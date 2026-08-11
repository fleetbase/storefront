<?php

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\NotificationChannel;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Notifications\PromotionalPushNotification;
use Fleetbase\Storefront\Notifications\StorefrontOrderAccepted;
use Fleetbase\Storefront\Notifications\StorefrontOrderCanceled;
use Fleetbase\Storefront\Notifications\StorefrontOrderCompleted;
use Fleetbase\Storefront\Notifications\StorefrontOrderCreated;
use Fleetbase\Storefront\Notifications\StorefrontOrderDriverAssigned;
use Fleetbase\Storefront\Notifications\StorefrontOrderEnroute;
use Fleetbase\Storefront\Notifications\StorefrontOrderNearby;
use Fleetbase\Storefront\Notifications\StorefrontOrderPreparing;
use Fleetbase\Storefront\Notifications\StorefrontOrderReadyForPickup;
use Fleetbase\Storefront\Support\PushNotification;
use Illuminate\Notifications\Messages\MailMessage;
use NotificationChannels\Apn\ApnMessage;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Twilio\TwilioChannel;
use NotificationChannels\Twilio\TwilioSmsMessage;

class TestablePromotionalPushNotification extends PromotionalPushNotification
{
    public static ?Pushok\Client $apnClient        = null;
    public static ?NotificationChannel $fcmChannel = null;
    public static mixed $fcmClient                 = null;

    protected function getApnClient(): ?Pushok\Client
    {
        return static::$apnClient;
    }

    protected function getFcmNotificationChannel(): ?NotificationChannel
    {
        return static::$fcmChannel;
    }

    protected function getFcmClient(NotificationChannel $notificationChannel)
    {
        return static::$fcmClient;
    }
}

function notificationWithoutConstructor(string $class, Order $order, Store $store, array $properties = []): object
{
    $notification                 = (new ReflectionClass($class))->newInstanceWithoutConstructor();
    $notification->order          = $order;
    $notification->storefront     = $store;
    $notification->sentAt         = '2026-07-26 12:00:00';
    $notification->notificationId = 'notification_contract';

    foreach ($properties as $property => $value) {
        $notification->{$property} = $value;
    }

    return $notification;
}

function notificationOrder(array $meta = []): Order
{
    $order = new Order();
    $order->forceFill([
        'uuid'      => 'order_uuid',
        'public_id' => 'order_public',
        'meta'      => $meta,
    ]);
    $order->setRelation('customer', new class(['public_id' => 'contact_public', 'name' => 'Ada Buyer', 'email' => 'ada@example.test', 'phone' => '+15550100']) extends Illuminate\Database\Eloquent\Model {
        protected $guarded = [];
    });
    $order->setRelation('company', new class(['public_id' => 'company_public', 'name' => 'Acme Logistics']) extends Illuminate\Database\Eloquent\Model {
        protected $guarded = [];
    });

    return $order;
}

