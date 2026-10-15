<?php

require_once __DIR__ . '/../../../Support/OrderOpsCoverageHelpers.php';

use Fleetbase\Models\ChatChannel;
use Fleetbase\Models\ChatParticipant;
use Fleetbase\Models\ChatReceipt;
use Fleetbase\Notifications\ChatMessageReceived;
use Fleetbase\Storefront\Http\Controllers\OrderChatController;
use Fleetbase\Storefront\Support\OrderChat;

function consoleOrderChatOrder(string $publicId): Fleetbase\FleetOps\Models\Order
{
    return Fleetbase\FleetOps\Models\Order::where('public_id', $publicId)->with(['customer', 'driverAssigned'])->first();
}

beforeEach(function () {
    orderOpsCoverageSchema();
    $this->notifications = orderOpsCoverageBoot();
    session(['user' => 'staff_user_uuid']);
    orderOpsCoverageRequest([], 'GET');
    orderOpsCoverageOrder('order_active');
    orderOpsCoverageOrder('order_unassigned', ['driver_assigned_uuid' => null]);
    orderOpsCoverageOrder('order_guest', ['customer_uuid' => 'guest_contact_uuid']);
    orderOpsCoverageOrder('order_done', ['status' => 'completed']);
    orderOpsCoverageOrder('order_other_company', ['company_uuid' => 'other_company_uuid']);
    $this->controller = new OrderChatController();
});

afterEach(function () {
    orderOpsCoverageShutdown();
});

test('every console chat action refuses an order that is not the company\'s', function () {
    $show      = $this->controller->show('order_missing', 'customer');
    $send      = $this->controller->send(orderOpsCoverageRequest(['content' => 'Hello']), 'order_other_company', 'customer');
    $read      = $this->controller->read('order_missing', 'customer');
    $dismissed = $this->controller->dismissAddressChange('order_other_company');

    expect($show->getStatusCode())->toBe(404)
        ->and($show->getData(true))->toBe(['error' => 'Order not found'])
        ->and($send->getStatusCode())->toBe(404)
        ->and($read->getData(true))->toBe(['read' => 0])
        ->and($dismissed->getStatusCode())->toBe(404)
        ->and(ChatChannel::query()->count())->toBe(0);
});

test('looking at a thread that was not started says why, and whether the store can start it', function () {
    $customer   = $this->controller->show('order_active', 'customer')->getData(true);
    $group      = $this->controller->show('order_active', 'group')->getData(true);
    $dispatch   = $this->controller->show('order_active', 'dispatch')->getData(true);
    $noDriver   = $this->controller->show('order_unassigned', 'driver')->getData(true);
    $noDispatch = $this->controller->show('order_unassigned', 'dispatch')->getData(true);
    $guest      = $this->controller->show('order_guest', 'driver')->getData(true);
    $finished   = $this->controller->show('order_done', 'customer')->getData(true);

    expect($customer)->toBe(['chat' => null, 'reason' => 'not_started', 'can_write' => true, 'quick_replies' => OrderChatController::DEFAULT_QUICK_REPLIES])
        ->and($group)->toBe(['chat' => null, 'reason' => 'not_started', 'can_write' => true, 'quick_replies' => OrderChatController::DEFAULT_QUICK_REPLIES])
        ->and($dispatch)->toBe(['chat' => null, 'reason' => 'not_started', 'can_write' => true, 'quick_replies' => OrderChatController::DEFAULT_DRIVER_REPLIES])
        ->and($noDriver)->toBe(['chat' => null, 'reason' => 'driver_not_assigned', 'can_write' => false, 'quick_replies' => []])
        ->and($noDispatch)->toBe(['chat' => null, 'reason' => 'driver_not_assigned', 'can_write' => false, 'quick_replies' => []])
        // A guest has no account to chat with the driver.
        ->and($guest['can_write'])->toBeFalse()
        ->and($finished)->toBe(['chat' => null, 'reason' => 'not_started', 'can_write' => false, 'quick_replies' => []])
        // Looking starts nothing.
        ->and(ChatChannel::query()->count())->toBe(0);
});

