<?php

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Models\UserDevice;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\NotificationChannel;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Push\ApnClientFactory;
use Fleetbase\Storefront\Push\Contracts\SendsPushNotification;
use Fleetbase\Storefront\Push\FirebaseMessagingFactory;
use Fleetbase\Storefront\Push\PushConfigurationException;
use Fleetbase\Storefront\Push\PushCredentialResolver;
use Fleetbase\Storefront\Push\PushMessage;
use Fleetbase\Storefront\Push\PushOutcome;
use Fleetbase\Storefront\Push\StorefrontPushChannel;
use Fleetbase\Storefront\Push\Transports\ApnTransport;
use Fleetbase\Storefront\Push\Transports\FcmTransport;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Notifications\Notification;
use Kreait\Firebase\Exception\Messaging\AuthenticationError;
use Kreait\Firebase\Exception\Messaging\InvalidMessage;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Exception\Messaging\ServerUnavailable;
use Kreait\Firebase\Messaging\MessageTarget;
use Kreait\Firebase\Messaging\SendReport;

function pushChannel(array $attributes, array $config = []): NotificationChannel
{
    $channel = new NotificationChannel();
    $channel->forceFill(array_merge(['uuid' => $attributes['app_key'] ?? 'channel', 'name' => 'Test channel'], $attributes));
    $channel->config = $config;

    return $channel;
}

function pushDevice(string $token, string $platform, array $attributes = []): UserDevice
{
    $device = new UserDevice();
    $device->forceFill(array_merge(['token' => $token, 'platform' => $platform, 'status' => 'active'], $attributes));

    return $device;
}

function pushServiceAccount(): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    openssl_pkey_export($key, $privateKey);

    return [
        'type'         => 'service_account',
        'project_id'   => 'storefront-tests',
        'client_email' => 'firebase-admin@storefront-tests.iam.gserviceaccount.com',
        'client_id'    => '1234567890',
        'private_key'  => $privateKey,
    ];
}

function pushNotification(array $storefronts, ?PushMessage $message = null): Notification
{
    return new class($storefronts, $message ?? PushMessage::create('Order ready', 'Collect it now', ['id' => 'order_public'])) extends Notification implements SendsPushNotification {
        public function __construct(public array $storefronts, public PushMessage $message)
        {
        }

        public function toPush($notifiable): ?PushMessage
        {
            return $this->message;
        }

        public function pushStorefronts(): array
        {
            return $this->storefronts;
        }
    };
}

function fakeResolver(array $channelsByScheme): PushCredentialResolver
{
    return new class($channelsByScheme) extends PushCredentialResolver {
        public array $calls = [];

        public function __construct(public array $channelsByScheme)
        {
        }

        public function channels(string $scheme, array $storefronts, ?string $appIdentifier = null): Illuminate\Support\Collection
        {
            $this->calls[] = [$scheme, $appIdentifier];

            return collect($this->channelsByScheme[$scheme] ?? []);
        }
    };
}

function fakeFcm(callable $handler): FcmTransport
{
    return new class($handler) extends FcmTransport {
        public array $calls = [];

        public function __construct(public $handler)
        {
        }

        public function send(NotificationChannel $channel, PushMessage $message, array $tokens): array
        {
            $this->calls[] = [$channel->app_key, $tokens];

            return ($this->handler)($channel, $tokens);
        }
    };
}

function fakeApn(callable $handler): ApnTransport
{
    return new class($handler) extends ApnTransport {
        public array $calls = [];

        public function __construct(public $handler)
        {
        }

        public function send(NotificationChannel $channel, string $environment, PushMessage $message, array $tokens): array
        {
            $this->calls[] = [$channel->app_key, $environment, $tokens];

            return ($this->handler)($channel, $environment, $tokens);
        }
    };
}

