<?php

require_once __DIR__ . '/../../Support/PushAppTestHelpers.php';

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Models\PushApp;
use Fleetbase\Models\UserDevice;
use Fleetbase\Push\PushMessage;
use Fleetbase\Push\PushProviders;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Push\Contracts\SendsStorefrontPush;
use Fleetbase\Storefront\Push\NotificationChannelMigrator;
use Fleetbase\Storefront\Push\StorefrontPushAppChannel;
use Fleetbase\Storefront\Push\StorefrontPushApps;
use Illuminate\Http\Request;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Facade;

/**
 * Storefront on core-api's push apps: each store's and network's push app, the channel that
 * sends through it (and finds the app of devices that aren't linked yet), and the migration
 * of the old notification channels into push apps.
 */
function sfPushAppsStorefronts(): void
{
    $schema = sfPushAppsDb()->getSchemaBuilder();
    foreach (['stores', 'networks', 'notification_channels'] as $table) {
        $schema->dropIfExists($table);
    }
    foreach (['stores', 'networks'] as $name) {
        $schema->create($name, function ($table) {
            $table->increments('id');
            foreach (['uuid', 'public_id', 'company_uuid', 'backdrop_uuid', 'logo_uuid', 'order_config_uuid', 'key', 'name', 'description', 'translations', 'website', 'facebook', 'instagram', 'twitter', 'email', 'phone', 'tags', 'currency', 'timezone', 'pod_method', 'options'] as $column) {
                $table->text($column)->nullable();
            }
            $table->timestamps();
            $table->softDeletes();
        });
    }
    $schema->create('notification_channels', function ($table) {
        $table->increments('id');
        foreach (['uuid', 'company_uuid', 'created_by_uuid', 'owner_uuid', 'owner_type', 'name', 'scheme', 'app_key', 'config', 'options'] as $column) {
            $table->text($column)->nullable();
        }
        $table->timestamps();
        $table->softDeletes();
    });

    $db = sfPushAppsDb();
    $db->table('stores')->insert(['uuid' => 'store_uuid', 'public_id' => 'store_public', 'company_uuid' => 'company_uuid', 'key' => 'store_key', 'name' => 'Corner Store']);
    $db->table('stores')->insert(['uuid' => 'bare_store_uuid', 'public_id' => 'store_bare', 'company_uuid' => 'company_uuid', 'key' => 'store_bare_key', 'name' => '']);
    $db->table('networks')->insert(['uuid' => 'network_uuid', 'public_id' => 'network_public', 'company_uuid' => 'company_uuid', 'key' => 'network_key', 'name' => 'Corner Network']);
}

function sfPushAppsChannelRow(string $owner, string $scheme, string $appKey, array $config, string $createdAt = '2026-10-01 00:00:00', array $attributes = []): void
{
    sfPushAppsDb()->table('notification_channels')->insert(array_replace([
        'uuid'            => 'channel_' . $appKey,
        'company_uuid'    => 'company_uuid',
        'created_by_uuid' => 'user_uuid',
        'owner_uuid'      => $owner,
        'owner_type'      => 'Fleetbase\\Storefront\\Models\\Store',
        'name'            => strtoupper($scheme) . ' channel',
        'scheme'          => $scheme,
        'app_key'         => $appKey,
        'config'          => json_encode($config),
        'created_at'      => $createdAt,
        'updated_at'      => $createdAt,
    ], $attributes));
}

function sfPushAppsDevice(string $token, array $attributes = []): UserDevice
{
    $device = new UserDevice();
    $device->forceFill(array_replace(['uuid' => 'device_' . $token, 'public_id' => 'device_' . $token, 'token' => $token, 'platform' => 'ios', 'status' => 'active', 'user_uuid' => 'user_uuid'], $attributes));

    return $device;
}

