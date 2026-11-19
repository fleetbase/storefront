<?php

namespace Fleetbase\Storefront\Push;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Models\PushApp;
use Fleetbase\Models\UserDevice;
use Fleetbase\Push\PushProviders;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Support\Storefront;
use Illuminate\Http\Request;

/**
 * The push app of each store and network: one of core-api's push apps, owned by the store or
 * network, holding its app's Apple (APNs) and Firebase (FCM) settings, and optionally a Sign
 * in with Apple key under `apple_sign_in`.
 *
 * A storefront app registers devices with its storefront key, and the device is linked to that
 * store's or network's push app. An app can instead name a push app with `push_app`: either a
 * push app key, or the `noty_channel_…` key of a notification channel the push app was made
 * from (recorded on its meta as `storefront_channel_keys`).
 */
class StorefrontPushApps
{
    /** The push app setting that holds a separate Sign in with Apple key. Not a push provider. */
    public const APPLE_SIGN_IN = 'apple_sign_in';

    /** The meta key listing the notification channel keys a push app was made from. */
    public const CHANNEL_KEYS = 'storefront_channel_keys';

    public const LEGACY_KEY_PREFIX = 'noty_channel_';

    /**
     * The push app a store or network manages in Storefront: the first one it owns.
     */
    public static function forOwner(Store|Network|string|null $owner, bool $activeOnly = false): ?PushApp
    {
        $uuid = is_string($owner) ? $owner : $owner?->uuid;
        if (!$uuid) {
            return null;
        }

        return PushApp::where('owner_uuid', $uuid)
            ->when($activeOnly, fn ($query) => $query->where('status', 'active'))
            ->orderBy('id')
            ->first();
    }

    /**
     * The model class a push app owned by this storefront records as `owner_type`.
     */
    public static function ownerType(Store|Network $owner): string
    {
        return $owner instanceof Network ? Network::class : Store::class;
    }

    /**
     * The active push app named by a key in the company: a push app key, or the key of a
     * notification channel a push app was made from.
     */
    public static function findByKey(?string $key, ?string $companyUuid): ?PushApp
    {
        $key = trim((string) $key);
        if (str_starts_with($key, PushApp::KEY_PREFIX)) {
            return PushApp::findByKey($key, $companyUuid);
        }
        if (!$companyUuid || !str_starts_with($key, self::LEGACY_KEY_PREFIX)) {
            return null;
        }

        return PushApp::where('company_uuid', $companyUuid)
            ->where('status', 'active')
            ->orderBy('id')
            ->get()
            ->first(fn (PushApp $app) => in_array($key, (array) data_get($app->meta, self::CHANNEL_KEYS, []), true));
    }

    /**
     * The push app a device registering from a storefront joins: the one the request names
     * with `push_app` when it's in the storefront's company, else the storefront's own.
     */
    public static function forRegistration(Request $request, Store|Network|null $storefront): ?PushApp
    {
        if (!$storefront) {
            return null;
        }

        return static::findByKey($request->input('push_app'), $storefront->company_uuid) ?? static::forOwner($storefront, true);
    }

    /**
     * Link a device to a push app, with the service that issued its token (`push_provider`)
     * and its APNs build (`environment`) when the app sends them.
     */
    public static function link(UserDevice $device, PushApp $app, Request $request): UserDevice
    {
        $attributes = $app->deviceAttributes();
        $provider   = PushProviders::normalize($request->input('push_provider'));
        if ($provider && app(PushProviders::class)->get($provider)) {
            $attributes['push_provider'] = $provider;
        }
        $device->forceFill($attributes);

        return $device;
    }

    /**
     * Firebase settings with the install's default Android notification channel
     * (`storefront.push.android_channel_id`, STOREFRONT_PUSH_ANDROID_CHANNEL_ID) filled in when
     * they name none. Applied when a push app's Firebase settings are saved or migrated.
     */
    public static function withDefaultAndroidChannel(array $fcm): array
    {
        $default = trim((string) config('storefront.push.android_channel_id'));
        if ($default !== '' && empty($fcm['android_channel_id'])) {
            $fcm['android_channel_id'] = $default;
        }

        return $fcm;
    }

    /**
     * Storefronts an order's push notifications may be sent through, most preferred first.
     *
     * Network (marketplace) orders are placed from the network's app, so the network comes
     * before the individual store.
     *
     * @return array<Store|Network>
     */
    public static function storefrontsForOrder(Order $order): array
    {
        $storefronts = [];

        foreach (['storefront_network_id', 'storefront_id'] as $metaKey) {
            $id = $order->getMeta($metaKey);
            if (!$id) {
                continue;
            }

            $storefront = Storefront::findAbout($id);
            if ($storefront && !collect($storefronts)->contains(fn ($existing) => $existing->uuid === $storefront->uuid)) {
                $storefronts[] = $storefront;
            }
        }

        return $storefronts;
    }
}
