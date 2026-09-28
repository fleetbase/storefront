<?php

namespace Fleetbase\Storefront\Push;

use Fleetbase\Models\UserDevice;
use Fleetbase\Storefront\Models\NotificationChannel;
use Fleetbase\Storefront\Push\Contracts\SendsPushNotification;
use Fleetbase\Storefront\Push\Transports\ApnTransport;
use Fleetbase\Storefront\Push\Transports\FcmTransport;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Delivers storefront notifications to a notifiable's registered iOS and Android devices.
 *
 * For each platform the channel walks the candidate storefront channels (the app the device
 * registered from, then network, then store) and, for iOS, the candidate APNs environments. Tokens that the
 * provider reports as belonging to another app or environment are retried against the next
 * candidate, dead tokens are pruned, and every failure is logged. Errors never propagate, so
 * a push failure cannot block the notification's other channels.
 */
class StorefrontPushChannel
{
    public function __construct(
        protected PushCredentialResolver $resolver,
        protected FcmTransport $fcm,
        protected ApnTransport $apn,
    ) {
    }

    /**
     * @return array<string, PushOutcome> final outcome keyed by device token
     */
    public function send($notifiable, Notification $notification): array
    {
        if (!$notification instanceof SendsPushNotification) {
            return [];
        }

        try {
            $message = $notification->toPush($notifiable);
            $devices = static::devicesFor($notifiable);
            if (!$message || $devices->isEmpty()) {
                return [];
            }

            $storefronts = $notification->pushStorefronts();
        } catch (\Throwable $e) {
            static::log('error', 'Unable to prepare push notification.', ['notification' => get_class($notification), 'error' => $e->getMessage()]);

            return [];
        }

        $outcomes = [];
        foreach ($devices->groupBy(fn ($device) => static::normalizePlatform($device->platform)) as $platform => $platformDevices) {
            foreach ($platformDevices->groupBy(fn ($device) => data_get($device, 'app_identifier') ?? '') as $appIdentifier => $group) {
                $outcomes += match ($platform) {
                    'android' => $this->sendToAndroid($group, $message, $storefronts, $appIdentifier ?: null),
                    'ios'     => $this->sendToIos($group, $message, $storefronts, $appIdentifier ?: null),
                    default   => [],
                };
            }
        }

        return $outcomes;
    }

    protected function sendToAndroid(Collection $devices, PushMessage $message, array $storefronts, ?string $appIdentifier): array
    {
        $channels = $this->resolver->channels('fcm', $storefronts, $appIdentifier);

        return $this->deliver($devices, $channels, [null], function (NotificationChannel $channel, ?string $environment, array $tokens) use ($message) {
            return $this->fcm->send($channel, $message, $tokens);
        });
    }

    protected function sendToIos(Collection $devices, PushMessage $message, array $storefronts, ?string $appIdentifier): array
    {
        $channels = $this->resolver->channels('apn', $storefronts, $appIdentifier);
        $outcomes = [];

        // Devices that registered their APNs environment are sent to that environment only.
        foreach ($devices->groupBy(fn ($device) => data_get($device, 'environment') ?? '') as $deviceEnvironment => $group) {
            $outcomes += $this->deliver(
                $group,
                $channels,
                fn (NotificationChannel $channel)                                      => ApnClientFactory::environments($channel, $deviceEnvironment ?: null),
                fn (NotificationChannel $channel, ?string $environment, array $tokens) => $this->apn->send($channel, $environment, $message, $tokens)
            );
        }

        return $outcomes;
    }

