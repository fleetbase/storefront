<?php

use Fleetbase\Models\ChatChannel;
use Fleetbase\Storefront\Support\OrderChat;

function chatChannel(?string $kind): ChatChannel
{
    $channel = new ChatChannel();
    $channel->forceFill(['meta' => array_filter(['storefront_order_uuid' => 'order_uuid', 'kind' => $kind])]);

    return $channel;
}

test('a thread without a kind is the driver thread, and unknown kinds fall back to it', function () {
    expect(OrderChat::kindOf(chatChannel(null)))->toBe(OrderChat::KIND_DRIVER)
        ->and(OrderChat::kindOf(chatChannel('group')))->toBe(OrderChat::KIND_DRIVER)
        ->and(OrderChat::kindOf(chatChannel('customer')))->toBe(OrderChat::KIND_CUSTOMER)
        ->and(OrderChat::normalizeKind('group'))->toBe(OrderChat::KIND_DRIVER)
        ->and(OrderChat::normalizeKind(null))->toBe(OrderChat::KIND_DRIVER);
});

test('roles come from the participant, else who they are in the thread', function () {
    $schema = Illuminate\Database\Eloquent\Model::getConnectionResolver()->connection('mysql')->getSchemaBuilder();
    $schema->dropIfExists('drivers');
    $schema->create('drivers', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    Illuminate\Database\Eloquent\Model::getConnectionResolver()->connection('mysql')->table('drivers')->insert(['uuid' => 'driver_uuid', 'user_uuid' => 'driver_user']);

    $customer = (object) ['user_uuid' => 'customer_user', 'meta' => null];
    $driver   = (object) ['user_uuid' => 'driver_user', 'meta' => null];
    $staff    = (object) ['user_uuid' => 'staff_user', 'meta' => null];
    $recorded = (object) ['user_uuid' => 'staff_user', 'meta' => ['role' => 'store']];

    expect(OrderChat::roleOf(chatChannel(null), $customer, 'customer_user'))->toBe(OrderChat::ROLE_CUSTOMER)
        ->and(OrderChat::roleOf(chatChannel('group'), $driver, 'customer_user'))->toBe(OrderChat::ROLE_DRIVER)
        ->and(OrderChat::roleOf(chatChannel('group'), $staff, 'customer_user'))->toBe(OrderChat::ROLE_STORE)
        ->and(OrderChat::roleOf(chatChannel('customer'), $staff, 'customer_user'))->toBe(OrderChat::ROLE_STORE)
        ->and(OrderChat::roleOf(chatChannel('driver'), $driver, 'customer_user'))->toBe(OrderChat::ROLE_DRIVER)
        ->and(OrderChat::roleOf(chatChannel('driver'), $staff, 'customer_user'))->toBe(OrderChat::ROLE_STORE)
        ->and(OrderChat::roleOf(chatChannel(null), $recorded, 'customer_user'))->toBe(OrderChat::ROLE_STORE);

    $schema->dropIfExists('drivers');
});

test('the store and driver thread is its own kind, apart from the customer and driver thread', function () {
    expect(OrderChat::kindOf(chatChannel('driver')))->toBe(OrderChat::KIND_DISPATCH)
        ->and(OrderChat::kindOf(chatChannel('group')))->toBe(OrderChat::KIND_DRIVER)
        ->and(OrderChat::normalizeKind('group'))->toBe(OrderChat::KIND_DRIVER)
        ->and(OrderChat::normalizeKind('dispatch'))->toBe(OrderChat::KIND_DISPATCH);
});
