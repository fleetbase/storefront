<?php

namespace Fleetbase\Storefront\Push;

use Fleetbase\Models\PushApp;
use Fleetbase\Push\PushAppChannel;
use Fleetbase\Storefront\Push\Contracts\SendsStorefrontPush;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Sends Storefront notifications through core-api's PushAppChannel, with each store's and
 * network's own push app.
 *
 * Devices registered before their storefront had a push app (or registered from an app
 * version that didn't link them) aren't linked to one yet. For this send they're given the
 * push app of the storefront they registered from (`app_identifier`), else of the first of
 * the notification's storefronts that has one, so customers of apps already installed keep
 * getting pushes. The link isn't saved: the device is linked for good the next time the app
 * registers it.
 */
class StorefrontPushAppChannel extends PushAppChannel
{
    public function send($notifiable, Notification $notification): array
    {
        try {
            $this->linkUnlinkedDevices($notifiable, $notification);
        } catch (\Throwable $e) {
            static::log('error', 'Unable to work out the push app of unlinked devices.', ['notification' => get_class($notification), 'error' => $e->getMessage()]);
        }

        return parent::send($notifiable, $notification);
    }

    protected function linkUnlinkedDevices($notifiable, Notification $notification): void
    {
        if (!$notification instanceof SendsStorefrontPush || !is_object($notifiable) || !method_exists($notifiable, 'devices')) {
            return;
        }

        $unlinked = collect($notifiable->devices)->filter(fn ($device) => is_object($device) && empty($device->push_app_uuid) && !empty($device->token));
        if ($unlinked->isEmpty()) {
            return;
        }

        $fallback = false;
        foreach ($unlinked as $device) {
            $app = $device->app_identifier ? StorefrontPushApps::forOwner($device->app_identifier, true) : null;
            if (!$app) {
                if ($fallback === false) {
                    $fallback = collect($notification->pushStorefronts())->map(fn ($storefront) => StorefrontPushApps::forOwner($storefront, true))->first(fn (?PushApp $app) => $app !== null);
                }
                $app = $fallback;
            }
            if ($app) {
                $device->push_app_uuid = $app->uuid;
            }
        }
    }

    protected static function log(string $level, string $message, array $context = []): void
    {
        try {
            Log::{$level}('[Storefront Push] ' . $message, $context);
        } catch (\Throwable $e) {
            // Logging never stops delivery.
        }
    }
}