test('order lifecycle notifications expose stable mail and database contracts', function ($class, $subject, $body, $status, $arrayMessage) {
    $order = notificationOrder();
    $store = new Store();
    $store->forceFill(['uuid' => 'store_uuid', 'public_id' => 'store_public', 'name' => 'Corner Store']);

    $notification = notificationWithoutConstructor($class, $order, $store, [
        'subject' => $subject,
        'body'    => $body,
        'status'  => $status,
    ]);
    $notifiable = (object) ['public_id' => 'user_public'];
    $mail       = $notification->toMail($notifiable);
    $payload    = $notification->toArray($notifiable);

    expect($mail)->toBeInstanceOf(MailMessage::class)
        ->and($mail->subject)->toBe($subject)
        ->and($mail->introLines)->toContain($body)
        ->and($payload)->toMatchArray([
            'notifiable'      => 'user_public',
            'notification_id' => 'notification_contract',
            'sent_at'         => '2026-07-26 12:00:00',
            'subject'         => $subject,
            'message'         => $arrayMessage,
            'storefront'      => 'Corner Store',
            'storefront_id'   => 'store_public',
            'id'              => 'contact_public',
            'email'           => 'ada@example.test',
            'phone'           => '+15550100',
            'companyId'       => 'company_public',
            'company'         => 'Acme Logistics',
        ]);
})->with([
    'accepted' => [
        StorefrontOrderAccepted::class,
        'Order accepted',
        'Your order was accepted.',
        'order_accepted',
        'order_accepted',
    ],
    'canceled' => [
        StorefrontOrderCanceled::class,
        'Order canceled',
        'Your order was canceled.',
        'order_canceled',
        'Your order was canceled.',
    ],
    'completed' => [
        StorefrontOrderCompleted::class,
        'Order completed',
        'Your order was delivered.',
        'order_completed',
        'order_completed',
    ],
    'driver assigned' => [
        StorefrontOrderDriverAssigned::class,
        'Driver assigned',
        'A driver is heading to the store.',
        'order_driver_assigned',
        'order_driver_assigned',
    ],
    'enroute' => [
        StorefrontOrderEnroute::class,
        'Order enroute',
        'Your order is on the way.',
        'order_enroute',
        'order_enroute',
    ],
    'nearby' => [
        StorefrontOrderNearby::class,
        'Order nearby',
        'Your order is almost there.',
        'order_nearby',
        'order_nearby',
    ],
    'preparing' => [
        StorefrontOrderPreparing::class,
        'Order preparing',
        'Your order is being prepared.',
        'order_preparing',
        'order_preparing',
    ],
    'ready for pickup' => [
        StorefrontOrderReadyForPickup::class,
        'Order ready',
        'Your order is ready for pickup.',
        'order_ready',
        'order_ready',
    ],
]);

