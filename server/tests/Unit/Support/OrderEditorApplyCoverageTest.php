<?php

require_once __DIR__ . '/../../Support/OrderEditorTestHelpers.php';

use Fleetbase\FleetOps\Http\Resources\v1\ServiceQuote as ServiceQuoteResource;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\Storefront\Notifications\StorefrontOrderUpdated;
use Fleetbase\Storefront\Promotions\PromotionEngine;
use Fleetbase\Storefront\Promotions\PromotionResult;
use Fleetbase\Storefront\Support\OrderEditException;
use Fleetbase\Storefront\Support\OrderEditor;
use Illuminate\Contracts\Notifications\Dispatcher as NotificationDispatcher;

beforeEach(fn () => orderEditorCoverageSetUp());
afterEach(fn () => orderEditorCoverageTearDown());

class OrderEditorCoverageProbe extends OrderEditor
{
    public static function writeContactFor(Order $order, array $contact): void
    {
        static::writeContact($order, $contact);
    }
}

/** A cash order whose driver still collects its total. */
function orderEditorApplyCashOrder(array $order = [], array $meta = [], array $payload = [], ?array $entities = null): Order
{
    return orderEditorCoverageOrder($order, array_merge(['gateway' => 'cash'], $meta), array_merge(['cod_amount' => 2000, 'cod_payment_method' => 'cash'], $payload), $entities);
}

function orderEditorApplyEntities(): array
{
    return Entity::where('payload_uuid', 'payload_uuid')->orderBy('id')->get()->map(fn ($entity) => [$entity->public_id, $entity->name, $entity->meta])->all();
}

function orderEditorApplyFresh(): Order
{
    return Order::where('uuid', 'order_uuid')->first();
}

function orderEditorApplyFailure(Order $order, array $input): OrderEditException
{
    try {
        OrderEditor::apply($order, $input);
    } catch (OrderEditException $exception) {
        return $exception;
    }

    throw new RuntimeException('The edit was applied.');
}

test('an edit the stage does not allow is refused before anything moves', function () {
    $failure = orderEditorApplyFailure(orderEditorCoverageOrder(['status' => 'driver_enroute']), ['items' => [['entity' => 'entity_tea', 'quantity' => 1], ['entity' => 'entity_cake']]]);

    expect($failure->getMessage())->toBe('The driver has the order.')
        ->and($failure->status())->toBe(422)
        ->and($failure->context)->toBe(['violations' => ['items' => 'The driver has the order.']])
        ->and(orderEditorApplyEntities()[0][2]['quantity'])->toBe(2)
        ->and(orderEditorApplyFresh()->getMeta('amount_paid'))->toBeNull();
});

test('an edit that changes nothing is refused', function () {
    $failure = orderEditorApplyFailure(orderEditorCoverageOrder(['notes' => 'Leave at door']), ['notes' => 'Leave at door']);

    expect($failure->getMessage())->toBe('Nothing changed.')
        ->and($failure->status())->toBe(422);
});

test('a total that moved since the preview has to be confirmed again', function () {
    $failure = orderEditorApplyFailure(orderEditorCoverageOrder(), ['items' => [['entity' => 'entity_tea', 'quantity' => 3], ['entity' => 'entity_cake']], 'confirm_total' => '2000']);

    expect($failure->getMessage())->toBe('The new total is 2500, not 2000. Review the change and confirm again.')
        ->and($failure->status())->toBe(409)
        ->and($failure->context['proposed']['total'])->toBe(2500)
        ->and(orderEditorApplyEntities()[0][2]['quantity'])->toBe(2);
});

