<?php

require_once __DIR__ . '/../../Support/OrderOpsCoverageHelpers.php';

use Fleetbase\Models\ChatChannel;
use Fleetbase\Models\ChatParticipant;
use Fleetbase\Storefront\Support\OrderChat;

beforeEach(function () {
    orderOpsCoverageSchema();
    $this->notifications = orderOpsCoverageBoot();
});

afterEach(function () {
    orderOpsCoverageShutdown();
});

test('the store and driver thread needs a driver with an account', function () {
    $order = orderOpsCoverageOrder('order_unassigned', ['driver_assigned_uuid' => null]);

    expect(OrderChat::openDispatch($order, 'staff_user_uuid'))->toBeNull()
        ->and(ChatChannel::query()->count())->toBe(0);
});

test('the store and driver thread is started once, with roles, and moves to a reassigned driver', function () {
    $order   = orderOpsCoverageOrder('order_dispatch');
    $channel = OrderChat::openDispatch($order, 'staff_user_uuid');
    $again   = OrderChat::openDispatch($order, 'staff_user_uuid');

    $roles = fn () => ChatParticipant::where('chat_channel_uuid', $channel->uuid)->get()->mapWithKeys(fn ($participant) => [$participant->user_uuid => data_get($participant, 'meta.role')])->all();

    expect($channel->name)->toBe('Order order_dispatch · driver')
        ->and($channel->created_by_uuid)->toBe('staff_user_uuid')
        ->and($channel->meta)->toMatchArray([
            'storefront_order_uuid' => 'order_dispatch_uuid',
            'storefront_order_id'   => 'order_dispatch',
            'order_public_id'       => 'order_dispatch',
            'kind'                  => 'driver',
        ])
        ->and(OrderChat::kindOf($channel))->toBe(OrderChat::KIND_DISPATCH)
        ->and($again->uuid)->toBe($channel->uuid)
        ->and(ChatChannel::query()->count())->toBe(1)
        // The creator joined with the channel (no role recorded); the driver joined as the driver.
        ->and($roles())->toBe(['staff_user_uuid' => null, 'driver_user_uuid' => 'driver']);

    orderOpsCoverageDb()->table('orders')->where('public_id', 'order_dispatch')->update(['driver_assigned_uuid' => 'second_driver_uuid']);
    $reassigned = Fleetbase\FleetOps\Models\Order::where('public_id', 'order_dispatch')->with(['customer', 'driverAssigned'])->first();
    $moved      = OrderChat::openDispatch($reassigned, 'second_staff_user_uuid');

    expect($moved->uuid)->toBe($channel->uuid)
        ->and($roles())->toBe(['staff_user_uuid' => null, 'second_driver_user_uuid' => 'driver', 'second_staff_user_uuid' => 'store'])
        ->and(OrderChat::find($reassigned, OrderChat::KIND_DISPATCH)->uuid)->toBe($channel->uuid);
});

test('store staff are console users of the order company who are not drivers', function () {
    $channel = new ChatChannel();
    $channel->forceFill(['company_uuid' => 'company_uuid']);

    expect(OrderChat::isStaffUser($channel, 'staff_user_uuid'))->toBeTrue()
        ->and(OrderChat::isStaffUser($channel, 'driver_user_uuid'))->toBeFalse()
        ->and(OrderChat::isStaffUser($channel, 'customer_user_uuid'))->toBeFalse()
        ->and(OrderChat::isStaffUser($channel, 'outsider_user_uuid'))->toBeFalse()
        ->and(OrderChat::isStaffUser($channel, null))->toBeFalse()
        ->and(OrderChat::isDriverUser('driver_user_uuid'))->toBeTrue()
        ->and(OrderChat::isDriverUser('staff_user_uuid'))->toBeFalse()
        ->and(OrderChat::isDriverUser(null))->toBeFalse()
        ->and(OrderChat::isDriverUser(''))->toBeFalse();
});

test('a driver who was removed from the fleet no longer counts as a driver', function () {
    orderOpsCoverageDb()->table('drivers')->where('user_uuid', 'driver_user_uuid')->update(['deleted_at' => now()]);

    expect(OrderChat::isDriverUser('driver_user_uuid'))->toBeFalse()
        ->and(OrderChat::isDriverUser('second_driver_user_uuid'))->toBeTrue();
});