function sfPushAppsNotification(array|Closure $storefronts): Notification
{
    return new class($storefronts) extends Notification implements SendsStorefrontPush {
        public function __construct(public array|Closure $storefronts)
        {
        }

        public function toPushApp($notifiable): ?PushMessage
        {
            return PushMessage::create('Order ready', 'Collect it now');
        }

        public function pushStorefronts(): array
        {
            return is_array($this->storefronts) ? $this->storefronts : ($this->storefronts)();
        }
    };
}

function sfPushAppsNotifiable(array $devices): object
{
    return new class($devices) {
        public function __construct(public array $devices)
        {
        }

        public function devices(): array
        {
            return $this->devices;
        }
    };
}

function sfPushAppsLogger(bool $throws = false): object
{
    $log = new class($throws) extends Psr\Log\AbstractLogger {
        public array $records = [];

        public function __construct(public bool $throws)
        {
        }

        public function log($level, $message, array $context = []): void
        {
            if ($this->throws) {
                throw new RuntimeException('log down');
            }
            $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
        }

        public function channel(?string $name = null): self
        {
            return $this;
        }
    };
    app()->instance('log', $log);
    Facade::clearResolvedInstance('log');

    return $log;
}

test('a storefront manages the first push app it owns, and only active ones are used to send', function () {
    sfPushAppsSetUp();
    sfPushAppsStorefronts();
    $first = sfPushAppsCreate(['uuid' => 'first_app', 'status' => 'inactive']);
    sfPushAppsCreate(['uuid' => 'second_app']);
    $store   = Store::where('uuid', 'store_uuid')->first();
    $network = Network::where('uuid', 'network_uuid')->first();

    expect(StorefrontPushApps::forOwner($store)->uuid)->toBe('first_app')
        ->and(StorefrontPushApps::forOwner('store_uuid', true)->uuid)->toBe('second_app')
        ->and(StorefrontPushApps::forOwner($network))->toBeNull()
        ->and(StorefrontPushApps::forOwner(null))->toBeNull()
        ->and(StorefrontPushApps::forOwner(''))->toBeNull()
        ->and(StorefrontPushApps::ownerType($store))->toBe(Store::class)
        ->and(StorefrontPushApps::ownerType($network))->toBe(Network::class)
        ->and($first->exists)->toBeTrue();
});

test('a push app is found by its key or by a notification channel key it was made from, in the company', function () {
    sfPushAppsSetUp();
    sfPushAppsCreate(['uuid' => 'keyed_app', 'key' => 'push_app_keyed']);
    sfPushAppsCreate(['uuid' => 'migrated_app', 'key' => 'push_app_migrated', 'meta' => [StorefrontPushApps::CHANNEL_KEYS => ['noty_channel_apn', 'noty_channel_fcm']]]);
    sfPushAppsCreate(['uuid' => 'off_app', 'key' => 'push_app_off', 'status' => 'inactive', 'meta' => [StorefrontPushApps::CHANNEL_KEYS => ['noty_channel_off']]]);

    expect(StorefrontPushApps::findByKey(' push_app_keyed ', 'company_uuid')->uuid)->toBe('keyed_app')
        ->and(StorefrontPushApps::findByKey('push_app_keyed', 'other_company'))->toBeNull()
        ->and(StorefrontPushApps::findByKey('noty_channel_fcm', 'company_uuid')->uuid)->toBe('migrated_app')
        ->and(StorefrontPushApps::findByKey('noty_channel_fcm', null))->toBeNull()
        ->and(StorefrontPushApps::findByKey('noty_channel_off', 'company_uuid'))->toBeNull()
        ->and(StorefrontPushApps::findByKey('noty_channel_unknown', 'company_uuid'))->toBeNull()
        ->and(StorefrontPushApps::findByKey('store_key', 'company_uuid'))->toBeNull()
        ->and(StorefrontPushApps::findByKey(null, 'company_uuid'))->toBeNull();
});