test('changing the items rewrites the order, lowers the cash to collect and tells the driver and the customer', function () {
    $order = orderEditorApplyCashOrder(['status' => 'preparing']);
    orderEditorCoverageDb()->table('users')->insert(['uuid' => 'user_uuid', 'name' => 'Grace Hopper']);
    session(['user' => 'user_uuid']);
    $notifications = app(NotificationDispatcher::class);

    $result = OrderEditor::apply($order, [
        'items' => [
            ['entity' => 'entity_tea', 'quantity' => 1, 'note' => 'No sugar'],
            ['product' => 'product_bun', 'quantity' => 2, 'substitutes' => 'entity_cake'],
        ],
        'confirm_total' => 1300,
    ]);

    expect($result['changes'])->toBe(['items', 'total', 'cod_amount'])
        ->and($result['summary'])->toBe('Tea 2→1 · Tea note updated · Cake replaced with Bun ×2')
        ->and($result['settlement'])->toMatchArray(['type' => 'cod_update', 'amount' => -700, 'gateway' => 'cash', 'by' => 'user_uuid']);

    // The kept line is updated, the substitute added in its place, the replaced line removed.
    $entities = orderEditorApplyEntities();
    expect($entities)->toHaveCount(2)
        ->and($entities[0][0])->toBe('entity_tea')
        ->and($entities[0][2])->toMatchArray(['product_id' => 'product_tea', 'quantity' => 1, 'subtotal' => 500, 'note' => 'No sugar'])
        ->and($entities[1][1])->toBe('Bun')
        ->and($entities[1][0])->toStartWith('entity_')
        ->and($entities[1][2])->toMatchArray(['product_id' => 'product_bun', 'quantity' => 2, 'subtotal' => 600, 'substitute_for' => 'entity_cake', 'added_by_edit' => true])
        ->and(Entity::withTrashed()->where('public_id', 'entity_cake')->first()->trashed())->toBeTrue()
        ->and(Entity::where('public_id', $entities[1][0])->value('customer_uuid'))->toBe('contact_uuid');

    // The order carries the new prices, the kitchen is told, and the edit is logged.
    $fresh = orderEditorApplyFresh();
    expect($fresh->getMeta())->toMatchArray(['subtotal' => 1100, 'delivery_fee' => 200, 'discount' => 0, 'promotions' => [], 'total' => 1300, 'kitchen_changed_at' => '2026-10-12T10:00:00+00:00'])
        ->and($fresh->getMeta('edits'))->toBe([[
            'at'         => '2026-10-12T10:00:00+00:00',
            'by'         => 'user_uuid',
            'by_name'    => 'Grace Hopper',
            'summary'    => 'Tea 2→1 · Tea note updated · Cake replaced with Bun ×2',
            'changes'    => ['items', 'total', 'cod_amount'],
            'difference' => -700,
        ]])
        ->and($fresh->getMeta('payment'))->toMatchArray(['state' => 'to_collect', 'cod_amount' => 1300, 'total' => 1300])
        ->and((int) orderEditorCoverageDb()->table('payloads')->value('cod_amount'))->toBe(1300);

    // The returned order is reloaded with what the console shows.
    expect($result['order']->relationLoaded('payload'))->toBeTrue()
        ->and($result['order']->payload->entities)->toHaveCount(2)
        ->and($result['order']->relationLoaded('driverAssigned'))->toBeTrue();

    expect($notifications->sent)->toHaveCount(1)
        ->and($notifications->sent[0][0]->uuid)->toBe('contact_uuid')
        ->and($notifications->sent[0][1])->toBeInstanceOf(StorefrontOrderUpdated::class)
        ->and($notifications->sent[0][1]->body)->toBe('Tea 2→1 · Tea note updated · Cake replaced with Bun ×2.');

    if (($broadcasts = orderEditorCoverageBroadcasts()) !== null) {
        expect($broadcasts)->toBe([[
            'order'         => 'order_public',
            'changes'       => ['items', 'total', 'cod_amount'],
            'summary'       => 'Tea 2→1 · Tea note updated · Cake replaced with Bun ×2',
            'extra'         => ['total' => 1300, 'currency' => 'USD', 'changed_by' => 'Grace Hopper (store)'],
            'notify_driver' => true,
        ]]);
    }
});

