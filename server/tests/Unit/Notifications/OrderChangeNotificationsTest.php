<?php

use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\Storefront\Notifications\StorefrontOrderBalancePaid;
use Fleetbase\Storefront\Notifications\StorefrontOrderItemUnavailable;
use Fleetbase\Storefront\Notifications\StorefrontOrderUpdated;
use Fleetbase\Storefront\Push\PushMessage;

function orderChangeStore(?string $timezone = 'Asia/Singapore'): void
{
    $schema = Illuminate\Database\Capsule\Manager::schema('mysql');
    $schema->dropIfExists('stores');
    $schema->create('stores', function (Illuminate\Database\Schema\Blueprint $table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id');
        $table->string('company_uuid')->nullable();
        $table->string('key')->nullable();
        $table->string('name');
        $table->string('currency')->nullable();
        $table->string('timezone')->nullable();
        $table->text('options')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Illuminate\Database\Capsule\Manager::connection('mysql')->table('stores')->insert([
        'uuid'      => 'store_uuid',
        'public_id' => 'store_public',
        'name'      => 'Lion City Cleaning',
        'currency'  => 'SGD',
        'timezone'  => $timezone,
        'options'   => '{}',
    ]);
}

function orderChangeOrder(array $meta = []): Order
{
    $order = new Order();
    $order->forceFill([
        'uuid'      => 'order_uuid',
        'public_id' => 'order_public',
        'meta'      => ['storefront_id' => 'store_public', 'currency' => 'SGD', ...$meta],
    ]);

    return $order;
}

test('an unavailable item tells the customer it will be replaced or removed', function () {
    orderChangeStore();

    $notification = new StorefrontOrderItemUnavailable(orderChangeOrder(), 'Mango Lassi');
    $push         = $notification->toPush((object) ['public_id' => 'contact_public']);

    expect($notification->status)->toBe('order_item_unavailable')
        ->and($notification->subject)->toBe('Mango Lassi is unavailable')
        ->and($notification->body)->toBe('Mango Lassi from your order is unavailable. Lion City Cleaning will replace or remove it and adjust your total if it changes.')
        ->and($push)->toBeInstanceOf(PushMessage::class)
        ->and($push->data)->toMatchArray(['type' => 'order_item_unavailable', 'id' => 'order_public']);

    expect((new StorefrontOrderItemUnavailable(orderChangeOrder(['is_booking' => true]), ' '))->body)
        ->toStartWith('An item from your booking is unavailable.');
});

test('a paid balance sends a receipt, with what is left when it was not all of it', function () {
    orderChangeStore();

    $paid = new StorefrontOrderBalancePaid(orderChangeOrder(['balance_due' => 0]), 1200);
    $part = new StorefrontOrderBalancePaid(orderChangeOrder(['balance_due' => 500, 'is_booking' => true]), 700);

    expect($paid->status)->toBe('order_balance_paid')
        ->and($paid->subject)->toBe('Payment received for your order from Lion City Cleaning')
        ->and($paid->body)->toContain('We received your payment of')
        ->and($paid->body)->toEndWith('Your order is fully paid.')
        ->and($part->subject)->toBe('Payment received for your booking from Lion City Cleaning')
        ->and($part->body)->toContain(' left to pay.');
});

test('a booking that only moved says when it is now, in the store time', function () {
    orderChangeStore('Asia/Singapore');

    $order = orderChangeOrder([
        'is_booking' => true,
        'booking'    => ['start' => '2026-10-19T03:00:00+00:00', 'end' => '2026-10-19T04:00:00+00:00', 'duration_minutes' => 60],
    ]);
    $service = new Entity();
    $service->forceFill(['name' => 'Deep Clean', 'meta' => ['scheduled_at' => '2026-10-19T03:00:00+00:00']]);
    $payload = new Payload();
    $payload->setRelation('entities', collect([$service]));
    $order->setRelation('payload', $payload);

    $moved = new StorefrontOrderUpdated($order, 'Booking moved to Mon 19 Oct, 11:00', ['schedule']);
    $other = new StorefrontOrderUpdated($order, 'Address changed', ['dropoff', 'schedule']);

    expect($moved->status)->toBe('order_updated')
        ->and($moved->subject)->toBe('Your booking with Lion City Cleaning was moved')
        ->and($moved->body)->toBe('Deep Clean is now Mon 19 Oct, 11:00 to 12:00.')
        ->and($other->subject)->toBe('Your booking with Lion City Cleaning was updated')
        ->and($other->body)->toStartWith('Address changed.');
});

test('an edited order keeps the order wording', function () {
    orderChangeStore();

    $notification = new StorefrontOrderUpdated(orderChangeOrder(), 'Removed Mango Lassi', ['items']);

    expect($notification->subject)->toBe('Your order from Lion City Cleaning was updated')
        ->and($notification->body)->toStartWith('Removed Mango Lassi.');
});
