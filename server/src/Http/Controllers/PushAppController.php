<?php

namespace Fleetbase\Storefront\Http\Controllers;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\PushApp;
use Fleetbase\Models\UserDevice;
use Fleetbase\Push\PushAppChannel;
use Fleetbase\Push\PushConfigurationException;
use Fleetbase\Push\PushMessage;
use Fleetbase\Push\PushOutcome;
use Fleetbase\Push\PushProviders;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Push\StorefrontPushApps;
use Illuminate\Http\Request;
use Illuminate\Notifications\Notification;

/**
 * Settings → Notifications: the push app of a store or network, with its Apple (`apn`) and
 * Firebase (`fcm`) settings, checked by core-api's push providers, and a separate Sign in
 * with Apple key (`apple_sign_in`). Secrets are written here but never read back.
 *
 * Permissions are those of the notification channels this replaces
 * (`storefront … notification-channel`), so existing roles keep their access.
 */
class PushAppController extends Controller
{
    public function getService(): string
    {
        return 'storefront';
    }

    public function getResourceSingularName(): string
    {
        return 'notification_channel';
    }

    public function getPermissionResourceName(): string
    {
        return 'notification-channel';
    }

    public function show(string $owner)
    {
        $storefront = $this->owner($owner);
        if (!$storefront) {
            return response()->error('Store or network not found.', 404);
        }

        $app = StorefrontPushApps::forOwner($storefront);

        return response()->json(['push_app' => $app ? $this->present($app) : null]);
    }

    /**
     * Creates the owner's push app, or updates it.
     */
    public function save(string $owner, Request $request)
    {
        $storefront = $this->owner($owner);
        if (!$storefront) {
            return response()->error('Store or network not found.', 404);
        }

        $app        = StorefrontPushApps::forOwner($storefront);
        $attributes = $this->validated($request, $app, $storefront);
        if (is_string($attributes)) {
            return response()->error($attributes, 422);
        }

        if ($app) {
            $app->update($attributes);
        } else {
            $app = PushApp::create(array_replace($attributes, [
                'company_uuid'    => $storefront->company_uuid,
                'created_by_uuid' => session('user'),
                'owner_uuid'      => $storefront->uuid,
                'owner_type'      => StorefrontPushApps::ownerType($storefront),
            ]));
        }

        return response()->json(['push_app' => $this->present($app->refresh())]);
    }

    public function destroy(string $owner)
    {
        $storefront = $this->owner($owner);
        $app        = $storefront ? StorefrontPushApps::forOwner($storefront) : null;
        if (!$app) {
            return response()->error('Push app not found.', 404);
        }

        $app->delete();

        return response()->json(['status' => 'ok']);
    }

    /**
     * Sends a test push to one device token with the push app's settings.
     */
    public function sendTest(string $owner, Request $request)
    {
        $storefront = $this->owner($owner);
        $app        = $storefront ? StorefrontPushApps::forOwner($storefront) : null;
        if (!$app) {
            return response()->error('Save the push app before sending a test push.', 404);
        }

        $token  = trim((string) $request->input('token'));
        $device = new UserDevice();
        $device->forceFill([
            'token'         => $token,
            'platform'      => UserDevice::normalizePlatform($request->input('platform')) ?? 'ios',
            'push_provider' => PushProviders::normalize($request->input('push_provider')),
            'push_app_uuid' => $app->uuid,
            'status'        => 'active',
            'environment'   => $request->input('environment') ?: null,
        ]);
        $provider = $token === '' ? null : app(PushProviders::class)->forDevice($device);
        if (!$provider) {
            return response()->error('Paste a device token from a phone with the app installed.', 422);
        }
        if ($app->status !== 'active') {
            return response()->error('Sending is turned off for this push app. Turn on “Send push notifications” and save, then try again.', 422);
        }
        if (!$app->credentials($provider)) {
            return response()->error($provider === 'apn'
                ? 'This is an Apple device token, and the push app has no Apple settings yet. Add them, or choose “Firebase token” if your iOS app gets its tokens from Firebase.'
                : 'This is a Firebase token, and the push app has no Firebase service account key yet. Add one first.', 422);
        }

        $target = new class([$device]) {
            public function __construct(public array $devices)
            {
            }

            public function routeNotificationForPushApp(): array
            {
                return $this->devices;
            }
        };

        $notification = new class(PushMessage::create((string) ($request->input('title') ?: 'Test push'), (string) ($request->input('message') ?: 'Push notifications from ' . $storefront->name . ' work.'), ['type' => 'test'])) extends Notification implements \Fleetbase\Push\Contracts\SendsToPushApps {
            public function __construct(public PushMessage $message)
            {
            }

            public function toPushApp($notifiable): ?PushMessage
            {
                return $this->message;
            }
        };

        $outcome = app(PushAppChannel::class)->send($target, $notification)[$token] ?? PushOutcome::error('The push could not be sent. Check the push app’s settings.');

        return response()->json(['status' => $outcome->status, 'reason' => $outcome->reason, 'sent' => $outcome->is(PushOutcome::SENT)]);
    }