test('a lower card total can go back as store credit, without telling anyone when asked not to', function () {
    $notifications = app(NotificationDispatcher::class);

    $result = OrderEditor::apply(orderEditorCoverageOrder(), [
        'items'           => [['entity' => 'entity_tea']],
        'settlement'      => 'store_credit',
        'notify_customer' => false,
        'notify_driver'   => false,
    ]);

    expect($result['settlement'])->toMatchArray(['type' => 'store_credit', 'amount' => 800, 'gateway' => 'store_credit', 'reason' => 'Removed Cake'])
        ->and($result['settlement']['reference'])->toStartWith('CREDIT-')
        ->and(orderEditorCoverageDb()->table('promotion_codes')->where('code', $result['settlement']['reference'])->value('customer_uuid'))->toBe('contact_uuid')
        ->and(orderEditorApplyFresh()->getMeta())->toMatchArray(['amount_paid' => 2000, 'total' => 1200])
        ->and(orderEditorApplyFresh()->getMeta('payment.state'))->toBe('partially_refunded')
        ->and($notifications->sent)->toBe([]);

    if (($broadcasts = orderEditorCoverageBroadcasts()) !== null) {
        expect($broadcasts[0]['notify_driver'])->toBeFalse()
            ->and($broadcasts[0]['extra']['changed_by'])->toBeNull();
    }
});

test('a refund the gateway cannot make leaves the order as it was', function () {
    // An unknown settlement falls back to what the order's payment allows: a Stripe refund.
    $failure = orderEditorApplyFailure(orderEditorCoverageOrder(), ['items' => [['entity' => 'entity_tea']], 'settlement' => 'teleport']);

    expect($failure->getMessage())->toBe('This order has no Stripe payment to refund.')
        ->and($failure->status())->toBe(422)
        ->and(orderEditorApplyEntities())->toHaveCount(2)
        ->and(orderEditorApplyFresh()->getMeta('total'))->toBe(2000);
});

test('a higher card total the driver collects is a balance due', function () {
    $notifications = app(NotificationDispatcher::class);

    $result = OrderEditor::apply(orderEditorCoverageOrder(), ['items' => [['entity' => 'entity_tea', 'quantity' => 3], ['entity' => 'entity_cake']], 'settlement' => 'collect']);

    expect($result['settlement'])->toMatchArray(['type' => 'cod_update', 'amount' => 500])
        ->and(orderEditorApplyFresh()->getMeta())->toMatchArray(['balance_due' => 500, 'total' => 2500])
        ->and((int) orderEditorCoverageDb()->table('payloads')->value('cod_amount'))->toBe(500)
        ->and($notifications->sent[0][1]->body)->toBe('Tea 2→3. There is a balance to pay.');
});