test('a registering device joins the push app it names, else its storefront\'s, and records who issued its token', function () {
    sfPushAppsSetUp();
    sfPushAppsStorefronts();
    sfPushAppsCreate(['uuid' => 'store_app']);
    sfPushAppsCreate(['uuid' => 'named_app', 'key' => 'push_app_named', 'owner_uuid' => 'elsewhere']);
    $store = Store::where('uuid', 'store_uuid')->first();

    expect(StorefrontPushApps::forRegistration(Request::create('/', 'POST'), null))->toBeNull()
        ->and(StorefrontPushApps::forRegistration(Request::create('/', 'POST'), $store)->uuid)->toBe('store_app')
        ->and(StorefrontPushApps::forRegistration(Request::create('/', 'POST', ['push_app' => 'push_app_named']), $store)->uuid)->toBe('named_app');

    $app    = PushApp::where('uuid', 'store_app')->first();
    $device = StorefrontPushApps::link(sfPushAppsDevice('abc'), $app, Request::create('/', 'POST', ['push_provider' => 'APNs']));
    $other  = StorefrontPushApps::link(sfPushAppsDevice('def'), $app, Request::create('/', 'POST', ['push_provider' => 'carrier-pigeon']));

    expect($device->only(['push_app_uuid', 'company_uuid', 'app_identifier', 'push_provider']))->toBe(['push_app_uuid' => 'store_app', 'company_uuid' => 'company_uuid', 'app_identifier' => 'store_app', 'push_provider' => 'apn'])
        ->and($other->push_provider)->toBeNull();
});

test('an order is pushed through its network first, then its store, each once', function () {
    sfPushAppsSetUp();
    sfPushAppsStorefronts();
    $order = new Order();
    $order->setRawAttributes(['meta' => json_encode(['storefront_network_id' => 'network_public', 'storefront_id' => 'store_public'])]);
    $storeOnly = new Order();
    $storeOnly->setRawAttributes(['meta' => json_encode(['storefront_id' => 'store_public', 'storefront_network_id' => 'store_public'])]);
    $unknown = new Order();
    $unknown->setRawAttributes(['meta' => json_encode(['storefront_id' => 'store_missing'])]);

    expect(collect(StorefrontPushApps::storefrontsForOrder($order))->pluck('uuid')->all())->toBe(['network_uuid', 'store_uuid'])
        ->and(collect(StorefrontPushApps::storefrontsForOrder($storeOnly))->pluck('uuid')->all())->toBe(['store_uuid'])
        ->and(StorefrontPushApps::storefrontsForOrder($unknown))->toBe([]);
});

test('the channel sends unlinked devices through their storefront\'s app, else the notification\'s first storefront with one', function () {
    $fakes = sfPushAppsSetUp();
    sfPushAppsStorefronts();
    sfPushAppsCreate(['uuid' => 'store_app', 'public_id' => 'store_app_public'], ['apn' => sfPushAppsApn()]);
    sfPushAppsCreate(['uuid' => 'network_app', 'public_id' => 'network_app_public', 'owner_uuid' => 'network_uuid', 'owner_type' => Network::class], ['apn' => sfPushAppsApn(), 'fcm' => sfPushAppsFcm()]);
    sfPushAppsCreate(['uuid' => 'linked_app', 'public_id' => 'linked_app_public', 'owner_uuid' => 'other_owner'], ['apn' => sfPushAppsApn()]);
    $store   = Store::where('uuid', 'store_uuid')->first();
    $network = Network::where('uuid', 'network_uuid')->first();

    $devices = [
        // Registered from the store before it had a push app.
        sfPushAppsDevice('from-store', ['app_identifier' => 'store_uuid']),
        // Registered without saying from where: the order's first storefront with an app.
        sfPushAppsDevice('fcm:from-old-app', ['platform' => 'android']),
        sfPushAppsDevice('from-elsewhere', ['app_identifier' => 'io.example.other']),
        // Already linked: left as it is.
        sfPushAppsDevice('linked', ['push_app_uuid' => 'linked_app']),
        // No token: nothing to send to.
        sfPushAppsDevice('', ['app_identifier' => 'store_uuid']),
        'not a device',
    ];
    $outcomes = (new StorefrontPushAppChannel(app(PushProviders::class)))->send(sfPushAppsNotifiable($devices), sfPushAppsNotification([$network, $store]));

    expect(array_keys($outcomes))->toEqualCanonicalizing(['from-store', 'fcm:from-old-app', 'from-elsewhere', 'linked'])
        ->and(collect($fakes['apn']->calls)->mapWithKeys(fn ($call) => [$call[0] => $call[2]])->all())->toEqualCanonicalizing([
            'store_app_public'   => ['from-store'],
            'network_app_public' => ['from-elsewhere'],
            'linked_app_public'  => ['linked'],
        ])
        ->and($fakes['fcm']->calls[0][0])->toBe('network_app_public')
        ->and($devices[0]->push_app_uuid)->toBe('store_app')
        ->and($devices[4]->push_app_uuid)->toBeNull()
        // The link is only for this send.
        ->and($devices[0]->isDirty('push_app_uuid'))->toBeTrue();
});