test('the store writes to the customer, who is notified, and sees the thread with what it has not read', function () {
    $sent    = $this->controller->send(orderOpsCoverageRequest(['content' => "  Your order is being prepared.  \n"]), 'order_active', 'customer')->getData(true)['message'];
    $order   = consoleOrderChatOrder('order_active');
    $channel = OrderChat::find($order, OrderChat::KIND_CUSTOMER);
    // The store's message is stamped with the real clock, so the reply is dated after it, not on a fixed day.
    orderOpsCoverageSay($channel, 'customer_user_uuid', 'Thanks! Can you add napkins?', now()->addMinute()->toDateTimeString());

    $shown = $this->controller->show('order_active', 'customer')->getData(true);

    expect($sent['content'])->toBe('Your order is being prepared.')
        ->and($sent['is_mine'])->toBeTrue()
        ->and($sent['sender']['role'])->toBe('store')
        ->and($sent['sender']['name'])->toBe('Sam Store')
        ->and($this->notifications->sentTo('customer_user_uuid', ChatMessageReceived::class))->toHaveCount(1)
        ->and($shown['can_write'])->toBeTrue()
        ->and($shown['quick_replies'])->toBe(OrderChatController::DEFAULT_QUICK_REPLIES)
        ->and($shown['chat']['kind'])->toBe('customer')
        ->and($shown['chat']['status'])->toBe('open')
        ->and($shown['chat']['me'])->toBe(OrderChat::participantFor($channel, 'staff_user_uuid')->public_id)
        ->and(collect($shown['chat']['messages'])->pluck('content')->all())->toBe(['Your order is being prepared.', 'Thanks! Can you add napkins?'])
        ->and(collect($shown['chat']['participants'])->pluck('role', 'name')->all())->toBe(['Mei Tan' => 'customer', 'Sam Store' => 'store'])
        ->and($shown['chat']['unread_count'])->toBe(1);
});

test('the store writes in the customer and driver thread and to the driver alone', function () {
    $group    = $this->controller->send(orderOpsCoverageRequest(['content' => 'Driver is 5 minutes away.']), 'order_active', 'group')->getData(true)['message'];
    $again    = $this->controller->send(orderOpsCoverageRequest(['content' => 'Almost there.']), 'order_active', 'driver')->getData(true)['message'];
    $dispatch = $this->controller->send(orderOpsCoverageRequest(['content' => 'The order is ready for pickup.']), 'order_active', 'dispatch')->getData(true)['message'];
    $order    = consoleOrderChatOrder('order_active');
    $thread   = OrderChat::find($order);

    expect($group['sender']['role'])->toBe('store')
        ->and($again['sender']['id'])->toBe($group['sender']['id'])
        ->and(ChatChannel::query()->count())->toBe(2)
        ->and(ChatParticipant::where('chat_channel_uuid', $thread->uuid)->pluck('user_uuid')->sort()->values()->all())->toBe(['customer_user_uuid', 'driver_user_uuid', 'staff_user_uuid'])
        ->and($dispatch['sender']['role'])->toBe('store')
        ->and(OrderChat::find($order, OrderChat::KIND_DISPATCH))->not->toBeNull()
        // The customer hears of the two messages in their thread, the driver of all three.
        ->and($this->notifications->sentTo('customer_user_uuid', ChatMessageReceived::class))->toHaveCount(2)
        ->and($this->notifications->sentTo('driver_user_uuid', ChatMessageReceived::class))->toHaveCount(3);
});