test('a typed drop-off is saved for the customer, quoted, and closes their address request', function () {
    $connection = orderEditorCoverageDb();
    // MySQL keeps a point as its SRID then little-endian WKB (x = longitude). Registered for
    // each arity, replacing any version an earlier test left on the shared connection.
    $toWkb = function ($wkt, $srid = 0) {
        preg_match('/POINT\(([-\d.]+) ([-\d.]+)\)/', (string) $wkt, $match);

        return pack('L', (int) $srid) . pack('CLdd', 1, 1, (float) $match[1], (float) $match[2]);
    };
    foreach ([1, 2, 3] as $arguments) {
        $connection->getPdo()->sqliteCreateFunction('ST_GeomFromText', $toWkb, $arguments);
    }
    app()->instance('geocoder', new class {
        public function geocode(string $address): self
        {
            return $this;
        }

        public function get(): Illuminate\Support\Collection
        {
            return collect();
        }
    });
    Geocoder\Laravel\Facades\Geocoder::clearResolvedInstance('geocoder');
    $quote = new ServiceQuote();
    $quote->forceFill(['amount' => 450]);
    OrderEditorCoverageQuoteController::$response = new ServiceQuoteResource($quote);

    $order  = orderEditorCoverageOrder([], ['address_change_request' => ['status' => 'open', 'note' => 'We moved', 'opened_at' => '2026-10-12T09:00:00+00:00']]);
    $result = OrderEditor::apply($order, [
        'dropoff'                => ['street1' => '22 Harbour Front', 'instructions' => 'Lobby desk', 'location' => ['type' => 'Point', 'coordinates' => [103.85, 1.28]]],
        'save_place_to_customer' => true,
        'settlement'             => 'collect',
    ]);

    $place = Place::where('street1', '22 Harbour Front')->first();
    expect($place)->not->toBeNull()
        ->and($place->owner_uuid)->toBe('contact_uuid')
        ->and($place->owner_type)->toBe(Fleetbase\Support\Utils::getMutationType('fleet-ops:contact'))
        ->and($place->getMeta('instructions'))->toBe('Lobby desk')
        ->and($connection->table('payloads')->value('dropoff_uuid'))->toBe($place->uuid)
        ->and(OrderEditorCoverageQuoteController::$requests[0]['destination'])->toBe($place->public_id)
        ->and($result['changes'])->toBe(['dropoff', 'total'])
        ->and(orderEditorApplyFresh()->getMeta())->toMatchArray(['delivery_fee' => 450, 'total' => 2250, 'balance_due' => 250])
        ->and(orderEditorApplyFresh()->getMeta('address_change_request'))->toBe(['status' => 'resolved', 'note' => 'We moved', 'opened_at' => '2026-10-12T09:00:00+00:00', 'closed_at' => '2026-10-12T10:00:00+00:00']);

    unset(app()['geocoder']);
});

test('a saved drop-off leaves an address request that is not open alone', function () {
    $order = orderEditorApplyCashOrder([], ['address_change_request' => ['status' => 'declined']]);

    OrderEditor::apply($order, ['dropoff' => ['place' => 'place_office']]);

    expect(orderEditorCoverageDb()->table('payloads')->value('dropoff_uuid'))->toBe('office_uuid')
        ->and(orderEditorApplyFresh()->getMeta('address_change_request'))->toBe(['status' => 'declined']);
});

test('new contact details go on the profile when asked, else on the order', function () {
    OrderEditor::apply(orderEditorCoverageOrder(), ['contact' => ['name' => 'Ada King', 'phone' => '', 'scope' => 'profile']]);

    expect(orderEditorCoverageDb()->table('contacts')->where('uuid', 'contact_uuid')->first())->toMatchArray(['name' => 'Ada King', 'phone' => '+6590000000'])
        ->and(orderEditorApplyFresh()->getMeta('contact'))->toBeNull();

    orderEditorCoverageSchema();
    OrderEditor::apply(orderEditorCoverageOrder([], ['contact' => ['name' => 'Ada', 'email' => 'old@example.com']]), ['contact' => ['email' => 'new@example.com', 'phone' => '+6591111111']]);

    expect(orderEditorApplyFresh()->getMeta('contact'))->toBe(['name' => 'Ada', 'email' => 'new@example.com', 'phone' => '+6591111111'])
        ->and(orderEditorCoverageDb()->table('contacts')->value('email'))->toBe('ada@example.com');

    // An order without a customer keeps profile changes on the order.
    orderEditorCoverageSchema();
    $notifications = orderEditorCoverageNotifications();
    OrderEditor::apply(orderEditorCoverageOrder(['customer_uuid' => null, 'customer_type' => null]), ['contact' => ['name' => 'Walk-in', 'scope' => 'profile']]);

    expect(orderEditorApplyFresh()->getMeta('contact'))->toBe(['name' => 'Walk-in'])
        ->and($notifications->sent)->toBe([]);
});

test('contact details with nothing in them change nothing', function () {
    $order = orderEditorCoverageOrder();

    OrderEditorCoverageProbe::writeContactFor($order, ['name' => '', 'phone' => null, 'scope' => 'profile']);

    expect($order->getMeta('contact'))->toBeNull()
        ->and(orderEditorCoverageDb()->table('contacts')->value('name'))->toBe('Ada Lovelace');
});