    /**
     * @param array|callable $environments list of environments, or a resolver taking the channel
     */
    protected function deliver(Collection $devices, Collection $channels, array|callable $environments, callable $send): array
    {
        $devicesByToken = $devices->keyBy('token');
        $pending        = $devicesByToken->keys()->all();
        $outcomes       = [];

        if ($channels->isEmpty()) {
            static::log('warning', 'No push notification channel is configured for the storefront; skipping devices.', ['devices' => count($pending)]);

            return [];
        }

        foreach ($channels as $channel) {
            $channelEnvironments = is_callable($environments) ? $environments($channel) : $environments;
            $retryOtherApp       = [];

            foreach ($channelEnvironments as $environment) {
                if (empty($pending)) {
                    break;
                }

                try {
                    $results = $send($channel, $environment, $pending);
                } catch (\Throwable $e) {
                    static::log('error', 'Push notification provider request failed.', [
                        'channel'     => $channel->app_key,
                        'scheme'      => $channel->scheme,
                        'environment' => $environment,
                        'error'       => $e->getMessage(),
                    ]);
                    foreach ($pending as $token) {
                        $outcomes[$token] = PushOutcome::error($e->getMessage());
                    }
                    // Credentials for this channel are unusable; the next channel may still work.
                    $pending = array_values(array_unique(array_merge($pending, $retryOtherApp)));
                    continue 2;
                }

                $retryEnvironment = [];
                foreach ($pending as $token) {
                    $outcome          = $results[$token] ?? PushOutcome::error('No response from provider.');
                    $outcomes[$token] = $outcome;

                    if ($outcome->is(PushOutcome::WRONG_ENVIRONMENT)) {
                        $retryEnvironment[] = $token;
                    } elseif ($outcome->is(PushOutcome::WRONG_APP)) {
                        $retryOtherApp[] = $token;
                    }
                }

                $pending = $retryEnvironment;
            }

            // Still failing with BadDeviceToken in every environment: the token is invalid.
            foreach ($pending as $token) {
                $outcomes[$token] = PushOutcome::dead($outcomes[$token]->reason ?? 'BadDeviceToken');
            }

            $pending = $retryOtherApp;
            if (empty($pending)) {
                break;
            }
        }

        foreach ($outcomes as $token => $outcome) {
            $device = $devicesByToken->get($token);

            if ($outcome->is(PushOutcome::DEAD)) {
                static::pruneDevice($device, $outcome->reason);
            } elseif (!$outcome->is(PushOutcome::SENT)) {
                static::log('warning', 'Push notification was not delivered to device.', [
                    'device'   => $device?->public_id,
                    'platform' => $device?->platform,
                    'status'   => $outcome->status,
                    'reason'   => $outcome->reason,
                ]);
            }
        }

        return $outcomes;
    }

    /**
     * Active devices registered for the notifiable.
     */
    public static function devicesFor($notifiable): Collection
    {
        if (is_object($notifiable) && method_exists($notifiable, 'routeNotificationForStorefrontPush')) {
            $devices = collect($notifiable->routeNotificationForStorefrontPush());
        } elseif (is_object($notifiable) && (method_exists($notifiable, 'devices') || isset($notifiable->devices))) {
            $devices = collect($notifiable->devices);
        } else {
            return collect();
        }

        return $devices
            ->filter(fn ($device) => is_object($device) && !empty($device->token) && !in_array($device->status, ['invalid', 'inactive'], true))
            ->unique('token')
            ->values();
    }

    public static function normalizePlatform(?string $platform): ?string
    {
        $platform = strtolower(trim((string) $platform));

        return match ($platform) {
            'ios', 'iphone', 'ipad', 'ipados', 'apple', 'apn', 'apns' => 'ios',
            'android', 'fcm'                                          => 'android',
            default                                                   => null,
        };
    }

    protected static function pruneDevice($device, ?string $reason): void
    {
        if (!$device instanceof UserDevice) {
            return;
        }

        static::log('info', 'Pruning push device with a dead token.', ['device' => $device->public_id, 'platform' => $device->platform, 'reason' => $reason]);

        try {
            $device->status = 'invalid';
            $device->save();
            $device->delete();
        } catch (\Throwable $e) {
            static::log('error', 'Unable to prune push device.', ['device' => $device->public_id, 'error' => $e->getMessage()]);
        }
    }

    protected static function log(string $level, string $message, array $context = []): void
    {
        try {
            Log::{$level}('[Storefront Push] ' . $message, $context);
        } catch (\Throwable $e) {
            // Logging must never break delivery.
        }
    }
}
