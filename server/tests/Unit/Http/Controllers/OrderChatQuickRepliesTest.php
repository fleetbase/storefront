<?php

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Storefront\Http\Controllers\OrderChatController;
use Fleetbase\Storefront\Support\OrderChat;

function quickReplyStore(array $options = []): void
{
    $schema = Illuminate\Database\Capsule\Manager::schema('mysql');
    $schema->dropIfExists('stores');
    $schema->create('stores', function (Illuminate\Database\Schema\Blueprint $table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id');
        $table->string('company_uuid')->nullable();
        $table->string('name');
        $table->text('options')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Illuminate\Database\Capsule\Manager::connection('mysql')->table('stores')->insert([
        'uuid'      => 'store_uuid',
        'public_id' => 'store_public',
        'name'      => 'Lion City Cleaning',
        'options'   => json_encode($options),
    ]);
}

function quickReplyOrder(array $meta = []): Order
{
    $order = new Order();
    $order->forceFill(['uuid' => 'order_uuid', 'public_id' => 'order_public', 'meta' => ['storefront_id' => 'store_public', ...$meta]]);

    return $order;
}

test('an order offers the delivery replies, a booking its own', function () {
    quickReplyStore();

    expect(OrderChatController::quickReplies(quickReplyOrder()))->toBe(OrderChatController::DEFAULT_QUICK_REPLIES)
        ->and(OrderChatController::quickReplies(quickReplyOrder(['is_booking' => true])))->toBe(OrderChatController::DEFAULT_BOOKING_REPLIES)
        ->and(OrderChatController::quickReplies(quickReplyOrder(['is_booking' => true, 'is_pickup' => true])))->toBe(OrderChatController::DEFAULT_BOOKING_AT_STORE_REPLIES)
        ->and(OrderChatController::quickReplies(quickReplyOrder(['is_booking' => true]), OrderChat::KIND_DRIVER))->toBe(OrderChatController::DEFAULT_BOOKING_REPLIES);
});

test('writing to the driver offers the driver replies, for a booking its provider ones', function () {
    quickReplyStore();

    expect(OrderChatController::quickReplies(quickReplyOrder(), OrderChat::KIND_DISPATCH))->toBe(OrderChatController::DEFAULT_DRIVER_REPLIES)
        ->and(OrderChatController::quickReplies(quickReplyOrder(['is_booking' => true]), OrderChat::KIND_DISPATCH))->toBe(OrderChatController::DEFAULT_BOOKING_DRIVER_REPLIES);
});

test("a store's own replies win, booking replies for bookings", function () {
    quickReplyStore(['quick_replies' => ['Your food is on its way.', ' '], 'booking_quick_replies' => [' See you soon! ', 42]]);

    expect(OrderChatController::quickReplies(quickReplyOrder()))->toBe(['Your food is on its way.'])
        ->and(OrderChatController::quickReplies(quickReplyOrder(['is_booking' => true])))->toBe(['See you soon!']);
});

test('a booking without its own store replies falls back to the booking defaults, not the delivery ones', function () {
    quickReplyStore(['quick_replies' => ['Your food is on its way.']]);

    expect(OrderChatController::quickReplies(quickReplyOrder(['is_booking' => true])))->toBe(OrderChatController::DEFAULT_BOOKING_REPLIES);
});