test('a new time and notes are written to the order without touching its prices', function () {
    $result = OrderEditor::apply(orderEditorCoverageOrder(['notes' => 'Leave at door']), ['scheduled_at' => '2026-10-13T06:30:00+00:00', 'notes' => ' Call on arrival ']);

    $fresh = orderEditorApplyFresh();
    expect($result['changes'])->toBe(['schedule', 'notes'])
        ->and($result['settlement'])->toBeNull()
        ->and($fresh->scheduled_at->toIso8601String())->toBe('2026-10-13T06:30:00+00:00')
        ->and($fresh->notes)->toBe('Call on arrival')
        ->and($fresh->getMeta())->toMatchArray(['subtotal' => 1800, 'total' => 2000])
        ->and($fresh->getMeta('kitchen_changed_at'))->toBeNull()
        ->and(app(PromotionEngine::class)->calls)->toBe([]);
});

/** A booking of a service at 09:00 store time on Tuesday 13 Oct; slots follow the service length. */
function orderEditorApplyBooking(array $booking, int $serviceMinutes, ?array $entityMeta = null): Order
{
    $order = orderEditorApplyCashOrder([], ['is_booking' => true, 'subtotal' => 6000, 'delivery_fee' => 0, 'total' => 6000, 'booking' => $booking], ['cod_amount' => 6000], [
        ['public_id' => 'entity_clean', 'name' => 'Deep Clean', 'meta' => $entityMeta ?? ['product_id' => 'product_clean', 'quantity' => 1, 'subtotal' => 6000, 'scheduled_at' => '2026-10-13T01:00:00+00:00']],
    ]);
    orderEditorCoverageDb()->table('products')->insert(['uuid' => 'clean_uuid', 'public_id' => 'product_clean', 'company_uuid' => 'company_uuid', 'store_uuid' => 'store_uuid', 'name' => 'Deep Clean', 'currency' => 'USD', 'price' => 6000, 'is_bookable' => true, 'meta' => json_encode(['service' => ['duration_minutes' => $serviceMinutes]])]);
    orderEditorCoverageDb()->table('product_hours')->insert(['uuid' => 'clean_hours', 'product_uuid' => 'clean_uuid', 'day_of_week' => 'tuesday', 'start' => '09:00:00', 'end' => '12:00:00']);

    return $order;
}

test('moving a booking keeps its length and moves its service line', function () {
    OrderEditor::apply(orderEditorApplyBooking(['start' => '2026-10-13T01:00:00+00:00', 'duration_minutes' => 90], 60), ['scheduled_at' => '2026-10-13T02:00:00+00:00']);

    $fresh = orderEditorApplyFresh();
    expect($fresh->getMeta('booking'))->toBe(['start' => '2026-10-13T02:00:00+00:00', 'end' => '2026-10-13T03:30:00+00:00', 'duration_minutes' => 90])
        ->and($fresh->getMeta('booking_at'))->toBe('2026-10-13T02:00:00+00:00')
        ->and($fresh->scheduled_at)->toBeNull()
        ->and(orderEditorApplyEntities()[0][2]['scheduled_at'])->toBe('2026-10-13T02:00:00+00:00')
        ->and(app(NotificationDispatcher::class)->sent[0][1]->subject)->toBe('Your booking with Corner Bakery was moved');
});

test('a booking without a length takes its service length, or an hour without a service', function () {
    OrderEditor::apply(orderEditorApplyBooking(['start' => '2026-10-13T01:00:00+00:00'], 45), ['scheduled_at' => '2026-10-13T01:45:00+00:00']);

    expect(orderEditorApplyFresh()->getMeta('booking'))->toBe(['start' => '2026-10-13T01:45:00+00:00', 'end' => '2026-10-13T02:30:00+00:00', 'duration_minutes' => 45]);

    orderEditorCoverageSchema();
    OrderEditor::apply(orderEditorApplyBooking(['start' => '2026-10-13T01:00:00+00:00'], 45, ['quantity' => 1, 'subtotal' => 6000]), ['scheduled_at' => '2026-10-14T03:00:00+00:00']);

    expect(orderEditorApplyFresh()->getMeta('booking'))->toBe(['start' => '2026-10-14T03:00:00+00:00', 'end' => '2026-10-14T04:00:00+00:00', 'duration_minutes' => 60])
        ->and(orderEditorApplyEntities()[0][2])->not->toHaveKey('scheduled_at');
});

