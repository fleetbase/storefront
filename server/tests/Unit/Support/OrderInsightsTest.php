<?php

require_once __DIR__ . '/../../Support/OrderOpsCoverageHelpers.php';

use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\Storefront\Support\OrderChat;
use Fleetbase\Storefront\Support\OrderInsights;
use Illuminate\Support\Carbon;

// The installed Fleet-Ops may predate tracking page links; the insight only needs pageUrl().
if (!class_exists(Fleetbase\FleetOps\Support\TrackingCode::class)) {
    eval('namespace Fleetbase\FleetOps\Support; class TrackingCode { public static function pageUrl(?string $trackingNumber, ?string $companyUuid = null): string { return "https://console.test/~/track/" . rawurlencode((string) $trackingNumber); } }');
}

function orderInsightsOrder(array $attributes = [], array $meta = []): Order
{
    $order = new Order();
    $order->forceFill(array_merge([
        'uuid'         => 'order_insights_uuid',
        'public_id'    => 'order_insights',
        'company_uuid' => 'company_uuid',
        'status'       => 'dispatched',
        'meta'         => ['storefront_id' => 'store_bloom', 'gateway' => 'stripe', 'currency' => 'SGD', ...$meta],
    ], $attributes));

    return $order;
}

function orderInsightsEntity(string $name, bool $unavailable): Entity
{
    $entity = new Entity();
    $entity->forceFill(['name' => $name, 'meta' => $unavailable ? ['unavailable' => true] : []]);

    return $entity;
}

beforeEach(function () {
    orderOpsCoverageSchema();
    $this->notifications = orderOpsCoverageBoot();
    Carbon::setTestNow('2026-10-14 12:00:00');
});

afterEach(function () {
    orderOpsCoverageShutdown();
});

test('an order is due at its booking, its schedule, or when prepared and driven', function () {
    orderOpsCoverageDb()->table('stores')->where('public_id', 'store_bloom')->update(['options' => json_encode(['prep_minutes' => 25])]);

    $booked    = orderInsightsOrder([], ['booking_at' => '2026-10-14T15:00:00+00:00']);
    $slot      = orderInsightsOrder([], ['booking' => ['start' => '2026-10-14T16:30:00+00:00']]);
    $scheduled = orderInsightsOrder(['scheduled_at' => '2026-10-15 09:00:00']);
    $delivery  = orderInsightsOrder(['created_at' => '2026-10-14 11:00:00', 'time' => 841]);
    $pickup    = orderInsightsOrder(['created_at' => '2026-10-14 11:00:00', 'time' => 841], ['is_pickup' => true]);
    $unplaced  = orderInsightsOrder();

    expect(OrderInsights::dueAt($booked)->toIso8601String())->toBe('2026-10-14T15:00:00+00:00')
        ->and(OrderInsights::dueAt($slot)->toIso8601String())->toBe('2026-10-14T16:30:00+00:00')
        ->and(OrderInsights::dueAt($scheduled)->toIso8601String())->toBe('2026-10-15T09:00:00+00:00')
        // 25 minutes to prepare, a 841 second drive rounds up to 15 minutes.
        ->and(OrderInsights::dueAt($delivery)->toIso8601String())->toBe('2026-10-14T11:40:00+00:00')
        ->and(OrderInsights::dueAt($pickup)->toIso8601String())->toBe('2026-10-14T11:25:00+00:00')
        ->and(OrderInsights::dueAt($unplaced))->toBeNull();
});

test('a store that has not said how long it prepares takes the default', function () {
    $elsewhere = orderInsightsOrder([], ['storefront_id' => 'store_elsewhere']);

    expect(OrderInsights::prepMinutes(orderInsightsOrder()))->toBe(OrderInsights::DEFAULT_PREP_MINUTES)
        ->and(OrderInsights::prepMinutes($elsewhere))->toBe(20);

    orderOpsCoverageDb()->table('stores')->where('public_id', 'store_bloom')->update(['options' => json_encode(['prep_minutes' => -5])]);

    expect(OrderInsights::prepMinutes(orderInsightsOrder()))->toBe(20);
});

test('only an unfinished order past its due time is late', function () {
    $due = Carbon::parse('2026-10-14 11:15:00');

    expect(OrderInsights::lateMinutes(orderInsightsOrder(), $due))->toBe(45)
        ->and(OrderInsights::lateMinutes(orderInsightsOrder(), Carbon::parse('2026-10-14 12:30:00')))->toBe(0)
        ->and(OrderInsights::lateMinutes(orderInsightsOrder(), null))->toBe(0)
        ->and(OrderInsights::lateMinutes(orderInsightsOrder(['status' => 'completed']), $due))->toBe(0)
        ->and(OrderInsights::isFinished(orderInsightsOrder(['status' => 'order_canceled'])))->toBeTrue()
        ->and(OrderInsights::isFinished(orderInsightsOrder()))->toBeFalse();
});