test('the store cannot write without text, after the order finished, or without someone to write to', function () {
    $closed   = $this->controller->send(orderOpsCoverageRequest(['content' => 'Thanks!']), 'order_done', 'customer');
    $noDriver = $this->controller->send(orderOpsCoverageRequest(['content' => 'Ready.']), 'order_unassigned', 'dispatch');
    $noGroup  = $this->controller->send(orderOpsCoverageRequest(['content' => 'Hi.']), 'order_guest', 'driver');
    session(['user' => null]);
    $noStore = $this->controller->send(orderOpsCoverageRequest(['content' => 'Hi.']), 'order_guest', 'customer');

    expect($closed->getStatusCode())->toBe(423)
        ->and($closed->getData(true))->toBe(['error' => 'This chat closed when the order finished.'])
        ->and($noDriver->getStatusCode())->toBe(409)
        ->and($noDriver->getData(true))->toBe(['error' => 'Assign a driver to message them.'])
        ->and($noGroup->getStatusCode())->toBe(409)
        ->and($noStore->getStatusCode())->toBe(409)
        ->and($noStore->getData(true))->toBe(['error' => 'This order has no chat with the store.'])
        ->and(ChatChannel::query()->count())->toBe(0);

    expect(fn () => $this->controller->send(orderOpsCoverageRequest(['content' => '']), 'order_active', 'customer'))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

test('a staff member who reads a thread joins it as the store and marks what they read', function () {
    expect($this->controller->read('order_active', 'customer')->getData(true))->toBe(['read' => 0]);

    $order   = consoleOrderChatOrder('order_active');
    $channel = OrderChat::openStore($order);
    $first   = orderOpsCoverageSay($channel, 'customer_user_uuid', 'Is it ready?', '2026-10-14 11:00:00');
    orderOpsCoverageSay($channel, 'customer_user_uuid', 'Hello?', '2026-10-14 11:05:00');

    session(['user' => null]);
    $nobody = $this->controller->read('order_active', 'customer')->getData(true);
    session(['user' => 'staff_user_uuid']);
    $read  = $this->controller->read('order_active', 'customer')->getData(true);
    $again = $this->controller->read('order_active', 'customer')->getData(true);
    $staff = OrderChat::participantFor($channel, 'staff_user_uuid');

    expect($nobody)->toBe(['read' => 0])
        ->and($read)->toBe(['read' => 2])
        ->and($again)->toBe(['read' => 0])
        ->and(data_get($staff, 'meta.role'))->toBe('store')
        ->and(ChatReceipt::where('chat_message_uuid', $first->uuid)->value('participant_uuid'))->toBe($staff->uuid)
        ->and(OrderChatController::unread($order))->toBe(['customer' => 0, 'dispatch' => 0, 'group' => 0]);
});

test('the store has read a message once any staff member has, whoever looks', function () {
    $order    = consoleOrderChatOrder('order_active');
    $store    = OrderChat::openStore($order, 'staff_user_uuid');
    $group    = OrderChat::open($order);
    $dispatch = OrderChat::openDispatch($order, 'staff_user_uuid');
    orderOpsCoverageSay($store, 'customer_user_uuid', 'Can I pick it up instead?', '2026-10-14 11:00:00');
    $read = orderOpsCoverageSay($store, 'customer_user_uuid', 'Never mind.', '2026-10-14 11:01:00');
    orderOpsCoverageSay($store, 'staff_user_uuid', 'Sure.', '2026-10-14 11:02:00');
    orderOpsCoverageSay($group, 'customer_user_uuid', 'Gate code 4412.', '2026-10-14 11:03:00');
    orderOpsCoverageSay($group, 'driver_user_uuid', 'Thanks.', '2026-10-14 11:04:00');
    orderOpsCoverageSay($dispatch, 'driver_user_uuid', 'At the store.', '2026-10-14 11:05:00');
    // The customer's own receipt does not count; a staff member's does.
    ChatReceipt::create(['company_uuid' => 'company_uuid', 'chat_message_uuid' => $read->uuid, 'participant_uuid' => OrderChat::participantFor($store, 'customer_user_uuid')->uuid]);
    $customerOnly = OrderChatController::unread($order);
    ChatReceipt::create(['company_uuid' => 'company_uuid', 'chat_message_uuid' => $read->uuid, 'participant_uuid' => OrderChat::participantFor($store, 'staff_user_uuid')->uuid]);

    expect($customerOnly)->toBe(['customer' => 2, 'dispatch' => 1, 'group' => 2])
        ->and(OrderChatController::unread($order))->toBe(['customer' => 1, 'dispatch' => 1, 'group' => 2])
        // A store owner who also drives has read what they sent.
        ->and(OrderChatController::unread($order, 'driver_user_uuid'))->toBe(['customer' => 1, 'dispatch' => 0, 'group' => 1])
        ->and(OrderChatController::unreadFromCustomer($order))->toBe(1)
        ->and($this->controller->show('order_active', 'group')->getData(true)['chat']['unread_count'])->toBe(2)
        ->and($this->controller->show('order_active', 'dispatch')->getData(true)['chat']['unread_count'])->toBe(1);
});

test('unread counts are zero without a thread, a customer account, or the customer in the thread', function () {
    $active = consoleOrderChatOrder('order_active');
    $guest  = consoleOrderChatOrder('order_guest');

    expect(OrderChatController::unreadFromCustomer($active))->toBe(0)
        ->and(OrderChatController::unread($active))->toBe(['customer' => 0, 'dispatch' => 0, 'group' => 0]);

    $guestThread = OrderChat::openStore($guest, 'staff_user_uuid');
    orderOpsCoverageSay($guestThread, 'staff_user_uuid', 'We will call you.', '2026-10-14 11:00:00');
    $thread = OrderChat::openStore($active, 'staff_user_uuid');
    orderOpsCoverageSay($thread, 'customer_user_uuid', 'Hello', '2026-10-14 11:00:00');
    OrderChat::participantFor($thread, 'customer_user_uuid')->delete();

    expect(OrderChatController::unreadFromCustomer($guest))->toBe(0)
        ->and(OrderChatController::unread($guest)['customer'])->toBe(0)
        ->and(OrderChatController::unreadFromCustomer($active))->toBe(0)
        ->and(OrderChatController::unread($active)['customer'])->toBe(0);
});

test('the store closes the customer\'s address request without changing the address', function () {
    orderOpsCoverageOrder('order_address', [], [
        'storefront_id'          => 'store_bloom',
        'address_change_request' => ['status' => 'open', 'message' => 'Wrong tower', 'at' => '2026-10-14T11:00:00+00:00'],
    ]);
    Illuminate\Support\Carbon::setTestNow('2026-10-14 11:30:00');

    $dismissed = $this->controller->dismissAddressChange('order_address')->getData(true);
    $nothing   = $this->controller->dismissAddressChange('order_active')->getData(true);

    expect($dismissed)->toBe(['status' => 'ok'])
        ->and(consoleOrderChatOrder('order_address')->getMeta('address_change_request'))->toBe([
            'status'    => 'dismissed',
            'message'   => 'Wrong tower',
            'at'        => '2026-10-14T11:00:00+00:00',
            'closed_at' => '2026-10-14T11:30:00+00:00',
        ])
        ->and($nothing)->toBe(['status' => 'ok'])
        ->and(consoleOrderChatOrder('order_active')->getMeta('address_change_request'))->toBeNull();
});