test('the channel leaves devices unlinked when no storefront has an app, and ignores what it can\'t link', function () {
    $fakes = sfPushAppsSetUp();
    sfPushAppsStorefronts();
    $store   = Store::where('uuid', 'store_uuid')->first();
    $channel = new StorefrontPushAppChannel(app(PushProviders::class));
    $device  = sfPushAppsDevice('lonely');

    $other = new class extends Notification implements Fleetbase\Push\Contracts\SendsToPushApps {
        public function toPushApp($notifiable): ?PushMessage
        {
            return PushMessage::create('Hi', 'There');
        }
    };

    expect($channel->send(sfPushAppsNotifiable([$device]), sfPushAppsNotification([$store])))->toBe([])
        ->and($device->push_app_uuid)->toBeNull()
        ->and($channel->send(sfPushAppsNotifiable([sfPushAppsDevice('plain')]), $other))->toBe([])
        ->and($channel->send(new stdClass(), sfPushAppsNotification([$store])))->toBe([])
        ->and($channel->send(sfPushAppsNotifiable([]), sfPushAppsNotification([$store])))->toBe([])
        ->and($fakes['apn']->calls)->toBe([]);
});

test('a failure working out unlinked devices is logged and linked devices still get the push', function () {
    $fakes = sfPushAppsSetUp();
    sfPushAppsCreate(['uuid' => 'linked_app', 'public_id' => 'linked_app_public'], ['apn' => sfPushAppsApn()]);
    $log          = sfPushAppsLogger();
    $notification = sfPushAppsNotification(fn () => throw new RuntimeException('storefronts unavailable'));
    $channel      = new StorefrontPushAppChannel(app(PushProviders::class));

    $outcomes = $channel->send(sfPushAppsNotifiable([sfPushAppsDevice('unlinked'), sfPushAppsDevice('linked', ['push_app_uuid' => 'linked_app'])]), $notification);

    expect(array_keys($outcomes))->toBe(['linked'])
        ->and($log->records[0]['message'])->toBe('[Storefront Push] Unable to work out the push app of unlinked devices.')
        ->and($log->records[0]['context']['error'])->toBe('storefronts unavailable');

    // A logger that throws doesn't stop the push either.
    sfPushAppsLogger(true);
    expect(array_keys($channel->send(sfPushAppsNotifiable([sfPushAppsDevice('unlinked'), sfPushAppsDevice('linked', ['push_app_uuid' => 'linked_app'])]), $notification)))->toBe(['linked'])
        ->and(count($fakes['apn']->calls))->toBe(2);

    app()->forgetInstance('log');
    Facade::clearResolvedInstance('log');
});

