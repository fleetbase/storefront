<?php

use Fleetbase\FleetOps\Models\Order;
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
use Fleetbase\Storefront\Push\PushMessage;
use Fleetbase\Storefront\Push\StorefrontPushChannel;
use Illuminate\Notifications\Messages\MailMessage;
use NotificationChannels\Twilio\TwilioChannel;
use NotificationChannels\Twilio\TwilioSmsMessage;

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
            'type'            => $status,
            'title'           => $subject,
            'body'            => $body,
            'subject'         => $subject,
            'message'         => $arrayMessage,
            'order'           => 'order_uuid',
            'order_id'        => 'order_public',
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
        'Your order was accepted.',
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
        'Your order was delivered.',
    ],
    'driver assigned' => [
        StorefrontOrderDriverAssigned::class,
        'Driver assigned',
        'A driver is heading to the store.',
        'order_driver_assigned',
        'A driver is heading to the store.',
    ],
    'enroute' => [
        StorefrontOrderEnroute::class,
        'Order enroute',
        'Your order is on the way.',
        'order_enroute',
        'Your order is on the way.',
    ],
    'nearby' => [
        StorefrontOrderNearby::class,
        'Order nearby',
        'Your order is almost there.',
        'order_nearby',
        'Your order is almost there.',
    ],
    'preparing' => [
        StorefrontOrderPreparing::class,
        'Order preparing',
        'Your order is being prepared.',
        'order_preparing',
        'Your order is being prepared.',
    ],
    'ready for pickup' => [
        StorefrontOrderReadyForPickup::class,
        'Order ready',
        'Your order is ready for pickup.',
        'order_ready',
        'Your order is ready for pickup.',
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

    $push = $notification->toPush($notifiable);

    expect($notification->storefront->public_id)->toBe('store_public')
        ->and($notification->status)->toBe($expectedStatus)
        ->and($notification->subject)->not->toBeEmpty()
        ->and($notification->body)->not->toBeEmpty()
        ->and($notification->via($notifiable))->toBe([StorefrontPushChannel::class, 'database', 'mail'])
        ->and($push)->toBeInstanceOf(PushMessage::class)
        ->and($push->title)->toBe($notification->subject)
        ->and($push->body)->toBe($notification->body)
        ->and($push->data)->toMatchArray([
            'type'     => $expectedStatus,
            'order'    => 'order_uuid',
            'id'       => 'order_public',
            'store_id' => 'store_public',
        ])
        ->and(collect($notification->pushStorefronts())->pluck('uuid')->all())->toBe(['store_uuid']);
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

test('order notifications tolerate orders without a loaded customer or company', function () {
    $order = new Order();
    $order->forceFill(['uuid' => 'order_uuid', 'public_id' => 'order_public', 'meta' => []]);
    $order->setRelation('customer', null);
    $order->setRelation('company', null);
    $store = new Store();
    $store->forceFill(['uuid' => 'store_uuid', 'public_id' => 'store_public', 'name' => 'Corner Store']);

    $notification = notificationWithoutConstructor(StorefrontOrderCompleted::class, $order, $store, [
        'subject' => 'Order completed',
        'body'    => 'Your order was delivered.',
        'status'  => 'order_completed',
    ]);

    expect($notification->toArray(null))->toMatchArray([
        'notifiable' => null,
        'id'         => null,
        'companyId'  => null,
        'message'    => 'Your order was delivered.',
    ]);
});

test('promotional notifications expose their persisted payload contract', function () {
    $store = new Store();
    $store->forceFill(['uuid' => 'store_uuid', 'public_id' => 'store_public', 'name' => 'Corner Store']);

    $notification = new PromotionalPushNotification('Weekend sale', 'Save twenty percent', $store);
    $payload      = $notification->toArray(null);

    expect($payload)->toMatchArray([
        'title'    => 'Weekend sale',
        'body'     => 'Save twenty percent',
        'message'  => 'Save twenty percent',
        'store'    => 'store_uuid',
        'store_id' => 'store_public',
        'type'     => 'promotional',
    ])->and($payload['sent_at'])->not->toBeEmpty()
        ->and($payload['notification_id'])->toStartWith('notification_');
});

test('promotional notifications deliver through the storefront push channel and the inbox', function () {
    $store = new Store();
    $store->forceFill(['uuid' => 'store_uuid', 'public_id' => 'store_public', 'name' => 'Corner Store']);
    $store->setRelation('networks', collect([tap(new Fleetbase\Storefront\Models\Network())->forceFill(['uuid' => 'network_uuid'])]));

    $notification = new PromotionalPushNotification('Weekend sale', 'Save now', $store);
    $push         = $notification->toPush(null);

    expect($notification->via(null))->toBe([StorefrontPushChannel::class, 'database'])
        ->and($push)->toBeInstanceOf(PushMessage::class)
        ->and($push->title)->toBe('Weekend sale')
        ->and($push->data)->toBe(['type' => 'promotional', 'store' => 'store_uuid', 'store_id' => 'store_public'])
        ->and(collect($notification->pushStorefronts())->pluck('uuid')->all())->toBe(['store_uuid', 'network_uuid']);
});

test('promotional notifications still use the store app when network membership cannot be loaded', function () {
    Illuminate\Database\Capsule\Manager::schema('mysql')->dropIfExists('network_stores');
    Illuminate\Database\Capsule\Manager::schema('mysql')->dropIfExists('networks');

    $store = new Store();
    $store->forceFill(['uuid' => 'store_uuid', 'public_id' => 'store_public']);

    expect(collect((new PromotionalPushNotification('Sale', 'Save now', $store))->pushStorefronts())->pluck('uuid')->all())->toBe(['store_uuid']);
});

test('order notifications fall back to their storefront when the order has no storefront metadata', function () {
    $store = new Store();
    $store->forceFill(['uuid' => 'store_uuid', 'public_id' => 'store_public', 'name' => 'Corner Store']);
    $notification = notificationWithoutConstructor(StorefrontOrderAccepted::class, notificationOrder(), $store);

    expect(collect($notification->pushStorefronts())->pluck('uuid')->all())->toBe(['store_uuid']);
});