test('alerts list what needs attention, most urgent first, and none once the order is finished', function () {
    $order = orderOpsCoverageOrder('order_alerts', ['created_at' => '2026-10-14 11:00:00'], [
        'storefront_id'          => 'store_bloom',
        'gateway'                => 'stripe',
        'balance_due'            => 450,
        'address_change_request' => ['status' => 'open', 'address' => '1 Orchard Rd', 'at' => '2026-10-14T11:30:00+00:00'],
    ]);
    $payload = new Payload();
    $payload->setRelation('entities', collect([orderInsightsEntity('Mango Lassi', true), orderInsightsEntity('Chai', false), orderInsightsEntity('Samosa', true)]));
    $order->setRelation('payload', $payload);

    $alerts = OrderInsights::alerts($order);

    expect(collect($alerts)->pluck('code')->all())->toBe(['address_change_requested', 'balance_due', 'item_unavailable', 'late'])
        ->and($alerts[0])->toBe([
            'code'    => 'address_change_requested',
            'message' => 'The customer asked to change the address.',
            'address' => '1 Orchard Rd',
            'at'      => '2026-10-14T11:30:00+00:00',
        ])
        ->and($alerts[1])->toBe(['code' => 'balance_due', 'message' => 'A balance is due after an edit.', 'amount' => 450])
        ->and($alerts[2])->toBe(['code' => 'item_unavailable', 'message' => 'Unavailable: Mango Lassi, Samosa', 'items' => ['Mango Lassi', 'Samosa']])
        // Placed at 11:00 and due at 11:20: 40 minutes late, and nobody has written to the customer.
        ->and($alerts[3])->toBe(['code' => 'late', 'message' => '40 min past due', 'minutes' => 40, 'told_at' => null]);

    $order->status = 'completed';

    expect(OrderInsights::alerts($order))->toBe([]);
});

test('an address request that was answered or dismissed raises no alert', function () {
    $dismissed = orderInsightsOrder([], ['address_change_request' => ['status' => 'dismissed', 'message' => 'Wrong tower']]);
    $open      = orderInsightsOrder([], ['address_change_request' => ['message' => 'Wrong tower']]);
    $garbled   = orderInsightsOrder([], ['address_change_request' => 'yes']);

    expect(OrderInsights::alerts($dismissed))->toBe([])
        ->and(OrderInsights::alerts($garbled))->toBe([])
        ->and(OrderInsights::alerts($open)[0])->toMatchArray(['code' => 'address_change_requested', 'message' => 'Wrong tower', 'address' => null, 'at' => null]);
});

test('a late order counts the customer as told once the store writes to them after it fell due', function () {
    $order   = orderOpsCoverageOrder('order_told', ['created_at' => '2026-10-14 11:00:00']);
    $channel = OrderChat::openStore($order, 'staff_user_uuid');
    $due     = Carbon::parse('2026-10-14 11:20:00');

    orderOpsCoverageSay($channel, 'staff_user_uuid', 'Your order is being prepared.', '2026-10-14 11:05:00');
    orderOpsCoverageSay($channel, 'customer_user_uuid', 'Where is my order?', '2026-10-14 11:40:00');

    expect(OrderInsights::customerToldAt($order, $due))->toBeNull()
        // Without a due time, any message from the store counts.
        ->and(OrderInsights::customerToldAt($order, null))->toBe('2026-10-14T11:05:00+00:00');

    orderOpsCoverageSay($channel, 'staff_user_uuid', 'Running 10 minutes late, sorry.', '2026-10-14 11:45:00');

    expect(OrderInsights::customerToldAt($order, $due))->toBe('2026-10-14T11:45:00+00:00')
        ->and(OrderInsights::alerts($order)[0])->toBe(['code' => 'late', 'message' => '40 min past due', 'minutes' => 40, 'told_at' => '2026-10-14T11:45:00+00:00']);
});

test('the customer is not counted as told without a store thread, or when it cannot be read', function () {
    $order = orderOpsCoverageOrder('order_untold');
    $guest = orderOpsCoverageOrder('order_untold_guest', ['customer_uuid' => 'guest_contact_uuid']);
    // A guest's thread is started by the store; every message in it is the store's.
    $guestThread = OrderChat::openStore($guest, 'staff_user_uuid');
    orderOpsCoverageSay($guestThread, 'staff_user_uuid', 'Ready in 5.', '2026-10-14 11:50:00');

    expect(OrderInsights::customerToldAt($order, null))->toBeNull()
        ->and(OrderInsights::customerToldAt($guest, Carbon::parse('2026-10-14 11:30:00')))->toBe('2026-10-14T11:50:00+00:00');

    orderOpsCoverageDb()->getSchemaBuilder()->drop('chat_channels');

    expect(OrderInsights::customerToldAt($order, null))->toBeNull();
});