test("the order's promotion uses follow the new prices, and a promotion that no longer applies is released", function () {
    $order = orderEditorApplyCashOrder([], ['discount' => 300, 'total' => 1700, 'promotions' => [['code' => 'SAVE'], ['code' => 'SHIP']]], ['cod_amount' => 1700]);
    orderEditorCoverageDb()->table('promotion_redemptions')->insert([
        ['uuid' => 'save_use', 'promotion_uuid' => 'save_uuid', 'order_uuid' => 'order_uuid', 'amount' => 300, 'status' => 'redeemed'],
        ['uuid' => 'ship_use', 'promotion_uuid' => 'ship_uuid', 'order_uuid' => 'order_uuid', 'amount' => 200, 'status' => 'redeemed'],
        ['uuid' => 'held_use', 'promotion_uuid' => 'ship_uuid', 'order_uuid' => 'order_uuid', 'amount' => 200, 'status' => 'reserved'],
        ['uuid' => 'other_use', 'promotion_uuid' => 'ship_uuid', 'order_uuid' => 'other_order', 'amount' => 200, 'status' => 'redeemed'],
    ]);
    app(PromotionEngine::class)->result = new PromotionResult([['promotion_uuid' => 'save_uuid', 'promotion_id' => 'promotion_save', 'code' => 'SAVE', 'amount' => 230, 'delivery_amount' => 20]]);

    OrderEditor::apply($order, ['items' => [['entity' => 'entity_tea', 'quantity' => 3], ['entity' => 'entity_cake']]]);

    expect(orderEditorCoverageDb()->table('promotion_redemptions')->orderBy('id')->get(['uuid', 'amount', 'status'])->map(fn ($row) => (array) $row)->all())->toBe([
        ['uuid' => 'save_use', 'amount' => 250, 'status' => 'redeemed'],
        ['uuid' => 'ship_use', 'amount' => 200, 'status' => 'released'],
        ['uuid' => 'held_use', 'amount' => 200, 'status' => 'reserved'],
        ['uuid' => 'other_use', 'amount' => 200, 'status' => 'redeemed'],
    ])
        ->and(orderEditorApplyFresh()->getMeta())->toMatchArray(['discount' => 250, 'total' => 2250]);
});

test('stock follows the edit: removed units go back on the shelf', function () {
    $order = orderEditorApplyCashOrder();
    orderEditorCoverageStock(5);

    OrderEditor::apply($order, ['items' => [['entity' => 'entity_tea', 'quantity' => 1], ['entity' => 'entity_cake']]]);

    expect(orderEditorCoverageDb()->table('product_inventories')->value('quantity'))->toBe(6)
        ->and(orderEditorCoverageDb()->table('inventory_movements')->where('type', 'edit')->get(['quantity', 'quantity_after', 'reference_uuid'])->map(fn ($row) => (array) $row)->all())
        ->toBe([['quantity' => 1, 'quantity_after' => 6, 'reference_uuid' => 'order_uuid']]);
});

test('a customer who cannot be notified does not undo the edit', function () {
    orderEditorCoverageNotifications(new RuntimeException('Mail is down'));

    $result = OrderEditor::apply(orderEditorApplyCashOrder(), ['notes' => 'Gate code 1234']);

    expect($result['changes'])->toBe(['notes'])
        ->and(orderEditorApplyFresh()->notes)->toBe('Gate code 1234')
        ->and($GLOBALS['orderEditorCoverageReported'])->toHaveCount(1)
        ->and($GLOBALS['orderEditorCoverageReported'][0]->getMessage())->toBe('Mail is down');
});