function createUserDevicesTable(): void
{
    $schema = Capsule::schema('mysql');
    $schema->dropIfExists('user_devices');
    $schema->create('user_devices', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('platform')->nullable();
        $table->text('token')->nullable();
        $table->string('status')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
}

test('push messages render high priority FCM payloads with string data', function () {
    $message = PushMessage::create('Order ready', 'Collect it now', [
        'id'       => 'order_public',
        'count'    => 2,
        'pickup'   => true,
        'meta'     => ['a' => 1],
        'optional' => null,
    ])->analyticsLabel('storefront_order');

    $payload = $message->toFcmArray(['android_channel_id' => 'orders']);

    expect($payload['notification'])->toBe(['title' => 'Order ready', 'body' => 'Collect it now'])
        ->and($payload['data'])->toBe(['id' => 'order_public', 'count' => '2', 'pickup' => 'true', 'meta' => '{"a":1}'])
        ->and($payload['android']['priority'])->toBe('high')
        ->and($payload['android']['notification'])->toMatchArray(['channel_id' => 'orders', 'sound' => 'default'])
        ->and($payload['apns']['headers'])->toBe(['apns-priority' => '10', 'apns-push-type' => 'alert'])
        ->and($payload['apns']['payload']['aps'])->toBe(['sound' => 'default', 'badge' => 1])
        ->and($payload['fcm_options'])->toBe(['analytics_label' => 'storefront_order'])
        ->and(Kreait\Firebase\Messaging\CloudMessage::fromArray($payload))->toBeInstanceOf(Kreait\Firebase\Messaging\CloudMessage::class);

    expect(PushMessage::create('Hi', 'There')->toFcmArray())->not->toHaveKey('data')
        ->and(PushMessage::create('Hi', 'There')->toFcmArray()['android']['notification'])->not->toHaveKey('channel_id');
});

test('push messages render APNs payloads with sound badge and custom data', function () {
    $payload = json_decode(json_encode(
        PushMessage::create('Order ready', 'Collect it now', ['id' => 'order_public', 'type' => 'order_ready', 'aps' => 'ignored'])->toApnPayload()
    ), true);

    expect($payload['aps'])->toMatchArray([
        'alert' => ['title' => 'Order ready', 'body' => 'Collect it now'],
        'sound' => 'default',
        'badge' => 1,
    ])->and($payload['id'])->toBe('order_public')
        ->and($payload['type'])->toBe('order_ready');
});

test('firebase service accounts are decoded from the channel and repaired', function () {
    $account                = pushServiceAccount();
    $escaped                = $account;
    $escaped['private_key'] = str_replace("\n", '\\n', $account['private_key']);
    unset($escaped['type']);

    $decoded = FirebaseMessagingFactory::serviceAccountFromChannel(
        pushChannel(['app_key' => 'fcm'], ['firebase_credentials_json' => json_encode($escaped)])
    );

    expect($decoded['private_key'])->toBe($account['private_key'])
        ->and($decoded['type'])->toBe('service_account')
        ->and($decoded['project_id'])->toBe('storefront-tests');

    expect(fn () => FirebaseMessagingFactory::serviceAccountFromChannel(pushChannel(['app_key' => 'fcm'], ['firebase_credentials_json' => 'not json'])))
        ->toThrow(PushConfigurationException::class, 'invalid Firebase service account JSON')
        ->and(fn () => FirebaseMessagingFactory::serviceAccountFromChannel(pushChannel(['app_key' => 'fcm'], [])))
        ->toThrow(PushConfigurationException::class, 'missing its Firebase service account JSON')
        ->and(fn () => FirebaseMessagingFactory::serviceAccountFromChannel(pushChannel(['app_key' => 'fcm'], ['firebase_credentials_json' => json_encode(['project_id' => 'x'])])))
        ->toThrow(PushConfigurationException::class, 'missing "client_email"');
});

test('firebase messaging clients are built from the channel without touching application config', function () {
    FirebaseMessagingFactory::flush();
    config(['firebase.projects.app' => null]);

    $channel  = pushChannel(['app_key' => 'fcm', 'uuid' => 'fcm_uuid'], ['firebase_credentials_json' => json_encode(pushServiceAccount())]);
    $factory  = new FirebaseMessagingFactory();
    $client   = $factory->make($channel);

    expect($client)->toBeInstanceOf(Kreait\Firebase\Contract\Messaging::class)
        ->and($factory->make($channel))->toBe($client)
        ->and(config('firebase.projects.fcm'))->toBeNull()
        ->and(config('firebase.projects.app'))->toBeNull();
});

test('APNs environments honor device, explicit and legacy channel settings', function () {
    $channel = fn (array $config) => pushChannel(['app_key' => 'apn'], $config);

    expect(ApnClientFactory::environments($channel(['environment' => 'sandbox'])))->toBe(['sandbox'])
        ->and(ApnClientFactory::environments($channel(['environment' => 'production'])))->toBe(['production'])
        ->and(ApnClientFactory::environments($channel(['environment' => 'auto'])))->toBe(['production', 'sandbox'])
        ->and(ApnClientFactory::environments($channel(['production' => true])))->toBe(['production', 'sandbox'])
        ->and(ApnClientFactory::environments($channel(['production' => false])))->toBe(['sandbox', 'production'])
        ->and(ApnClientFactory::environments($channel(['production' => 'false'])))->toBe(['sandbox', 'production'])
        ->and(ApnClientFactory::environments($channel([])))->toBe(['production', 'sandbox'])
        ->and(ApnClientFactory::environments($channel(['environment' => 'production']), 'sandbox'))->toBe(['sandbox']);
});

test('APNs credentials are validated and normalized', function () {
    $credentials = ApnClientFactory::credentialsFromChannel(pushChannel(['app_key' => 'apn'], [
        'key_id'              => ' KEY123 ',
        'team_id'             => 'TEAM123',
        'app_bundle_id'       => 'com.example.storefront',
        'private_key_content' => "-----BEGIN PRIVATE KEY-----\\nabc\\n-----END PRIVATE KEY-----\n",
    ]));

    expect($credentials['key_id'])->toBe('KEY123')
        ->and($credentials['private_key_content'])->toBe("-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----");

    expect(fn () => ApnClientFactory::credentialsFromChannel(pushChannel(['app_key' => 'apn'], ['key_id' => 'a', 'team_id' => 'b'])))
        ->toThrow(PushConfigurationException::class, 'missing "app_bundle_id"')
        ->and(fn () => ApnClientFactory::credentialsFromChannel(pushChannel(['app_key' => 'apn'], ['key_id' => 'a', 'team_id' => 'b', 'app_bundle_id' => 'c'])))
        ->toThrow(PushConfigurationException::class, 'missing its .p8 private key');

    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    openssl_pkey_export($key, $privateKey);
    $client = (new ApnClientFactory())->make(pushChannel(['app_key' => 'apn'], [
        'key_id'              => 'KEY123',
        'team_id'             => 'TEAM123',
        'app_bundle_id'       => 'com.example.storefront',
        'private_key_content' => $privateKey,
    ]), 'sandbox');

    expect($client)->toBeInstanceOf(Pushok\Client::class);
});

test('APNs responses map to push outcomes', function ($status, $reason, $expected) {
    $response = new Pushok\Response($status, '', $reason ? json_encode(['reason' => $reason]) : '', 'token');

    expect(ApnTransport::outcomeFor($response)->status)->toBe($expected);
})->with([
    'sent'                => [200, null, PushOutcome::SENT],
    'unregistered'        => [410, 'Unregistered', PushOutcome::DEAD],
    'expired'             => [410, 'ExpiredToken', PushOutcome::DEAD],
    'bad device token'    => [400, 'BadDeviceToken', PushOutcome::WRONG_ENVIRONMENT],
    'wrong topic'         => [400, 'DeviceTokenNotForTopic', PushOutcome::WRONG_APP],
    'bad provider token'  => [403, 'InvalidProviderToken', PushOutcome::ERROR],
]);

test('FCM send reports map to push outcomes', function ($error, $expected) {
    $target = MessageTarget::with(MessageTarget::TOKEN, 'token');
    $error  = $error instanceof Closure ? $error() : $error;
    $report = $error ? SendReport::failure($target, $error) : SendReport::success($target, ['name' => 'projects/x/messages/1']);

    expect(FcmTransport::outcomeFor($report)->status)->toBe($expected);
})->with([
    'sent'               => [null, PushOutcome::SENT],
    'unregistered'       => [fn () => new NotFound('Requested entity was not found.'), PushOutcome::DEAD],
    'invalid token'      => [fn () => new InvalidMessage('The registration token is not a valid FCM registration token'), PushOutcome::DEAD],
    'sender mismatch'    => [fn () => new AuthenticationError('SenderId mismatch'), PushOutcome::WRONG_APP],
    'bad credentials'    => [fn () => new AuthenticationError('Request had invalid authentication credentials.'), PushOutcome::ERROR],
    'provider outage'    => [fn () => new ServerUnavailable('Service unavailable'), PushOutcome::ERROR],
]);

test('storefront push channel routes devices by platform and retries tokens from another app on the next channel', function () {
    $network = pushChannel(['app_key' => 'network_fcm', 'scheme' => 'fcm']);
    $store   = pushChannel(['app_key' => 'store_fcm', 'scheme' => 'fcm']);
    $apn     = pushChannel(['app_key' => 'store_apn', 'scheme' => 'apn'], ['environment' => 'production']);

    $fcm = fakeFcm(fn (NotificationChannel $channel, array $tokens) => collect($tokens)->mapWithKeys(fn ($token) => [
        $token => $channel->app_key === 'network_fcm' && $token === 'android_store_app' ? PushOutcome::wrongApp('SenderId mismatch') : PushOutcome::sent(),
    ])->all());
    $apnTransport = fakeApn(fn ($channel, $environment, array $tokens) => array_fill_keys($tokens, PushOutcome::sent()));
    $pushChannel  = new StorefrontPushChannel(fakeResolver(['fcm' => [$network, $store], 'apn' => [$apn]]), $fcm, $apnTransport);

    $notifiable = new class {
        public $devices;
    };
    $notifiable->devices = collect([
        pushDevice('android_network_app', 'android'),
        pushDevice('android_store_app', 'Android'),
        pushDevice('ios_device', 'iOS'),
        pushDevice('ios_device', 'ios'),
        pushDevice('stale_device', 'android', ['status' => 'invalid']),
        pushDevice('web_device', 'web'),
    ]);

    $outcomes = $pushChannel->send($notifiable, pushNotification([]));

    expect(collect($outcomes)->map->status->all())->toBe([
        'android_network_app' => PushOutcome::SENT,
        'android_store_app'   => PushOutcome::SENT,
        'ios_device'          => PushOutcome::SENT,
    ])->and($fcm->calls)->toBe([
        ['network_fcm', ['android_network_app', 'android_store_app']],
        ['store_fcm', ['android_store_app']],
    ])->and($apnTransport->calls)->toBe([
        ['store_apn', 'production', ['ios_device']],
    ]);
});

test('storefront push channel retries BadDeviceToken in the other APNs environment', function () {
    $apn = pushChannel(['app_key' => 'store_apn', 'scheme' => 'apn'], ['production' => true]);

    $transport = fakeApn(fn ($channel, string $environment, array $tokens) => collect($tokens)->mapWithKeys(fn ($token) => [
        $token => $environment === 'production' && $token === 'dev_build' ? PushOutcome::wrongEnvironment('BadDeviceToken') : PushOutcome::sent(),
    ])->all());
    $pushChannel = new StorefrontPushChannel(fakeResolver(['apn' => [$apn]]), fakeFcm(fn () => []), $transport);

    $notifiable          = new stdClass();
    $notifiable->devices = collect([pushDevice('dev_build', 'ios'), pushDevice('app_store_build', 'ios')]);
    $notifiable          = new class($notifiable->devices) {
        public function __construct(public $devices)
        {
        }
    };

    $outcomes = $pushChannel->send($notifiable, pushNotification([]));

    expect($outcomes['dev_build']->status)->toBe(PushOutcome::SENT)
        ->and($outcomes['app_store_build']->status)->toBe(PushOutcome::SENT)
        ->and($transport->calls)->toBe([
            ['store_apn', 'production', ['dev_build', 'app_store_build']],
            ['store_apn', 'sandbox', ['dev_build']],
        ]);
});

test('storefront push channel prunes dead tokens and keeps tokens with transient errors', function () {
    createUserDevicesTable();
    $dead      = tap(new UserDevice())->forceFill(['uuid' => 'dead_uuid', 'public_id' => 'device_dead', 'user_uuid' => 'user_uuid', 'platform' => 'android', 'token' => 'dead_token', 'status' => 'active']);
    $dead->save();
    $transient = tap(new UserDevice())->forceFill(['uuid' => 'transient_uuid', 'public_id' => 'device_transient', 'user_uuid' => 'user_uuid', 'platform' => 'android', 'token' => 'transient_token', 'status' => 'active']);
    $transient->save();
    $garbage   = tap(new UserDevice())->forceFill(['uuid' => 'garbage_uuid', 'public_id' => 'device_garbage', 'user_uuid' => 'user_uuid', 'platform' => 'ios', 'token' => 'garbage_token', 'status' => 'active']);
    $garbage->save();

    $pushChannel = new StorefrontPushChannel(
        fakeResolver([
            'fcm' => [pushChannel(['app_key' => 'fcm', 'scheme' => 'fcm'])],
            'apn' => [pushChannel(['app_key' => 'apn', 'scheme' => 'apn'], ['environment' => 'auto'])],
        ]),
        fakeFcm(fn ($channel, array $tokens) => ['dead_token' => PushOutcome::dead('Unregistered'), 'transient_token' => PushOutcome::error('503')]),
        fakeApn(fn ($channel, $environment, array $tokens) => ['garbage_token' => PushOutcome::wrongEnvironment('BadDeviceToken')])
    );
    $notifiable = new class(collect([$dead, $transient, $garbage])) {
        public function __construct(public $devices)
        {
        }
    };

    $outcomes = $pushChannel->send($notifiable, pushNotification([]));

    expect($outcomes['dead_token']->status)->toBe(PushOutcome::DEAD)
        ->and($outcomes['transient_token']->status)->toBe(PushOutcome::ERROR)
        ->and($outcomes['garbage_token']->status)->toBe(PushOutcome::DEAD)
        ->and(UserDevice::pluck('token')->all())->toBe(['transient_token'])
        ->and(UserDevice::withTrashed()->where('token', 'dead_token')->value('status'))->toBe('invalid');
});

test('storefront push channel falls through to the next channel when a provider request throws', function () {
    $broken  = pushChannel(['app_key' => 'broken', 'scheme' => 'fcm']);
    $working = pushChannel(['app_key' => 'working', 'scheme' => 'fcm']);
    $fcm     = fakeFcm(function (NotificationChannel $channel, array $tokens) {
        if ($channel->app_key === 'broken') {
            throw new PushConfigurationException('missing "private_key"');
        }

        return array_fill_keys($tokens, PushOutcome::sent());
    });
    $pushChannel = new StorefrontPushChannel(fakeResolver(['fcm' => [$broken, $working]]), $fcm, fakeApn(fn () => []));
    $notifiable  = new class(collect([pushDevice('android_token', 'android')])) {
        public function __construct(public $devices)
        {
        }
    };

    $outcomes = $pushChannel->send($notifiable, pushNotification([]));

    expect($outcomes['android_token']->status)->toBe(PushOutcome::SENT)
        ->and(collect($fcm->calls)->pluck(0)->all())->toBe(['broken', 'working']);
});

test('storefront push channel is a no-op without devices, channels, a push payload or push support', function () {
    $fcm         = fakeFcm(fn () => throw new RuntimeException('should not send'));
    $pushChannel = new StorefrontPushChannel(fakeResolver([]), $fcm, fakeApn(fn () => throw new RuntimeException('should not send')));
    $withDevices = new class(collect([pushDevice('android_token', 'android'), pushDevice('ios_token', 'ios')])) {
        public function __construct(public $devices)
        {
        }
    };
    $withoutDevices = new class(collect()) {
        public function __construct(public $devices)
        {
        }
    };
    $silent = new class extends Notification implements SendsPushNotification {
        public function toPush($notifiable): ?PushMessage
        {
            return null;
        }

        public function pushStorefronts(): array
        {
            return [];
        }
    };

    expect($pushChannel->send($withDevices, pushNotification([])))->toBe([])
        ->and($pushChannel->send($withoutDevices, pushNotification([])))->toBe([])
        ->and($pushChannel->send($withDevices, $silent))->toBe([])
        ->and($pushChannel->send($withDevices, new class extends Notification {}))->toBe([])
        ->and($pushChannel->send(null, pushNotification([])))->toBe([])
        ->and($fcm->calls)->toBe([]);
});

test('storefront push channel prefers channels of the app the device registered from', function () {
    $resolver    = fakeResolver(['fcm' => [pushChannel(['app_key' => 'fcm', 'scheme' => 'fcm'])]]);
    $pushChannel = new StorefrontPushChannel($resolver, fakeFcm(fn ($channel, $tokens) => array_fill_keys($tokens, PushOutcome::sent())), fakeApn(fn () => []));
    $notifiable  = new class(collect([pushDevice('a', 'android', ['app_identifier' => 'network_uuid']), pushDevice('b', 'android')])) {
        public function __construct(public $devices)
        {
        }
    };

    $pushChannel->send($notifiable, pushNotification([]));

    expect($resolver->calls)->toBe([['fcm', 'network_uuid'], ['fcm', null]]);
});

test('credential resolver orders channels by device app, storefront preference and age', function () {
    $schema = Capsule::schema('mysql');
    $schema->dropIfExists('notification_channels');
    $schema->create('notification_channels', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->string('owner_type')->nullable();
        $table->string('name')->nullable();
        $table->string('scheme')->nullable();
        $table->string('app_key')->nullable();
        $table->text('config')->nullable();
        $table->text('options')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    Capsule::connection('mysql')->table('notification_channels')->insert([
        ['uuid' => 'c1', 'owner_uuid' => 'store_uuid', 'scheme' => 'fcm', 'app_key' => 'store_old', 'created_at' => '2024-01-01 00:00:00'],
        ['uuid' => 'c2', 'owner_uuid' => 'store_uuid', 'scheme' => 'fcm', 'app_key' => 'store_new', 'created_at' => '2025-01-01 00:00:00'],
        ['uuid' => 'c3', 'owner_uuid' => 'network_uuid', 'scheme' => 'fcm', 'app_key' => 'network', 'created_at' => '2025-06-01 00:00:00'],
        ['uuid' => 'c4', 'owner_uuid' => 'network_uuid', 'scheme' => 'apn', 'app_key' => 'network_apn', 'created_at' => '2023-01-01 00:00:00'],
        ['uuid' => 'c5', 'owner_uuid' => 'other_store', 'scheme' => 'fcm', 'app_key' => 'other', 'created_at' => '2020-01-01 00:00:00'],
    ]);

    $store    = tap(new Store())->forceFill(['uuid' => 'store_uuid']);
    $network  = tap(new Network())->forceFill(['uuid' => 'network_uuid']);
    $resolver = new PushCredentialResolver();

    expect($resolver->channels('fcm', [$network, $store])->pluck('app_key')->all())->toBe(['network', 'store_old', 'store_new'])
        ->and($resolver->channels('fcm', [$network, $store], 'store_uuid')->pluck('app_key')->all())->toBe(['store_old', 'store_new', 'network'])
        ->and($resolver->channels('fcm', [$store], 'other_store')->pluck('app_key')->all())->toBe(['other', 'store_old', 'store_new'])
        ->and($resolver->channels('apn', [$network, $store])->pluck('app_key')->all())->toBe(['network_apn'])
        ->and($resolver->channels('fcm', []))->toHaveCount(0);
});

test('credential resolver prefers the network app for marketplace orders', function () {
    $columns = function ($table) {
        $table->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'backdrop_uuid', 'logo_uuid', 'order_config_uuid', 'name', 'description', 'translations', 'website', 'facebook', 'instagram', 'twitter', 'email', 'phone', 'tags', 'currency', 'timezone', 'pod_method', 'options'] as $column) {
            $table->text($column)->nullable();
        }
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    };
    $schema = Capsule::schema('mysql');
    $schema->dropIfExists('stores');
    $schema->dropIfExists('networks');
    $schema->create('stores', $columns);
    $schema->create('networks', $columns);
    Capsule::connection('mysql')->table('stores')->insert(['uuid' => 'store_uuid', 'public_id' => 'store_public', 'name' => 'Corner Store']);
    Capsule::connection('mysql')->table('networks')->insert(['uuid' => 'network_uuid', 'public_id' => 'network_public', 'name' => 'Marketplace']);

    $order = new Order();
    $order->forceFill(['meta' => ['storefront_id' => 'store_public', 'storefront_network_id' => 'network_public']]);
    $storeOrder = new Order();
    $storeOrder->forceFill(['meta' => ['storefront_id' => 'store_public']]);
    $masterOrder = new Order();
    $masterOrder->forceFill(['meta' => ['storefront_id' => 'network_public', 'storefront_network_id' => 'network_public']]);

    expect(collect(PushCredentialResolver::storefrontsForOrder($order))->pluck('uuid')->all())->toBe(['network_uuid', 'store_uuid'])
        ->and(collect(PushCredentialResolver::storefrontsForOrder($storeOrder))->pluck('uuid')->all())->toBe(['store_uuid'])
        ->and(collect(PushCredentialResolver::storefrontsForOrder($masterOrder))->pluck('uuid')->all())->toBe(['network_uuid'])
        ->and(PushCredentialResolver::storefrontsForOrder(new Order()))->toBe([]);
});

test('platform names are normalized', function () {
    expect(StorefrontPushChannel::normalizePlatform('iOS'))->toBe('ios')
        ->and(StorefrontPushChannel::normalizePlatform(' ANDROID '))->toBe('android')
        ->and(StorefrontPushChannel::normalizePlatform('ipados'))->toBe('ios')
        ->and(StorefrontPushChannel::normalizePlatform('web'))->toBeNull()
        ->and(StorefrontPushChannel::normalizePlatform(null))->toBeNull();
});
