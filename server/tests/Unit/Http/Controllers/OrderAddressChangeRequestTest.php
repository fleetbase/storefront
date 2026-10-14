<?php

require_once __DIR__ . '/../../../Support/OrderOpsCoverageHelpers.php';

use Fleetbase\Models\ChatChannel;
use Fleetbase\Models\ChatMessage;
use Fleetbase\Notifications\ChatMessageReceived;
use Fleetbase\Storefront\Http\Controllers\v1\OrderChatController;
use Fleetbase\Storefront\Support\OrderChat;

/**
 * A customer app request to the order chat endpoints, signed in unless no token is given.
 */
function addressChangeRequest(array $input = [], ?string $token = 'ops-secret'): Illuminate\Http\Request
{
    $request = orderOpsCoverageRequest($input, 'POST', $token);
    session(['company' => 'company_uuid', 'storefront_key' => 'store_key', 'storefront_store' => null, 'storefront_network' => null]);

    return $request;
}

function addressChangeSetup(bool $chatMeta = true): void
{
    orderOpsCoverageSchema($chatMeta);
    orderOpsCoverageForgetMetaColumns();
    orderOpsCoverageOrder('order_delivery');
    orderOpsCoverageOrder('order_pickup', [], ['storefront_id' => 'store_bloom', 'is_pickup' => true]);
    orderOpsCoverageOrder('order_finished', ['status' => 'completed']);
    orderOpsCoverageDb()->table('places')->insert([
        ['uuid' => 'place_home_uuid', 'public_id' => 'place_home', 'company_uuid' => 'company_uuid', 'owner_uuid' => orderOpsCoverageCustomerUuid(), 'name' => 'Home', 'street1' => '12 Orchard Road', 'city' => 'Singapore'],
        ['uuid' => 'place_other_uuid', 'public_id' => 'place_other', 'company_uuid' => 'company_uuid', 'owner_uuid' => 'guest_contact_uuid', 'name' => 'Not mine', 'street1' => '1 Elsewhere Lane', 'city' => 'Singapore'],
    ]);
}

beforeEach(function () {
    $this->notifications = orderOpsCoverageBoot();
    addressChangeSetup();
    Illuminate\Support\Carbon::setTestNow('2026-10-14 11:00:00');
});

afterEach(function () {
    orderOpsCoverageShutdown();
    session(['storefront_key' => null, 'company' => null]);
});

test('the customer tells the store the address is wrong, with a saved place, in the store thread', function () {
    $order = Fleetbase\FleetOps\Models\Order::where('public_id', 'order_delivery')->with(['customer', 'driverAssigned'])->first();
    OrderChat::openStore($order, 'staff_user_uuid');

    $response = (new OrderChatController())->requestAddressChange(addressChangeRequest(['message' => ' Wrong tower. ', 'place' => 'place_home']), 'order_delivery');
    $data     = $response->getData(true);
    $message  = ChatMessage::query()->first();
    $stored   = Fleetbase\FleetOps\Models\Order::where('public_id', 'order_delivery')->first()->getMeta('address_change_request');

    expect($response->getStatusCode())->toBe(200)
        ->and($data['status'])->toBe('ok')
        ->and($data['address_change_request'])->toBe($stored)
        ->and($stored['status'])->toBe('open')
        ->and($stored['message'])->toBe('Wrong tower.')
        ->and($stored['place'])->toBe('place_home')
        ->and($stored['address'])->toBe('HOME - 12 ORCHARD ROAD, SINGAPORE')
        ->and($stored['at'])->toBe('2026-10-14T11:00:00+00:00')
        ->and($message->content)->toBe('The address is wrong. Wrong tower. New address: HOME - 12 ORCHARD ROAD, SINGAPORE')
        ->and($message->meta)->toBe(['kind' => 'address_change_requested'])
        ->and(OrderChat::kindOf($message->chatChannel))->toBe(OrderChat::KIND_CUSTOMER)
        ->and($message->sender->user_uuid)->toBe('customer_user_uuid')
        ->and($this->notifications->sentTo('staff_user_uuid', ChatMessageReceived::class))->toHaveCount(1);
});

test('a place can be named by its uuid, and the message is optional', function () {
    $response = (new OrderChatController())->requestAddressChange(addressChangeRequest(['place' => 'place_home_uuid']), 'order_delivery');
    $stored   = $response->getData(true)['address_change_request'];

    expect($stored['message'])->toBeNull()
        ->and($stored['place'])->toBe('place_home')
        // Without a message the two sentences are joined by two spaces.
        ->and(ChatMessage::query()->value('content'))->toBe('The address is wrong.  New address: HOME - 12 ORCHARD ROAD, SINGAPORE');
});

test('a request without a place still reaches the store, without a message kind on older chat tables', function () {
    addressChangeSetup(false);

    $response = (new OrderChatController())->requestAddressChange(addressChangeRequest(), 'order_delivery');
    $message  = orderOpsCoverageDb()->table('chat_messages')->first();

    expect($response->getData(true)['address_change_request'])->toMatchArray(['status' => 'open', 'message' => null, 'place' => null, 'address' => null])
        ->and($message->content)->toBe('The address is wrong.')
        ->and(property_exists($message, 'meta'))->toBeFalse();
});

test('the address of a pickup or finished order no longer changes', function () {
    $controller = new OrderChatController();
    $pickup     = $controller->requestAddressChange(addressChangeRequest(['message' => 'Wrong tower']), 'order_pickup');
    // A finished order with no store thread has nothing to write in.
    $finished = $controller->requestAddressChange(addressChangeRequest(), 'order_finished');
    OrderChat::openStore(Fleetbase\FleetOps\Models\Order::where('public_id', 'order_finished')->with(['customer'])->first());
    $finishedWithThread = $controller->requestAddressChange(addressChangeRequest(), 'order_finished');

    expect($pickup->getStatusCode())->toBe(422)
        ->and($pickup->getData(true))->toBe(['error' => 'The address of this order can no longer change.'])
        ->and($finished->getStatusCode())->toBe(409)
        ->and($finished->getData(true))->toBe(['error' => 'This order has no chat with the store.', 'reason' => 'chat_unavailable'])
        ->and($finishedWithThread->getStatusCode())->toBe(422)
        ->and(ChatMessage::query()->count())->toBe(0)
        ->and(Fleetbase\FleetOps\Models\Order::where('public_id', 'order_pickup')->first()->getMeta('address_change_request'))->toBeNull();
});

test('the customer must be signed in and name one of their own places', function () {
    $controller = new OrderChatController();
    $signedOut  = $controller->requestAddressChange(addressChangeRequest([], null), 'order_delivery');
    $notMine    = $controller->requestAddressChange(addressChangeRequest(['place' => 'place_other']), 'order_delivery');

    expect($signedOut->getStatusCode())->toBe(401)
        ->and($notMine->getStatusCode())->toBe(404)
        ->and($notMine->getData(true))->toBe(['error' => 'Place not found.'])
        ->and(Fleetbase\FleetOps\Models\Order::where('public_id', 'order_delivery')->first()->getMeta('address_change_request'))->toBeNull()
        ->and(ChatChannel::query()->count())->toBe(1);

    expect(fn () => $controller->requestAddressChange(addressChangeRequest(['message' => str_repeat('a', 1001)]), 'order_delivery'))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});