test('each storefront\'s channels become one push app, extras their own, and its devices are linked', function () {
    sfPushAppsSetUp();
    sfPushAppsStorefronts();
    $serviceAccount = ['project_id' => 'corner-store', 'client_email' => 'push@corner-store.iam.gserviceaccount.com', 'private_key' => 'secret'];
    // The store: two Apple channels (the oldest is its app's), Firebase, and Sign in with Apple.
    sfPushAppsChannelRow('store_uuid', 'apn', 'noty_channel_apn1', ['key_id' => ' KEY1234567 ', 'team_id' => 'TEAM123456', 'app_bundle_id' => 'com.cornerstore.app', 'private_key_content' => '-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----', 'environment' => 'sandbox', 'production' => false], '2026-10-01 00:00:00');
    sfPushAppsChannelRow('store_uuid', 'apn', 'noty_channel_apn2', ['key_id' => 'KEY7654321', 'team_id' => 'TEAM123456', 'app_bundle_id' => 'com.cornerstore.staging', 'private_key_content' => 'key', 'environment' => 'auto'], '2026-10-05 00:00:00', ['name' => '']);
    sfPushAppsChannelRow('store_uuid', 'fcm', 'noty_channel_fcm1', ['firebase_credentials_json' => json_encode($serviceAccount), 'android_channel_id' => 'orders'], '2026-10-02 00:00:00');
    sfPushAppsChannelRow('store_uuid', 'apple_sign_in', 'noty_channel_siwa', ['key_id' => 'SIWAKEY001', 'team_id' => 'TEAM123456', 'app_bundle_id' => 'com.cornerstore.app', 'private_key_content' => 'siwa-key'], '2026-10-03 00:00:00');
    // The network, with a Firebase channel holding a decoded service account.
    sfPushAppsChannelRow('network_uuid', 'fcm', 'noty_channel_net', ['credentials' => $serviceAccount], '2026-10-01 00:00:00', ['owner_type' => 'Fleetbase\\Storefront\\Models\\Network']);
    // Left alone: a deleted channel, another kind of channel, an unknown owner and no owner.
    sfPushAppsChannelRow('store_uuid', 'apn', 'noty_channel_deleted', ['key_id' => 'GONE'], '2026-10-01 00:00:00', ['deleted_at' => '2026-10-10 00:00:00']);
    sfPushAppsChannelRow('store_uuid', 'sms', 'noty_channel_sms', []);
    sfPushAppsChannelRow('missing_uuid', 'apn', 'noty_channel_orphan', ['key_id' => 'ORPHAN']);
    sfPushAppsChannelRow('', 'apn', 'noty_channel_nowhere', ['key_id' => 'NOWHERE'], '2026-10-01 00:00:00', ['owner_uuid' => null]);

    $db = sfPushAppsDb();
    $db->table('user_devices')->insert([
        ['uuid' => 'd1', 'push_app_uuid' => null, 'token' => 'store-device', 'platform' => 'ios', 'app_identifier' => 'store_uuid', 'status' => 'active'],
        ['uuid' => 'd2', 'push_app_uuid' => null, 'token' => 'network-device', 'platform' => 'android', 'app_identifier' => 'network_uuid', 'status' => 'active'],
        ['uuid' => 'd3', 'push_app_uuid' => null, 'token' => 'driver-device', 'platform' => 'ios', 'app_identifier' => 'io.fleetbase.navigator', 'status' => 'active'],
        ['uuid' => 'd4', 'push_app_uuid' => 'fleetops_app', 'token' => 'linked-device', 'platform' => 'ios', 'app_identifier' => 'store_uuid', 'status' => 'active'],
    ]);

    $result = sfPushAppsWithEvents(fn () => (new NotificationChannelMigrator())->migrate());
    $apps   = PushApp::orderBy('id')->get()->keyBy(fn (PushApp $app) => $app->name);

    expect($result)->toBe(['push_apps' => 3, 'devices' => 2])
        ->and($apps->keys()->all())->toBe(['Corner Store', 'Corner Store (notification channel)', 'Corner Network'])
        ->and($apps['Corner Store']->provider('apn'))->toBe(['key_id' => 'KEY1234567', 'team_id' => 'TEAM123456', 'app_bundle_id' => 'com.cornerstore.app', 'private_key_content' => "-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----", 'environment' => 'sandbox'])
        ->and($apps['Corner Store']->provider('fcm'))->toBe(['credentials' => json_encode($serviceAccount), 'android_channel_id' => 'orders'])
        ->and($apps['Corner Store']->provider('apple_sign_in'))->toBe(['key_id' => 'SIWAKEY001', 'team_id' => 'TEAM123456', 'app_bundle_id' => 'com.cornerstore.app', 'private_key_content' => 'siwa-key'])
        ->and($apps['Corner Store']->meta[StorefrontPushApps::CHANNEL_KEYS])->toBe(['noty_channel_apn1', 'noty_channel_fcm1', 'noty_channel_siwa'])
        ->and($apps['Corner Store']->only(['company_uuid', 'created_by_uuid', 'owner_uuid', 'owner_type', 'status']))->toBe(['company_uuid' => 'company_uuid', 'created_by_uuid' => 'user_uuid', 'owner_uuid' => 'store_uuid', 'owner_type' => Store::class, 'status' => 'active'])
        ->and($apps['Corner Store']->key)->toStartWith('push_app_')
        ->and($apps['Corner Store (notification channel)']->provider('apn'))->toBe(['key_id' => 'KEY7654321', 'team_id' => 'TEAM123456', 'app_bundle_id' => 'com.cornerstore.staging', 'private_key_content' => 'key'])
        ->and($apps['Corner Store (notification channel)']->meta[StorefrontPushApps::CHANNEL_KEYS])->toBe(['noty_channel_apn2'])
        ->and($apps['Corner Network']->owner_type)->toBe(Network::class)
        ->and($apps['Corner Network']->provider('fcm'))->toBe(['credentials' => json_encode($serviceAccount)])
        ->and($db->table('user_devices')->orderBy('id')->pluck('push_app_uuid')->all())->toBe([$apps['Corner Store']->uuid, $apps['Corner Network']->uuid, null, 'fleetops_app'])
        ->and($db->table('user_devices')->where('uuid', 'd1')->value('company_uuid'))->toBe('company_uuid')
        // Found again by the old keys apps may register with.
        ->and(StorefrontPushApps::findByKey('noty_channel_fcm1', 'company_uuid')->uuid)->toBe($apps['Corner Store']->uuid);

    // Running it again moves nothing twice, even for a push app since deleted.
    $apps['Corner Network']->delete();
    sfPushAppsChannelRow('store_uuid', 'fcm', 'noty_channel_fcm_new', ['credentials' => json_encode($serviceAccount)], '2026-10-20 00:00:00', ['name' => 'Later Firebase']);
    $again = sfPushAppsWithEvents(fn () => (new NotificationChannelMigrator())->migrate());

    expect($again)->toBe(['push_apps' => 1, 'devices' => 0])
        ->and(PushApp::withTrashed()->count())->toBe(4)
        // The store already has its push app, so a channel added later gets one of its own.
        ->and(PushApp::orderByDesc('id')->first()->name)->toBe('Corner Store (Later Firebase)');

    // Rolling back unlinks the devices and removes every push app made from channels.
    $removed = (new NotificationChannelMigrator())->rollback();

    expect($removed)->toBe(4)
        ->and(PushApp::withTrashed()->count())->toBe(0)
        ->and($db->table('user_devices')->orderBy('id')->pluck('push_app_uuid')->all())->toBe([null, null, null, 'fleetops_app'])
        ->and((new NotificationChannelMigrator())->rollback())->toBe(0);
});