test('the store thread needs the customer or a staff member to take part', function () {
    $guestOrder = orderOpsCoverageOrder('order_guest', ['customer_uuid' => 'guest_contact_uuid']);

    expect(OrderChat::openStore($guestOrder))->toBeNull()
        ->and(ChatChannel::query()->count())->toBe(0);

    // A staff member can start it for a guest; the staff member is its creator.
    $staffThread = OrderChat::openStore($guestOrder, 'staff_user_uuid');

    expect($staffThread->created_by_uuid)->toBe('staff_user_uuid')
        ->and($staffThread->name)->toBe('Order order_guest · store')
        ->and(OrderChat::kindOf($staffThread))->toBe(OrderChat::KIND_CUSTOMER)
        ->and(ChatParticipant::where('chat_channel_uuid', $staffThread->uuid)->pluck('user_uuid')->all())->toBe(['staff_user_uuid']);
});

test('staff who write to the customer join the store thread as the store, and are never removed', function () {
    $order   = orderOpsCoverageOrder('order_store_thread');
    $channel = OrderChat::openStore($order, 'staff_user_uuid');
    OrderChat::openStore($order, 'second_staff_user_uuid');
    // The customer writing again changes nobody.
    OrderChat::openStore($order);
    // A staff member who is also the customer is not added twice.
    OrderChat::openStore($order, 'customer_user_uuid');

    $participants = ChatParticipant::where('chat_channel_uuid', $channel->uuid)->orderBy('id')->get();

    expect($channel->created_by_uuid)->toBe('customer_user_uuid')
        ->and($participants->pluck('user_uuid')->all())->toBe(['customer_user_uuid', 'staff_user_uuid', 'second_staff_user_uuid'])
        ->and($participants->map(fn ($participant) => data_get($participant, 'meta.role'))->all())->toBe([null, 'store', 'store'])
        ->and(OrderChat::roleOf($channel, $participants[1], 'customer_user_uuid'))->toBe(OrderChat::ROLE_STORE);
});

test('joining returns the existing participant and records roles only where the table can', function () {
    $order   = orderOpsCoverageOrder('order_join');
    $channel = OrderChat::openStore($order);

    $joined = OrderChat::join($channel, 'staff_user_uuid', OrderChat::ROLE_STORE);
    $same   = OrderChat::join($channel, 'staff_user_uuid', OrderChat::ROLE_DRIVER);

    expect($joined->company_uuid)->toBe('company_uuid')
        ->and($joined->chat_channel_uuid)->toBe($channel->uuid)
        ->and(data_get($joined, 'meta.role'))->toBe('store')
        ->and($same->uuid)->toBe($joined->uuid)
        ->and(data_get($same->fresh(), 'meta.role'))->toBe('store');

    // Before core-api 1.6.69 the participant table has no `meta`: the participant is added without a role.
    orderOpsCoverageSchema(false);
    orderOpsCoverageForgetMetaColumns();
    $legacyOrder   = orderOpsCoverageOrder('order_join_legacy');
    $legacyChannel = OrderChat::openStore($legacyOrder);
    $legacy        = OrderChat::join($legacyChannel, 'staff_user_uuid', OrderChat::ROLE_STORE);

    expect($legacy->exists)->toBeTrue()
        ->and(orderOpsCoverageDb()->table('chat_participants')->where('uuid', $legacy->uuid)->value('user_uuid'))->toBe('staff_user_uuid')
        ->and(OrderChat::hasMetaColumn('chat_participants'))->toBeFalse();
});

test('whether the chat tables have meta is looked up once per table', function () {
    expect(OrderChat::hasMetaColumn('chat_participants'))->toBeTrue()
        ->and(OrderChat::hasMetaColumn('chat_attachments'))->toBeFalse();

    // The answer is remembered: dropping the column does not change it until it is forgotten.
    orderOpsCoverageDb()->getSchemaBuilder()->drop('chat_participants');

    expect(OrderChat::hasMetaColumn('chat_participants'))->toBeTrue();

    orderOpsCoverageForgetMetaColumns();

    expect(OrderChat::hasMetaColumn('chat_participants'))->toBeFalse();
});

test('a schema that cannot be inspected counts as having no meta column', function () {
    $database = app('db');
    app()->instance('db', new class {
        public function connection($name = null)
        {
            throw new RuntimeException('database unavailable');
        }
    });
    Illuminate\Support\Facades\Schema::clearResolvedInstances();

    try {
        $hasMeta = OrderChat::hasMetaColumn('chat_messages');
    } finally {
        app()->instance('db', $database);
        Illuminate\Support\Facades\Schema::clearResolvedInstances();
    }

    expect($hasMeta)->toBeFalse();
});