    /**
     * The attributes to save, or a message saying what to fix.
     */
    protected function validated(Request $request, ?PushApp $app, Store|Network $storefront): array|string
    {
        $name = trim((string) $request->input('name', $app?->name ?? $storefront->name));
        if ($name === '') {
            return 'Give the push app a name, for example your app’s name on the App Store.';
        }

        $attributes = ['name' => $name];
        if ($request->has('status')) {
            if (!in_array($request->input('status'), ['active', 'inactive'], true)) {
                return 'Choose whether to send push notifications through this app.';
            }
            $attributes['status'] = $request->input('status');
        }

        $providers = app(PushProviders::class);
        $configs   = $app ? (array) $app->providers : [];
        foreach (['apn', 'fcm'] as $key) {
            if (!$request->has($key)) {
                continue;
            }
            try {
                $config = $providers->get($key)->configure((array) $request->input($key), $app?->provider($key));
            } catch (PushConfigurationException $e) {
                return $e->getMessage();
            }
            if ($config === null) {
                unset($configs[$key]);
            } else {
                $configs[$key] = $key === 'fcm' ? StorefrontPushApps::withDefaultAndroidChannel($config) : $config;
            }
        }

        if ($request->has(StorefrontPushApps::APPLE_SIGN_IN)) {
            $config = static::appleSignIn((array) $request->input(StorefrontPushApps::APPLE_SIGN_IN), $app?->provider(StorefrontPushApps::APPLE_SIGN_IN), $configs['apn'] ?? null);
            if (is_string($config)) {
                return $config;
            }
            if ($config === null) {
                unset($configs[StorefrontPushApps::APPLE_SIGN_IN]);
            } else {
                $configs[StorefrontPushApps::APPLE_SIGN_IN] = $config;
            }
        }

        $attributes['providers'] = $configs;

        return $attributes;
    }

    /**
     * The Sign in with Apple key to save, null to remove it, or a message saying what to fix.
     * The team ID and bundle ID may be left empty to use the Apple push settings' ones.
     */
    public static function appleSignIn(array $input, ?array $saved, ?array $apn): array|string|null
    {
        $config = [];
        foreach (['key_id', 'team_id', 'app_bundle_id', 'private_key_content'] as $field) {
            $value = $input[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $config[$field] = trim($value);
            }
        }
        if (empty($config)) {
            return null;
        }

        if (empty($config['key_id'])) {
            return 'Enter the Key ID of your Sign in with Apple key, or clear the Sign in with Apple settings to use your Apple push key.';
        }
        if (!preg_match('/^[A-Z0-9]{10}$/i', $config['key_id'])) {
            return 'The Sign in with Apple Key ID should be the 10 letters and numbers shown next to the key in your Apple Developer account, like XYZ987WXYZ.';
        }
        if (!empty($config['team_id']) && !preg_match('/^[A-Z0-9]{10}$/i', $config['team_id'])) {
            return 'The Sign in with Apple Team ID should be 10 letters and numbers, like TEAM123456.';
        }
        if (empty($config['team_id']) && empty($apn['team_id'])) {
            return 'Enter your Apple Team ID for Sign in with Apple. It’s under Membership details in your Apple Developer account.';
        }
        if (!empty($config['app_bundle_id']) && !preg_match('/^[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+$/', $config['app_bundle_id'])) {
            return 'The Sign in with Apple bundle ID should look like com.acme.shop: the Bundle Identifier of your iOS app in Xcode.';
        }
        if (empty($config['app_bundle_id']) && empty($apn['app_bundle_id'])) {
            return 'Enter the bundle ID of your iOS app for Sign in with Apple, like com.acme.shop.';
        }
        if (empty($config['private_key_content'])) {
            if (empty($saved['private_key_content'])) {
                return 'Add the .p8 file of your Sign in with Apple key.';
            }
            $config['private_key_content'] = $saved['private_key_content'];
        } elseif (!str_contains($config['private_key_content'], 'PRIVATE KEY')) {
            return 'The Sign in with Apple key should be the whole contents of the .p8 file, starting with "-----BEGIN PRIVATE KEY-----".';
        }

        return $config;
    }

    protected function present(PushApp $app): array
    {
        $signIn = $app->provider(StorefrontPushApps::APPLE_SIGN_IN);

        return [
            'id'            => $app->public_id,
            'name'          => $app->name,
            'key'           => $app->key,
            'status'        => $app->status,
            'providers'     => (object) $app->summaries,
            'apple_sign_in' => $signIn ? [
                'key_id'        => $signIn['key_id'] ?? null,
                'team_id'       => $signIn['team_id'] ?? null,
                'app_bundle_id' => $signIn['app_bundle_id'] ?? null,
                'has_key'       => !empty($signIn['private_key_content']),
            ] : null,
            'devices'                => $app->devices()->count(),
            'migrated_from_channels' => !empty(data_get($app->meta, StorefrontPushApps::CHANNEL_KEYS)),
            'created_at'             => $app->created_at,
        ];
    }

    /**
     * The company's store or network with this uuid or public ID.
     */
    protected function owner(string $id): Store|Network|null
    {
        $company = session('company');
        if (!$company) {
            return null;
        }

        foreach ([Store::class, Network::class] as $model) {
            $owner = $model::where('company_uuid', $company)->where(fn ($query) => $query->where('uuid', $id)->orWhere('public_id', $id))->first();
            if ($owner) {
                return $owner;
            }
        }

        return null;
    }
}