test('a storefront without a name names its push app after its channel', function () {
    sfPushAppsSetUp();
    sfPushAppsStorefronts();
    sfPushAppsChannelRow('bare_store_uuid', 'fcm', 'noty_channel_bare', ['credentials' => 'not json'], '2026-10-01 00:00:00', ['name' => 'Bare channel']);
    sfPushAppsChannelRow('bare_store_uuid', 'apn', 'noty_channel_bare_apn', [], '2026-10-02 00:00:00', ['name' => '', 'config' => null]);

    sfPushAppsWithEvents(fn () => (new NotificationChannelMigrator())->migrate());
    $app = PushApp::first();

    // Neither channel had settings worth keeping, but the keys are kept so apps still find it.
    expect($app->name)->toBe('Bare channel')
        ->and($app->providers)->toBe([])
        ->and($app->meta[StorefrontPushApps::CHANNEL_KEYS])->toBe(['noty_channel_bare', 'noty_channel_bare_apn']);
});

test('channel settings are turned into push app settings', function () {
    $privateKey = tempnam(sys_get_temp_dir(), 'sf-push-key');
    file_put_contents($privateKey, "\n-----BEGIN PRIVATE KEY-----\nfrom-file\n-----END PRIVATE KEY-----\n");

    expect(NotificationChannelMigrator::providerConfig('fcm', ['credentials' => '{"project_id":"p"}', 'android_channel_id' => ' ']))->toBe(['credentials' => '{"project_id":"p"}'])
        ->and(NotificationChannelMigrator::providerConfig('fcm', ['credentials' => '{broken']))->toBeNull()
        ->and(NotificationChannelMigrator::providerConfig('fcm', []))->toBeNull()
        ->and(NotificationChannelMigrator::providerConfig('apn', ['key_id' => 'KEY', 'private_key_path' => $privateKey, 'environment' => 'production', 'production' => true]))->toBe(['key_id' => 'KEY', 'private_key_content' => "-----BEGIN PRIVATE KEY-----\nfrom-file\n-----END PRIVATE KEY-----", 'environment' => 'production'])
        ->and(NotificationChannelMigrator::providerConfig('apn', ['key_id' => ['not', 'text'], 'private_key_path' => $privateKey . '.missing']))->toBeNull()
        ->and(NotificationChannelMigrator::providerConfig('apple_sign_in', ['key_id' => 'SIWA', 'environment' => 'sandbox']))->toBe(['key_id' => 'SIWA']);

    unlink($privateKey);
});