test('the customer summary counts their orders with this store and shows their latest message', function () {
    orderOpsCoverageOrder('order_summary_old', ['status' => 'completed']);
    orderOpsCoverageOrder('order_summary_deleted', ['deleted_at' => '2026-10-01 00:00:00']);
    orderOpsCoverageOrder('order_summary_elsewhere', [], ['storefront_id' => 'store_elsewhere']);
    $order = orderOpsCoverageOrder('order_summary');

    $driverThread = OrderChat::open($order);
    $storeThread  = OrderChat::openStore($order, 'staff_user_uuid');
    orderOpsCoverageSay($storeThread, 'customer_user_uuid', 'Can you add napkins?', '2026-10-14 11:10:00');
    orderOpsCoverageSay($driverThread, 'customer_user_uuid', 'Tower B, please.', '2026-10-14 11:30:00');
    orderOpsCoverageSay($driverThread, 'driver_user_uuid', 'On my way.', '2026-10-14 11:35:00');

    $summary = OrderInsights::customerSummary($order);

    expect($summary)->toBe([
        'orders_count' => 2,
        'since'        => '2025-01-15T09:00:00+00:00',
        'last_message' => ['content' => 'Tower B, please.', 'at' => '2026-10-14T11:30:00+00:00', 'from' => 'Mei Tan'],
    ])
        ->and(OrderInsights::customerSummary(orderInsightsOrder(['customer_uuid' => null])))->toBeNull();

    // Without a store on the order, every order of the customer counts.
    $unscoped = orderInsightsOrder(['customer_uuid' => orderOpsCoverageCustomerUuid(), 'customer_type' => Fleetbase\FleetOps\Models\Contact::class], ['storefront_id' => null]);

    expect(OrderInsights::customerSummary($unscoped)['orders_count'])->toBe(3);
});

test('there is no latest customer message without an account, a thread, or a message', function () {
    $guest  = orderOpsCoverageOrder('order_message_guest', ['customer_uuid' => 'guest_contact_uuid']);
    $quiet  = orderOpsCoverageOrder('order_message_quiet');
    $silent = orderOpsCoverageOrder('order_message_silent');
    $thread = OrderChat::open($silent);
    orderOpsCoverageSay($thread, 'driver_user_uuid', 'Arriving now.', '2026-10-14 11:35:00');

    expect(OrderInsights::lastMessage($guest))->toBeNull()
        ->and(OrderInsights::lastMessage($quiet))->toBeNull()
        ->and(OrderInsights::lastMessage($silent))->toBeNull();

    orderOpsCoverageDb()->getSchemaBuilder()->drop('chat_channels');

    expect(OrderInsights::lastMessage($silent))->toBeNull();
});

test('a delivered order is finished when its tracking history says so', function () {
    orderOpsCoverageDb()->table('tracking_statuses')->insert([
        ['tracking_number_uuid' => 'tracking_uuid', 'code' => 'COMPLETED', 'created_at' => '2026-10-14 10:00:00', 'deleted_at' => null],
        ['tracking_number_uuid' => 'tracking_uuid', 'code' => 'picked_up', 'created_at' => '2026-10-14 10:30:00', 'deleted_at' => null],
        ['tracking_number_uuid' => 'tracking_uuid', 'code' => 'COMPLETED', 'created_at' => '2026-10-14 11:00:00', 'deleted_at' => '2026-10-14 11:05:00'],
        ['tracking_number_uuid' => 'tracking_uuid', 'code' => 'DISPATCHED', 'created_at' => '2026-10-14 11:30:00', 'deleted_at' => null],
        ['tracking_number_uuid' => 'other_tracking_uuid', 'code' => 'DISPATCHED', 'created_at' => '2026-10-14 09:00:00', 'deleted_at' => null],
    ]);

    expect(OrderInsights::finishedAt(orderInsightsOrder(['status' => 'completed', 'tracking_number_uuid' => 'tracking_uuid'])))->toBe('2026-10-14T10:30:00+00:00')
        ->and(OrderInsights::finishedAt(orderInsightsOrder(['status' => 'picked_up', 'tracking_number_uuid' => 'other_tracking_uuid'])))->toBeNull()
        ->and(OrderInsights::finishedAt(orderInsightsOrder(['status' => 'completed'])))->toBeNull()
        ->and(OrderInsights::finishedAt(orderInsightsOrder(['status' => 'dispatched', 'tracking_number_uuid' => 'tracking_uuid'])))->toBeNull();
});

test('the tracking link is the page for the order tracking number', function () {
    $tracked = orderInsightsOrder(['company_uuid' => null]);
    $tracked->setRelation('trackingNumber', (object) ['tracking_number' => 'TRK-1001']);
    $broken = orderInsightsOrder(['company_uuid' => null]);
    // A tracking number that is not a string cannot be linked.
    $broken->setRelation('trackingNumber', (object) ['tracking_number' => ['TRK-1001']]);

    expect(OrderInsights::trackingUrl($tracked))->toEndWith('/TRK-1001')
        ->and(OrderInsights::trackingUrl(orderInsightsOrder()))->toBeNull()
        ->and(OrderInsights::trackingUrl($broken))->toBeNull();
});

test('the insights of an order come together for the console', function () {
    $order = orderOpsCoverageOrder('order_all_insights', ['created_at' => '2026-10-14 11:50:00']);

    expect(OrderInsights::forOrder($order))->toBe([
        'due_at'           => '2026-10-14T12:10:00+00:00',
        'late_minutes'     => 0,
        'alerts'           => [],
        'customer_summary' => ['orders_count' => 1, 'since' => '2025-01-15T09:00:00+00:00', 'last_message' => null],
        'tracking_url'     => null,
    ]);
});