test('order lifecycle notifications build their runtime messages and provider payloads', function ($class, $expectedStatus) {
    $schema = Illuminate\Database\Capsule\Manager::schema('mysql');
    $schema->dropIfExists('notification_channels');
    $schema->dropIfExists('stores');
    $schema->create('stores', function (Illuminate\Database\Schema\Blueprint $table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id');
        $table->string('company_uuid')->nullable();
        $table->string('key')->nullable();
        $table->string('name');
        $table->string('currency')->nullable();
        $table->text('options')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('notification_channels', function (Illuminate\Database\Schema\Blueprint $table) {
        $table->increments('id');
        $table->string('owner_uuid');
        $table->string('scheme');
        $table->timestamps();
        $table->softDeletes();
    });
    Illuminate\Database\Capsule\Manager::connection('mysql')->table('stores')->insert([
        'uuid'      => 'store_uuid',
        'public_id' => 'store_public',
        'name'      => 'Corner Store',
        'currency'  => 'USD',
        'options'   => '{}',
    ]);

    $order = notificationOrder(['storefront_id' => 'store_public']);
    if ($class === StorefrontOrderDriverAssigned::class) {
        $driver = new Fleetbase\FleetOps\Models\Driver();
        $driver->forceFill(['name' => 'Taylor Driver']);
        $order->setRelation('driverAssigned', $driver);
    }

    $notification = $class === StorefrontOrderNearby::class
        ? new $class($order, 125, 300)
        : new $class($order);
    $notifiable = (object) ['public_id' => 'user_public'];

    expect($notification->storefront->public_id)->toBe('store_public')
        ->and($notification->status)->toBe($expectedStatus)
        ->and($notification->subject)->not->toBeEmpty()
        ->and($notification->body)->not->toBeEmpty()
        ->and($notification->via($notifiable))->toBe(['mail', 'database'])
        ->and($notification->toFcm($notifiable))->toBeInstanceOf(FcmMessage::class)
        ->and($notification->toApn($notifiable))->toBeInstanceOf(ApnMessage::class);

    Illuminate\Database\Capsule\Manager::connection('mysql')->table('notification_channels')->insert([
        ['owner_uuid' => 'store_uuid', 'scheme' => 'apn'],
        ['owner_uuid' => 'store_uuid', 'scheme' => 'fcm'],
    ]);

    expect($notification->via($notifiable))->toBe([
        'mail',
        'database',
        NotificationChannels\Apn\ApnChannel::class,
        NotificationChannels\Fcm\FcmChannel::class,
    ]);
})->with([
    'accepted'        => [StorefrontOrderAccepted::class, 'order_accepted'],
    'canceled'        => [StorefrontOrderCanceled::class, 'order_canceled'],
    'completed'       => [StorefrontOrderCompleted::class, 'order_completed'],
    'driver assigned' => [StorefrontOrderDriverAssigned::class, 'order_driver_assigned'],
    'enroute'         => [StorefrontOrderEnroute::class, 'order_enroute'],
    'nearby'          => [StorefrontOrderNearby::class, 'order_nearby'],
    'preparing'       => [StorefrontOrderPreparing::class, 'order_preparing'],
    'ready for pickup'=> [StorefrontOrderReadyForPickup::class, 'order_ready'],
]);

test('created-order notification renders pickup messages without delivery-only charges', function () {
    $order = notificationOrder([
        'is_pickup'    => true,
        'subtotal'     => 2500,
        'delivery_fee' => 500,
        'delivery_tip' => 200,
        'tip'          => 100,
        'total'        => 2600,
        'currency'     => 'USD',
    ]);
    $order->setRelation('payload', (object) [
        'entities'     => collect([(object) ['name' => 'Coffee'], (object) ['name' => 'Cake']]),
        'dropoff'      => (object) ['address' => '1 Market Street'],
        'pickup_name'  => null,
        'dropoff_name' => null,
        'return_name'  => null,
    ]);
    $store        = new Store(['name' => 'Corner Store']);
    $notification = notificationWithoutConstructor(StorefrontOrderCreated::class, $order, $store);

    expect($notification->via(null))->toBe(['mail', TwilioChannel::class]);

    $sms  = $notification->toTwilio(null);
    $mail = $notification->toMail(null);

    expect($sms)->toBeInstanceOf(TwilioSmsMessage::class)
        ->and($sms->content)->toContain('A new pickup order was just created!')
        ->and($sms->content)->toContain('Items: Coffee,Cake')
        ->and($sms->content)->toContain('Tip:')
        ->and($sms->content)->not->toContain('Delivery Fee:')
        ->and($mail)->toBeInstanceOf(MailMessage::class)
        ->and($mail->subject)->toContain('Corner Store')
        ->and(implode("\n", $mail->introLines))->toContain('A new pickup order was just created!')
        ->not->toContain('Delivery Fee:')
        ->and($notification->toArray(null))->toMatchArray([
            'uuid'      => 'order_uuid',
            'public_id' => 'order_public',
        ]);
});

test('created-order notification includes delivery address fee and optional delivery tip', function () {
    $order = notificationOrder([
        'is_pickup'    => false,
        'subtotal'     => 2500,
        'delivery_fee' => 500,
        'delivery_tip' => 200,
        'tip'          => null,
        'total'        => 3200,
        'currency'     => 'USD',
    ]);
    $order->setRelation('payload', (object) [
        'entities' => collect([(object) ['name' => 'Coffee']]),
        'dropoff'  => (object) ['address' => '1 Market Street'],
    ]);
    $notification = notificationWithoutConstructor(
        StorefrontOrderCreated::class,
        $order,
        new Store(['name' => 'Corner Store'])
    );

    $sms       = $notification->toTwilio(null);
    $mailLines = implode("\n", $notification->toMail(null)->introLines);

    expect($sms->content)->toContain('A new delivery order was just created!')
        ->and($sms->content)->toContain('Address: 1 Market Street')
        ->and($sms->content)->toContain('Delivery Fee:')
        ->and($sms->content)->toContain('Delivery Tip:')
        ->and($mailLines)->toContain('Address: 1 Market Street')
        ->and($mailLines)->toContain('Delivery Fee:')
        ->and($mailLines)->toContain('Delivery Tip:');
});

test('created-order notification resolves its storefront from order metadata', function () {
    $schema = Illuminate\Database\Capsule\Manager::schema('mysql');
    $schema->dropIfExists('stores');
    $schema->create('stores', function (Illuminate\Database\Schema\Blueprint $table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id');
        $table->string('name');
        $table->timestamps();
        $table->softDeletes();
    });
    Illuminate\Database\Capsule\Manager::connection('mysql')->table('stores')->insert([
        'uuid'      => 'store_uuid',
        'public_id' => 'store_public',
        'name'      => 'Corner Store',
    ]);

    $notification = new StorefrontOrderCreated(notificationOrder(['storefront_id' => 'store_public']));

    expect($notification->storefront->public_id)->toBe('store_public')
        ->and($notification->notificationId)->toStartWith('notification_')
        ->and($notification->sentAt)->not->toBeEmpty();
});

test('promotional notifications expose their persisted payload contract', function () {
    $store = new Store();
    $store->forceFill(['uuid' => 'store_uuid', 'public_id' => 'store_public', 'name' => 'Corner Store']);

    $notification = new PromotionalPushNotification('Weekend sale', 'Save twenty percent', $store);
    $payload      = $notification->toArray(null);

    expect($payload)->toMatchArray([
        'title'    => 'Weekend sale',
        'body'     => 'Save twenty percent',
        'store'    => 'store_uuid',
        'store_id' => 'store_public',
        'type'     => 'promotional',
    ])->and($payload['sent_at'])->not->toBeEmpty()
        ->and($payload['notification_id'])->toStartWith('notification_');
});

test('promotional notifications build configured APN and FCM provider messages', function () {
    config(['firebase.projects.app' => [
        'credentials' => ['private_key' => 'default-key'],
        'database'    => ['url' => 'https://default.example.test'],
    ]]);

    $store = new Store();
    $store->forceFill(['uuid' => 'store_uuid', 'public_id' => 'store_public']);

    $channel = new NotificationChannel();
    $channel->forceFill(['app_key' => 'store-app']);
    $channel->config = [
        'firebase_credentials_json' => 'channel-private-key',
        'firebase_database_url'     => 'https://channel.example.test',
    ];

    TestablePromotionalPushNotification::$apnClient  = (new ReflectionClass(Pushok\Client::class))->newInstanceWithoutConstructor();
    TestablePromotionalPushNotification::$fcmChannel = $channel;
    TestablePromotionalPushNotification::$fcmClient  = (new ReflectionClass(Kreait\Firebase\Messaging::class))->newInstanceWithoutConstructor();

    $notification = new TestablePromotionalPushNotification('Weekend sale', 'Save now', $store);

    expect($notification->toApn(null))->toBeInstanceOf(ApnMessage::class)
        ->and($notification->toFcm(null))->toBeInstanceOf(FcmMessage::class)
        ->and(data_get(config('firebase.projects.store-app'), 'credentials.private_key'))->toBe('channel-private-key');
});

test('promotional notification provider resolvers honor the persisted channel configuration', function () {
    $schema = Illuminate\Database\Capsule\Manager::schema('mysql');
    $schema->dropIfExists('notification_channels');
    $schema->create('notification_channels', function (Illuminate\Database\Schema\Blueprint $table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('owner_uuid');
        $table->string('app_key');
        $table->string('scheme');
        $table->text('config')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    $store = new Store();
    $store->forceFill(['uuid' => 'store_uuid', 'public_id' => 'store_public']);
    $notification = new PromotionalPushNotification('Weekend sale', 'Save now', $store);

    $apnResolver    = new ReflectionMethod($notification, 'getApnClient');
    $fcmResolver    = new ReflectionMethod($notification, 'getFcmNotificationChannel');
    $clientResolver = new ReflectionMethod($notification, 'getFcmClient');

    expect($apnResolver->invoke($notification))->toBeNull()
        ->and($fcmResolver->invoke($notification))->toBeNull();

    Illuminate\Database\Capsule\Manager::connection('mysql')->table('notification_channels')->insert([
        'uuid'       => 'channel_uuid',
        'owner_uuid' => 'store_uuid',
        'app_key'    => 'store-app',
        'scheme'     => 'fcm',
        'config'     => '{}',
    ]);

    $channel = $fcmResolver->invoke($notification);

    expect($channel?->app_key)->toBe('store-app');

    try {
        $clientResolver->invoke($notification, $channel);
    } catch (Throwable $exception) {
        expect($exception)->toBeInstanceOf(Throwable::class);
    }
});

test('push notification messages retain payloads when provider channels are not configured', function () {
    $order = notificationOrder();
    $store = new Store();
    $store->forceFill(['uuid' => 'store_uuid', 'public_id' => 'store_public']);

    $double = new class extends PushNotification {
        public static Store $store;
        public static ?string $requestedScheme = null;

        public static function getStorefrontFromOrder(Order $order): Network|Store|null
        {
            return static::$store;
        }

        public static function getApnClient(Network|Store $storefront, ?Order $order = null): ?Pushok\Client
        {
            return null;
        }

        public static function getNotificationChannel(string $scheme, Network|Store $storefront, ?Order $order = null): ?NotificationChannel
        {
            static::$requestedScheme = $scheme;

            return null;
        }
    };
    $double::$store = $store;

    $apn = $double::createApnMessage($order, 'Order ready', 'Collect your order', 'pickup_ready');
    $fcm = $double::createFcmMessage($order, 'Order ready', 'Collect your order', 'pickup_ready');

    expect($apn)->toBeInstanceOf(ApnMessage::class)
        ->and($fcm)->toBeInstanceOf(FcmMessage::class)
        ->and($double::$requestedScheme)->toBe('fcm');
});

test('configured FCM messages include order routing metadata and the isolated provider client', function () {
    $order = notificationOrder();
    $store = new Store();
    $store->forceFill(['uuid' => 'store_uuid', 'public_id' => 'store_public']);
    $channel = new NotificationChannel();
    $channel->forceFill(['app_key' => 'store-app']);
    $channel->config = [
        'firebase_credentials_json' => 'channel-private-key',
        'firebase_database_url'     => 'https://channel.example.test',
    ];
    $client = (new ReflectionClass(Kreait\Firebase\Messaging::class))->newInstanceWithoutConstructor();

    $double = new class extends PushNotification {
        public static Store $store;
        public static NotificationChannel $channel;
        public static Kreait\Firebase\Contract\Messaging $client;

        public static function getStorefrontFromOrder(Order $order): Network|Store|null
        {
            return static::$store;
        }

        public static function getNotificationChannel(string $scheme, Network|Store $storefront, ?Order $order = null): ?NotificationChannel
        {
            return static::$channel;
        }

        protected static function getFcmClient(NotificationChannel $notificationChannel)
        {
            return static::$client;
        }
    };
    $double::$store   = $store;
    $double::$channel = $channel;
    $double::$client  = $client;
    config(['firebase.projects.app' => [
        'credentials' => ['private_key' => 'default-key'],
        'database'    => ['url' => 'https://default.example.test'],
    ]]);

    $message = $double::createFcmMessage($order, 'Order ready', 'Collect your order', 'pickup_ready');
    $payload = $message->toArray();

    expect($message->client)->toBe($client)
        ->and($message->notification?->title)->toBe('Order ready')
        ->and($message->notification?->body)->toBe('Collect your order')
        ->and($message->data)->toBe([
            'order' => $order->uuid,
            'id'    => $order->public_id,
            'type'  => 'pickup_ready',
        ])
        ->and(data_get($payload, 'android.notification.sound'))->toBe('default')
        ->and(data_get($payload, 'apns.payload.aps.sound'))->toBe('default');
});

test('push notification creates the configured firebase project messaging client', function () {
    $privateKey = openssl_pkey_new(['private_key_bits' => 2048]);
    openssl_pkey_export($privateKey, $privateKeyContent);
    config(['firebase.projects.real-app' => [
        'credentials' => [
            'type'                        => 'service_account',
            'project_id'                  => 'storefront-tests',
            'private_key_id'              => 'key-id',
            'private_key'                 => $privateKeyContent,
            'client_email'                => 'firebase-admin@example.test',
            'client_id'                   => '123456789',
            'auth_uri'                    => 'https://accounts.google.com/o/oauth2/auth',
            'token_uri'                   => 'https://oauth2.googleapis.com/token',
            'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
            'client_x509_cert_url'        => 'https://www.googleapis.com/robot/v1/metadata/x509/firebase-admin',
        ],
    ]]);
    Illuminate\Container\Container::getInstance()->instance('config', new class {
        public function get(string $key)
        {
            return config($key);
        }
    });
    $channel = new NotificationChannel();
    $channel->forceFill(['app_key' => 'real-app']);
    $method = new ReflectionMethod(PushNotification::class, 'getFcmClient');

    $client = $method->invoke(null, $channel);

    expect($client)->toBeInstanceOf(Kreait\Firebase\Contract\Messaging::class);
});

test('push notification configures isolated firebase projects from channel credentials', function () {
    config(['firebase.projects.app' => [
        'credentials' => ['private_key' => 'default-key', 'client_email' => 'firebase@example.test'],
        'database'    => ['url' => 'https://default.example.test'],
    ]]);

    $channel = new NotificationChannel();
    $channel->forceFill(['app_key' => 'store-app']);
    $channel->config = [
        'firebase_credentials_json' => 'channel-private-key',
        'firebase_database_url'     => 'https://channel.example.test',
    ];

    $configured = PushNotification::configureFcm($channel);

    expect(data_get($configured, 'credentials.private_key'))->toBe('channel-private-key')
        ->and(data_get($configured, 'credentials.client_email'))->toBe('firebase@example.test')
        ->and(data_get($configured, 'database.url'))->toBe('https://channel.example.test')
        ->and(config('firebase.projects.store-app'))->toBe($configured);
});

test('push notification channel lookup honors an order channel override before storefront defaults', function () {
    $schema = Illuminate\Database\Capsule\Manager::schema('mysql');
    $schema->dropIfExists('notification_channels');
    $schema->create('notification_channels', function (Illuminate\Database\Schema\Blueprint $table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('owner_uuid');
        $table->string('owner_type')->nullable();
        $table->string('app_key');
        $table->string('scheme');
        $table->text('config')->nullable();
        $table->text('options')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Illuminate\Database\Capsule\Manager::connection('mysql')->table('notification_channels')->insert([
        [
            'uuid'       => 'channel_default',
            'owner_uuid' => 'store_uuid',
            'app_key'    => 'default-app',
            'scheme'     => 'fcm',
        ],
        [
            'uuid'       => 'channel_override',
            'owner_uuid' => 'store_uuid',
            'app_key'    => 'override-app',
            'scheme'     => 'fcm',
        ],
    ]);

    $store = new Store();
    $store->forceFill(['uuid' => 'store_uuid']);

    $default  = PushNotification::getNotificationChannel('fcm', $store);
    $order    = notificationOrder(['storefront_notification_channel' => 'override-app']);
    $override = PushNotification::getNotificationChannel('fcm', $store, $order);

    expect($default?->app_key)->toBe('default-app')
        ->and($override?->app_key)->toBe('override-app')
        ->and(PushNotification::getNotificationChannel('apn', $store))->toBeNull();
});

test('push notification resolves APN credentials into a production-aware provider client', function () {
    $schema = Illuminate\Database\Capsule\Manager::schema('mysql');
    $schema->dropIfExists('notification_channels');
    $schema->create('notification_channels', function (Illuminate\Database\Schema\Blueprint $table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('owner_uuid');
        $table->string('owner_type')->nullable();
        $table->string('app_key');
        $table->string('scheme');
        $table->text('config')->nullable();
        $table->text('options')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $privateKey = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name'       => 'secp521r1',
    ]);
    openssl_pkey_export($privateKey, $privateKeyContent);
    Illuminate\Database\Capsule\Manager::connection('mysql')->table('notification_channels')->insert([
        'uuid'       => 'channel_apn',
        'owner_uuid' => 'store_uuid',
        'app_key'    => 'store-app',
        'scheme'     => 'apn',
        'config'     => json_encode([
            'key_id'              => 'KEY123',
            'team_id'             => 'TEAM123',
            'app_bundle_id'       => 'com.example.storefront',
            'private_key_content' => $privateKeyContent,
            'production'          => false,
        ]),
    ]);
    $store = new Store();
    $store->forceFill(['uuid' => 'store_uuid']);

    $client = PushNotification::getApnClient($store);

    expect($client)->toBeInstanceOf(Pushok\Client::class);
});

test('push notification resolves the storefront referenced by order metadata', function () {
    $schema = Illuminate\Database\Capsule\Manager::schema('mysql');
    $schema->dropIfExists('stores');
    $schema->create('stores', function (Illuminate\Database\Schema\Blueprint $table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('key')->nullable();
        $table->string('name')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('backdrop_uuid')->nullable();
        $table->string('logo_uuid')->nullable();
        $table->string('order_config_uuid')->nullable();
        $table->text('description')->nullable();
        $table->text('translations')->nullable();
        $table->string('website')->nullable();
        $table->string('facebook')->nullable();
        $table->string('instagram')->nullable();
        $table->string('twitter')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->text('tags')->nullable();
        $table->string('currency')->nullable();
        $table->string('timezone')->nullable();
        $table->string('pod_method')->nullable();
        $table->text('options')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    Illuminate\Database\Capsule\Manager::connection('mysql')->table('stores')->insert([
        'uuid'      => 'store_uuid',
        'public_id' => 'store_public',
        'key'       => 'store_key',
        'name'      => 'Test store',
    ]);

    $storefront = PushNotification::getStorefrontFromOrder(
        notificationOrder(['storefront_id' => 'store_public'])
    );

    expect($storefront)->toBeInstanceOf(Store::class)
        ->and($storefront->uuid)->toBe('store_uuid');
});

test('configured APN messages include order routing metadata and provider client', function () {
    $order = notificationOrder();
    $store = new Store();
    $store->forceFill(['uuid' => 'store_uuid', 'public_id' => 'store_public']);
    $client = (new ReflectionClass(Pushok\Client::class))->newInstanceWithoutConstructor();

    $double = new class extends PushNotification {
        public static Store $store;
        public static Pushok\Client $client;

        public static function getStorefrontFromOrder(Order $order): Network|Store|null
        {
            return static::$store;
        }

        public static function getApnClient(Network|Store $storefront, ?Order $order = null): ?Pushok\Client
        {
            return static::$client;
        }
    };
    $double::$store  = $store;
    $double::$client = $client;

    $message = $double::createApnMessage($order, 'Order ready', 'Collect it now', 'pickup_ready');

    expect($message)->toBeInstanceOf(ApnMessage::class);
});

test('promotional notifications select only configured provider channels and fail closed without them', function () {
    $schema = Illuminate\Database\Capsule\Manager::schema('mysql');
    $schema->dropIfExists('notification_channels');
    $schema->create('notification_channels', function (Illuminate\Database\Schema\Blueprint $table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('owner_uuid');
        $table->string('owner_type')->nullable();
        $table->string('app_key');
        $table->string('scheme');
        $table->text('config')->nullable();
        $table->text('options')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Illuminate\Database\Capsule\Manager::connection('mysql')->table('notification_channels')->insert([
        ['owner_uuid' => 'store_uuid', 'app_key' => 'apn-app', 'scheme' => 'apn'],
        ['owner_uuid' => 'store_uuid', 'app_key' => 'fcm-app', 'scheme' => 'fcm'],
    ]);

    $store = new Store();
    $store->forceFill(['uuid' => 'store_uuid', 'public_id' => 'store_public']);
    $notification = new PromotionalPushNotification('Weekend sale', 'Save now', $store);

    expect($notification->via(null))->toBe([
        NotificationChannels\Apn\ApnChannel::class,
        NotificationChannels\Fcm\FcmChannel::class,
    ]);

    Illuminate\Database\Capsule\Manager::connection('mysql')->table('notification_channels')->delete();

    expect($notification->via(null))->toBe([])
        ->and($notification->toApn(null))->toBeNull()
        ->and($notification->toFcm(null))->toBeNull();
});