test('the migration moves channels only once core-api has push apps', function () {
    sfPushAppsSetUp();
    sfPushAppsStorefronts();
    sfPushAppsChannelRow('store_uuid', 'fcm', 'noty_channel_m', ['credentials' => ['project_id' => 'p', 'client_email' => 'e', 'private_key' => 'k']]);
    $migration = require __DIR__ . '/../../../migrations/2026_11_19_100000_move_notification_channels_to_push_apps.php';
    app()->instance(NotificationChannelMigrator::class, new NotificationChannelMigrator());

    sfPushAppsWithEvents(fn () => $migration->up());
    expect(PushApp::query()->count())->toBe(1);

    $migration->down();
    expect(PushApp::withTrashed()->count())->toBe(0);

    sfPushAppsDb()->getSchemaBuilder()->drop('push_apps');
    $migration->up();
    $migration->down();

    expect(sfPushAppsDb()->getSchemaBuilder()->hasTable('push_apps'))->toBeFalse();
    app()->forgetInstance(NotificationChannelMigrator::class);
});

test('the install\'s default Android notification channel fills Firebase settings that name none', function () {
    config(['storefront.push.android_channel_id' => 'storefront_orders']);

    try {
        expect(StorefrontPushApps::withDefaultAndroidChannel(['credentials' => '{}']))->toBe(['credentials' => '{}', 'android_channel_id' => 'storefront_orders'])
            ->and(StorefrontPushApps::withDefaultAndroidChannel(['credentials' => '{}', 'android_channel_id' => 'promos']))->toBe(['credentials' => '{}', 'android_channel_id' => 'promos'])
            ->and(NotificationChannelMigrator::providerConfig('fcm', ['credentials' => '{"project_id":"p"}']))->toBe(['credentials' => '{"project_id":"p"}', 'android_channel_id' => 'storefront_orders'])
            ->and(NotificationChannelMigrator::providerConfig('fcm', []))->toBeNull();
    } finally {
        config(['storefront.push.android_channel_id' => null]);
    }

    expect(StorefrontPushApps::withDefaultAndroidChannel(['credentials' => '{}']))->toBe(['credentials' => '{}']);
});
