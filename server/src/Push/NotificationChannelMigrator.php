<?php

namespace Fleetbase\Storefront\Push;

use Fleetbase\Models\PushApp;
use Fleetbase\Models\UserDevice;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Store;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns Storefront's notification channels (one row per platform: `apn`, `fcm`,
 * `apple_sign_in`) into push apps, one per store or network.
 *
 * The oldest channel of each kind goes into the owner's push app, which is the one the
 * console shows and its devices are linked to, matching the old channel's "first configured
 * channel wins". When an owner has more than one channel of a kind, the others go into extra
 * push apps owned by it, so no credentials are lost. Each push app records the keys of the
 * channels it was made from under `meta.storefront_channel_keys`, so an app that registers
 * with a `noty_channel_…` key still finds it, and so running this again skips them.
 *
 * Devices that registered from the store or network (`user_devices.app_identifier` is its
 * uuid) are linked to its push app. The channels are left in place.
 */
class NotificationChannelMigrator
{
    /**
     * @return array{push_apps: int, devices: int}
     */
    public function migrate(): array
    {
        $result = ['push_apps' => 0, 'devices' => 0];

        $migrated = PushApp::withTrashed()->get(['uuid', 'meta'])->flatMap(fn (PushApp $app) => (array) data_get($app->meta, StorefrontPushApps::CHANNEL_KEYS, []))->all();
        $channels = DB::connection(config('storefront.connection.db'))
            ->table('notification_channels')
            ->whereNull('deleted_at')
            ->whereIn('scheme', ['apn', 'fcm', StorefrontPushApps::APPLE_SIGN_IN])
            ->whereNotNull('owner_uuid')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->reject(fn ($channel) => in_array($channel->app_key, $migrated, true));

        foreach ($channels->groupBy('owner_uuid') as $ownerUuid => $ownerChannels) {
            $owner = Store::where('uuid', $ownerUuid)->first() ?? Network::where('uuid', $ownerUuid)->first();
            if (!$owner) {
                continue;
            }

            $apps = $this->appsFor($owner, $ownerChannels);
            $result['push_apps'] += count($apps);
            $result['devices'] += $this->linkDevices($owner, StorefrontPushApps::forOwner($owner) ?? $apps[0]);
        }

        return $result;
    }

    /**
     * Remove the push apps made from notification channels and unlink their devices. The
     * channels themselves were never changed.
     */
    public function rollback(): int
    {
        $apps = PushApp::withTrashed()->get()->filter(fn (PushApp $app) => !empty(data_get($app->meta, StorefrontPushApps::CHANNEL_KEYS)));
        if ($apps->isEmpty()) {
            return 0;
        }

        UserDevice::query()->whereIn('push_app_uuid', $apps->pluck('uuid')->all())->update(['push_app_uuid' => null]);
        $apps->each(fn (PushApp $app) => $app->forceDelete());

        return $apps->count();
    }

    /**
     * Push apps for one owner's channels: the first holds the oldest channel of each kind,
     * each further one the next channel of each kind.
     *
     * @return PushApp[]
     */
    protected function appsFor(Store|Network $owner, Collection $channels): array
    {
        $byScheme = $channels->groupBy('scheme')->map(fn (Collection $group) => $group->values());
        $count    = $byScheme->map(fn (Collection $group) => $group->count())->max();
        $hasApp   = StorefrontPushApps::forOwner($owner) !== null;
        $apps     = [];

        for ($index = 0; $index < $count; $index++) {
            $providers = [];
            $sources   = [];
            foreach ($byScheme as $scheme => $group) {
                $channel = $group->get($index);
                if (!$channel) {
                    continue;
                }
                $sources[] = $channel;
                $config    = static::providerConfig($scheme, static::decode($channel->config));
                if ($config) {
                    $providers[$scheme] = $config;
                }
            }

            $name   = trim((string) $owner->name) ?: trim((string) $sources[0]->name) ?: 'Storefront app';
            $apps[] = PushApp::create([
                'company_uuid'    => $owner->company_uuid ?? $sources[0]->company_uuid,
                'created_by_uuid' => $sources[0]->created_by_uuid ?? null,
                'name'            => $index === 0 && !$hasApp ? $name : $name . ' (' . (trim((string) $sources[0]->name) ?: 'notification channel') . ')',
                'providers'       => $providers,
                'status'          => 'active',
                'owner_uuid'      => $owner->uuid,
                'owner_type'      => StorefrontPushApps::ownerType($owner),
                'meta'            => [StorefrontPushApps::CHANNEL_KEYS => collect($sources)->pluck('app_key')->filter()->values()->all()],
            ]);
        }

        return $apps;
    }

    /**
     * Link the devices that registered from the owner, and aren't linked yet, to its push app.
     */
    protected function linkDevices(Store|Network $owner, PushApp $app): int
    {
        return UserDevice::query()
            ->where('app_identifier', $owner->uuid)
            ->whereNull('push_app_uuid')
            ->update($app->deviceAttributes());
    }

    /**
     * A channel's settings in the shape the push app keeps for the provider, or null when
     * there's nothing to keep.
     */
    public static function providerConfig(string $scheme, array $config): ?array
    {
        $text = fn (string $key) => is_scalar($config[$key] ?? null) ? trim((string) $config[$key]) : '';

        if ($scheme === 'fcm') {
            $credentials = $config['firebase_credentials_json'] ?? $config['credentials'] ?? null;
            if (is_string($credentials)) {
                $credentials = json_decode(trim($credentials), true);
            }

            return array_filter([
                'credentials'        => is_array($credentials) ? json_encode($credentials) : null,
                'android_channel_id' => $text('android_channel_id') ?: null,
            ]) ?: null;
        }

        $privateKey = str_replace('\n', "\n", $text('private_key_content'));
        if ($privateKey === '' && $text('private_key_path') !== '' && is_readable($text('private_key_path'))) {
            $privateKey = trim((string) file_get_contents($text('private_key_path')));
        }

        $settings = array_filter([
            'key_id'              => $text('key_id'),
            'team_id'             => $text('team_id'),
            'app_bundle_id'       => $text('app_bundle_id'),
            'private_key_content' => $privateKey,
        ]);
        if ($scheme === 'apn' && in_array($text('environment'), ['production', 'sandbox'], true)) {
            $settings['environment'] = $text('environment');
        }

        return $settings ?: null;
    }

    protected static function decode(mixed $config): array
    {
        if (is_string($config)) {
            $config = json_decode($config, true);
        }

        return is_array($config) ? $config : (array) $config;
    }
}
