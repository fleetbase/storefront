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
    $customer = (object) ['user_uuid' => 'customer_user', 'meta' => null];
    $other    = (object) ['user_uuid' => 'someone_else', 'meta' => null];
    $recorded = (object) ['user_uuid' => 'someone_else', 'meta' => ['role' => 'store']];

    expect(OrderChat::roleOf(chatChannel(null), $customer, 'customer_user'))->toBe(OrderChat::ROLE_CUSTOMER)
        ->and(OrderChat::roleOf(chatChannel(null), $other, 'customer_user'))->toBe(OrderChat::ROLE_DRIVER)
        ->and(OrderChat::roleOf(chatChannel('customer'), $other, 'customer_user'))->toBe(OrderChat::ROLE_STORE)
        ->and(OrderChat::roleOf(chatChannel(null), $recorded, 'customer_user'))->toBe(OrderChat::ROLE_STORE);
});
