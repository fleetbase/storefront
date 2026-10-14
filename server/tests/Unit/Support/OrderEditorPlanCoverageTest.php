<?php

require_once __DIR__ . '/../../Support/OrderEditorTestHelpers.php';

use Fleetbase\FleetOps\Http\Resources\v1\ServiceQuote as ServiceQuoteResource;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\Storefront\Models\Cart;
use Fleetbase\Storefront\Promotions\PromotionEngine;
use Fleetbase\Storefront\Promotions\PromotionResult;
use Fleetbase\Storefront\Support\OrderEditor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

beforeEach(fn () => orderEditorCoverageSetUp());
afterEach(fn () => orderEditorCoverageTearDown());

/** The quote the store's rates give for the new drop-off. */
function orderEditorPlanQuote(int $amount): ServiceQuote
{
    $quote = new ServiceQuote();
    $quote->forceFill(['uuid' => 'quote_uuid', 'public_id' => 'quote_public', 'amount' => $amount, 'currency' => 'USD']);

    return $quote;
}

function orderEditorPlanEngine(): OrderEditorCoveragePromotionEngine
{
    return app(PromotionEngine::class);
}

test('a preview prices the new items and leaves the order as it was', function () {
    $order   = orderEditorCoverageOrder();
    $preview = OrderEditor::preview($order, ['items' => [
        ['entity' => 'entity_tea', 'quantity' => 3, 'note' => ' less sugar '],
        ['entity'  => 'entity_cake', 'variants' => [['name' => 'Large', 'additional_cost' => 200], null], 'addons' => [['name' => 'Cream', 'price' => 100, 'is_on_sale' => true, 'sale_price' => 50]]],
        ['product' => 'product_bun', 'quantity' => 2],
    ]]);

    expect($preview['order'])->toMatchArray(['subtotal' => 1800, 'delivery_fee' => 200, 'total' => 2000, 'currency' => 'USD'])
        ->and($preview['proposed'])->toMatchArray(['subtotal' => 3150, 'delivery_fee' => 200, 'discount' => 0, 'total' => 3350, 'promotions' => [], 'promotions_lost' => []])
        ->and($preview['difference'])->toBe(1350)
        ->and($preview['changes'])->toBe(['items', 'total'])
        ->and($preview['summary'])->toBe('Tea 2→3 · Tea note updated · Cake options changed · Added Bun ×2')
        ->and($preview['settlement'])->toMatchArray(['action' => 'payment_link', 'amount' => 1350])
        ->and($preview['stage'])->toBe(OrderEditor::STAGE_OPEN)
        ->and($preview['locked'])->toBe([])
        ->and($preview['violations'])->toBe([])
        ->and($preview['warnings'])->toBe([]);

    // Each line as the console shows it, without the models behind it.
    expect($preview['proposed']['items'])->toBe([
        ['entity' => 'entity_tea', 'product' => 'product_tea', 'name' => 'Tea', 'quantity' => 3, 'variants' => [], 'addons' => [], 'note' => 'less sugar', 'subtotal' => 1500, 'substitutes' => null],
        ['entity' => 'entity_cake', 'product' => 'product_cake', 'name' => 'Cake', 'quantity' => 1, 'variants' => [['name' => 'Large', 'additional_cost' => 200]], 'addons' => [['name' => 'Cream', 'price' => 100, 'is_on_sale' => true, 'sale_price' => 50]], 'note' => null, 'subtotal' => 1050, 'substitutes' => null],
        ['entity' => null, 'product' => 'product_bun', 'name' => 'Bun', 'quantity' => 2, 'variants' => [], 'addons' => [], 'note' => null, 'subtotal' => 600, 'substitutes' => null],
    ]);

    // Promotions were asked about the new lines, leaving out this order's own use.
    $call = orderEditorPlanEngine()->calls[0];
    expect($call['codes'])->toBe([])
        ->and($call['context']->excludeOrderUuid)->toBe('order_uuid')
        ->and($call['context']->currency)->toBe('USD')
        ->and($call['context']->customer)->toBeInstanceOf(Contact::class)
        ->and($call['context']->deliveryFee)->toBe(200)
        ->and(array_map(fn ($line) => [$line->id, $line->subtotal, $line->quantity, $line->productId, $line->productUuid, $line->categoryUuid, $line->storeId], $call['context']->lines))->toBe([
            ['entity_tea', 1500, 3, 'product_tea', 'tea_uuid', 'drinks_uuid', 'store_public'],
            ['entity_cake', 1050, 1, 'product_cake', 'cake_uuid', 'bakes_uuid', 'store_public'],
            ['line_2', 600, 2, 'product_bun', 'bun_uuid', 'bakes_uuid', 'store_public'],
        ]);

    // Nothing was written.
    expect(json_decode(orderEditorCoverageDb()->table('entities')->where('public_id', 'entity_tea')->value('meta'), true)['quantity'])->toBe(2)
        ->and(orderEditorCoverageDb()->table('entities')->count())->toBe(2);
});

test('lines that drop to zero are removed, unknown products are refused, and a substitute replaces its item', function () {
    $order   = orderEditorCoverageOrder();
    $preview = OrderEditor::preview($order, ['items' => [
        ['entity' => 'entity_tea', 'quantity' => 0],
        ['entity'   => 'entity_missing', 'product' => 'product_bun', 'quantity' => 1, 'substitutes' => 'entity_cake'],
        ['product'  => 'product_foreign'],
        ['quantity' => 1],
    ]]);

    expect($preview['summary'])->toBe('Cake replaced with Bun ×1 · Removed Tea')
        ->and($preview['proposed']['items'])->toHaveCount(1)
        ->and($preview['proposed']['items'][0])->toMatchArray(['entity' => null, 'product' => 'product_bun', 'substitutes' => 'entity_cake', 'subtotal' => 300])
        ->and($preview['violations'])->toBe(['items' => 'A product on the order is no longer sold by this store.'])
        ->and($preview['proposed']['subtotal'])->toBe(300)
        ->and($preview['difference'])->toBe(-1500)
        ->and($preview['settlement'])->toMatchArray(['action' => 'refund', 'amount' => 1500]);
});

test('an order needs at least one item', function () {
    $preview = OrderEditor::preview(orderEditorCoverageOrder(), ['items' => []]);

    expect($preview['violations'])->toBe(['items' => 'An order needs at least one item; cancel it instead.'])
        ->and($preview['summary'])->toBe('Removed Tea · Removed Cake')
        ->and($preview['proposed']['subtotal'])->toBe(0)
        ->and($preview['proposed']['total'])->toBe(200);
});

test('a ready order can lose items but not gain them or change them', function () {
    $ready = fn () => orderEditorCoverageOrder(['status' => 'ready']);

    $fewer = OrderEditor::preview($ready(), ['items' => [['entity' => 'entity_tea', 'quantity' => 1]]]);
    expect($fewer['violations'])->toBe([])
        ->and($fewer['summary'])->toBe('Tea 2→1 · Removed Cake')
        ->and($fewer['stage'])->toBe(OrderEditor::STAGE_READY);

    orderEditorCoverageSchema();
    expect(OrderEditor::preview($ready(), ['items' => [['entity' => 'entity_tea', 'quantity' => 3], ['entity' => 'entity_cake']]])['violations'])
        ->toBe(['items' => 'The order is ready; items can only be removed.']);

    orderEditorCoverageSchema();
    expect(OrderEditor::preview($ready(), ['items' => [['entity' => 'entity_tea', 'addons' => [['price' => 50]]], ['entity' => 'entity_cake']]])['violations'])
        ->toBe(['items' => 'The order is ready; items can only be removed.']);

    orderEditorCoverageSchema();
    $added = OrderEditor::preview($ready(), ['items' => [['entity' => 'entity_tea'], ['entity' => 'entity_cake'], ['product' => 'product_bun']]]);
    expect($added['violations'])->toBe(['items' => 'The order is ready; items can only be removed.'])
        ->and($added['summary'])->toBe('Added Bun ×1');
});

test('items are locked once the driver has the order', function () {
    $preview = OrderEditor::preview(orderEditorCoverageOrder(['status' => 'driver_enroute']), ['items' => [['entity' => 'entity_tea', 'quantity' => 1], ['entity' => 'entity_cake']]]);

    expect($preview['stage'])->toBe(OrderEditor::STAGE_WITH_DRIVER)
        ->and($preview['violations'])->toBe(['items' => 'The driver has the order.'])
        ->and($preview['summary'])->toBe('Tea 2→1');
});

test('a new drop-off is quoted with the store rates and the promotions follow the new fee', function () {
    $order                                        = orderEditorCoverageOrder();
    OrderEditorCoverageQuoteController::$response = function ($request) {
        // The cart the quote is asked for holds the order's lines.
        $cart = Cart::where('public_id', $request->input('cart'))->first();
        expect(collect($cart->items)->pluck('quantity', 'product_id')->all())->toBe(['product_tea' => 2, 'product_cake' => 1])
            ->and(session('storefront_key'))->toBe('store_editor_key')
            ->and(session('storefront_store'))->toBe('store_uuid')
            ->and(session('customer_id'))->toBe('contact_uuid');

        return new ServiceQuoteResource(orderEditorPlanQuote(450));
    };

    $preview = OrderEditor::preview($order, ['dropoff' => ['place' => 'place_office']]);

    expect($preview['changes'])->toBe(['dropoff', 'total'])
        ->and($preview['summary'])->toBe('Drop-off changed to OFFICE - 9 OFFICE ROAD')
        ->and($preview['proposed'])->toMatchArray(['subtotal' => 1800, 'delivery_fee' => 450, 'total' => 2250])
        ->and($preview['difference'])->toBe(250)
        ->and($preview['warnings'])->toBe([])
        ->and(OrderEditorCoverageQuoteController::$requests[0])->toMatchArray(['origin' => 'place_bakery', 'destination' => 'place_office'])
        ->and(OrderEditorCoverageQuoteController::$requests[0]['cart'])->toStartWith('cart_');

    // The quoting cart is thrown away and the storefront session it needed is cleared.
    expect(Cart::query()->count())->toBe(0)
        ->and(session('storefront_key'))->toBeNull()
        ->and(session('storefront_store'))->toBeNull();

    // The order's own lines (no product models yet) are priced by the products they name.
    $context = orderEditorPlanEngine()->calls[0]['context'];
    expect($context->deliveryFee)->toBe(450)
        ->and(array_map(fn ($line) => [$line->id, $line->productUuid, $line->categoryUuid], $context->lines))->toBe([
            ['entity_tea', 'tea_uuid', 'drinks_uuid'],
            ['entity_cake', 'cake_uuid', 'bakes_uuid'],
        ]);
});

test('a quote that answers with several quotes takes the first', function () {
    OrderEditorCoverageQuoteController::$response = new JsonResource(collect([orderEditorPlanQuote(380), orderEditorPlanQuote(990)]));

    $preview = OrderEditor::preview(orderEditorCoverageOrder(), ['dropoff' => ['place' => 'office_uuid']]);

    expect($preview['proposed']['delivery_fee'])->toBe(380)
        ->and($preview['proposed']['total'])->toBe(2180);
});

test('when the new drop-off cannot be quoted the delivery fee stays and the store is told why', function ($response, string $warning) {
    OrderEditorCoverageQuoteController::$response = $response();

    $preview = OrderEditor::preview(orderEditorCoverageOrder(), ['dropoff' => ['place' => 'place_office']]);

    expect($preview['changes'])->toBe(['dropoff'])
        ->and($preview['proposed']['delivery_fee'])->toBe(200)
        ->and($preview['proposed']['total'])->toBe(2000)
        ->and($preview['warnings'])->toBe(['delivery_fee' => $warning])
        ->and(session('storefront_key'))->toBeNull();
})->with([
    'an error list'         => [fn () => new JsonResponse(['errors' => ['Too far for our riders']], 400), 'Too far for our riders The delivery fee is unchanged.'],
    'a single error'        => [fn () => new JsonResponse(['error' => 'No service rates available!'], 400), 'No service rates available! The delivery fee is unchanged.'],
    'no quote at all'       => [fn () => new JsonResource(null), 'No delivery rate covers the new address. The delivery fee is unchanged.'],
    'the rates failing'     => [fn () => new RuntimeException('Rates are offline'), 'Rates are offline The delivery fee is unchanged.'],
]);

test('an order without a pickup is not quoted', function () {
    $preview = OrderEditor::preview(orderEditorCoverageOrder([], [], ['pickup_uuid' => null]), ['dropoff' => ['place' => 'place_office']]);

    expect($preview['warnings'])->toBe(['delivery_fee' => 'The order has no pickup to quote from. The delivery fee is unchanged.'])
        ->and(OrderEditorCoverageQuoteController::$requests)->toBe([]);
});

test('a drop-off that cannot be found, or is the same place, changes nothing', function () {
    $missing = OrderEditor::preview(orderEditorCoverageOrder(), ['dropoff' => ['place' => 'place_nowhere']]);
    expect($missing['violations'])->toBe(['dropoff' => 'The new address could not be found.'])
        ->and($missing['changes'])->toBe([]);

    orderEditorCoverageSchema();
    $same = OrderEditor::preview(orderEditorCoverageOrder(), ['dropoff' => ['place' => 'place_home']]);
    expect($same['changes'])->toBe([])
        ->and($same['violations'])->toBe([])
        ->and(OrderEditorCoverageQuoteController::$requests)->toBe([]);
});

test('a pickup order has no drop-off and pays no delivery', function () {
    $order   = orderEditorCoverageOrder([], ['is_pickup' => true, 'delivery_fee' => 0, 'total' => 1800, 'delivery_tip' => 150]);
    $preview = OrderEditor::preview($order, ['dropoff' => ['place' => 'place_office'], 'items' => [['entity' => 'entity_tea', 'quantity' => 1], ['entity' => 'entity_cake']]]);

    expect($preview['changes'])->toBe(['items', 'total'])
        ->and($preview['order']['delivery_tip'])->toBe(0)
        ->and($preview['proposed'])->toMatchArray(['subtotal' => 1300, 'delivery_fee' => 0, 'delivery_tip' => 0, 'total' => 1300])
        ->and(orderEditorPlanEngine()->calls[0]['context']->isPickup)->toBeTrue()
        ->and(orderEditorPlanEngine()->calls[0]['context']->deliveryFee)->toBe(0);
});

test('a finished order cannot be changed at all', function () {
    $preview = OrderEditor::preview(orderEditorCoverageOrder(['status' => 'completed', 'notes' => 'Leave at door']), [
        'dropoff' => ['place' => 'place_office'],
        'contact' => ['name' => 'Ada King'],
        'notes'   => 'Call on arrival',
    ]);

    expect($preview['stage'])->toBe(OrderEditor::STAGE_CLOSED)
        ->and($preview['changes'])->toBe(['dropoff', 'contact', 'notes'])
        ->and($preview['violations'])->toBe([
            'dropoff' => 'The order is completed.',
            'contact' => 'The order is completed.',
            'notes'   => 'The order is completed.',
        ]);
});

test('contact details change only when a name, phone or email is given', function () {
    $changed = OrderEditor::preview(orderEditorCoverageOrder(), ['contact' => ['name' => 'Ada King', 'phone' => '', 'email' => null]]);
    expect($changed['changes'])->toBe(['contact'])
        ->and($changed['summary'])->toBe('Contact details updated')
        ->and($changed['difference'])->toBe(0)
        ->and($changed['proposed']['total'])->toBe(2000)
        ->and(orderEditorPlanEngine()->calls)->toBe([]);

    orderEditorCoverageSchema();
    expect(OrderEditor::preview(orderEditorCoverageOrder(), ['contact' => ['phone' => '', 'scope' => 'profile']])['changes'])->toBe([]);
});

test('notes change when they differ, and blank notes clear them', function () {
    $order = fn () => orderEditorCoverageOrder(['notes' => 'Leave at door']);

    expect(OrderEditor::preview($order(), ['notes' => '  Leave at door '])['changes'])->toBe([]);

    orderEditorCoverageSchema();
    $cleared = OrderEditor::preview($order(), ['notes' => '   ']);
    expect($cleared['changes'])->toBe(['notes'])
        ->and($cleared['summary'])->toBe('Notes updated');
});

test('moving the time says when it is now in the store time, and clearing it means as soon as possible', function () {
    $order = fn (array $attributes = []) => orderEditorCoverageOrder(array_merge(['scheduled_at' => '2026-10-13 04:00:00'], $attributes));

    $moved = OrderEditor::preview($order(), ['scheduled_at' => '2026-10-13T06:30:00+00:00']);
    expect($moved['changes'])->toBe(['schedule'])
        ->and($moved['summary'])->toBe('Time moved to Tue 13 Oct, 14:30')
        ->and($moved['violations'])->toBe([]);

    orderEditorCoverageSchema();
    expect(OrderEditor::preview($order(), ['scheduled_at' => '2026-10-13T04:00:00Z'])['changes'])->toBe([]);

    orderEditorCoverageSchema();
    expect(OrderEditor::preview($order(), ['scheduled_at' => null])['summary'])->toBe('Time cleared (as soon as possible)');

    orderEditorCoverageSchema();
    expect(OrderEditor::preview(orderEditorCoverageOrder(), ['scheduled_at' => ''])['changes'])->toBe([]);
});

test('a ready order can only move earlier, and an order with the driver keeps its time', function () {
    $ready = fn () => orderEditorCoverageOrder(['status' => 'ready', 'scheduled_at' => '2026-10-13 04:00:00']);

    expect(OrderEditor::preview($ready(), ['scheduled_at' => '2026-10-13T03:00:00+00:00'])['violations'])->toBe([]);

    orderEditorCoverageSchema();
    expect(OrderEditor::preview($ready(), ['scheduled_at' => '2026-10-13T05:00:00+00:00'])['violations'])->toBe(['schedule' => 'The order is ready; it can only be moved earlier.']);

    orderEditorCoverageSchema();
    expect(OrderEditor::preview($ready(), ['scheduled_at' => null])['violations'])->toBe(['schedule' => 'The order is ready; it can only be moved earlier.']);

    orderEditorCoverageSchema();
    expect(OrderEditor::preview(orderEditorCoverageOrder(['status' => 'ready']), ['scheduled_at' => '2026-10-13T05:00:00+00:00'])['violations'])->toBe([]);

    orderEditorCoverageSchema();
    expect(OrderEditor::preview(orderEditorCoverageOrder(['status' => 'driver_enroute']), ['scheduled_at' => '2026-10-13T05:00:00+00:00'])['violations'])->toBe(['schedule' => 'The driver has the order.']);
});

/** A booking of the Deep Clean service (Tuesdays 09:00 to 12:00, store time) at 09:00 on 13 Oct. */
function orderEditorPlanBooking(array $meta = [], ?array $entityMeta = null): Fleetbase\FleetOps\Models\Order
{
    $order = orderEditorCoverageOrder([], array_merge([
        'is_booking' => true,
        'subtotal'   => 6000,
        'total'      => 6000,
        'booking'    => ['start' => '2026-10-13T01:00:00+00:00', 'end' => '2026-10-13T02:00:00+00:00', 'duration_minutes' => 60],
    ], $meta), [], [
        ['public_id' => 'entity_clean', 'name' => 'Deep Clean', 'meta' => $entityMeta ?? ['product_id' => 'product_clean', 'quantity' => 1, 'subtotal' => 6000, 'scheduled_at' => '2026-10-13T01:00:00+00:00']],
    ]);
    orderEditorCoverageDb()->table('products')->insert(['uuid' => 'clean_uuid', 'public_id' => 'product_clean', 'company_uuid' => 'company_uuid', 'store_uuid' => 'store_uuid', 'name' => 'Deep Clean', 'currency' => 'USD', 'price' => 6000, 'is_bookable' => true, 'meta' => json_encode(['service' => ['duration_minutes' => 60, 'capacity' => 1]])]);
    orderEditorCoverageDb()->table('product_hours')->insert(['uuid' => 'clean_hours', 'product_uuid' => 'clean_uuid', 'day_of_week' => 'tuesday', 'start' => '09:00:00', 'end' => '12:00:00']);

    return $order;
}

test('a booking moves to a free slot of its service', function () {
    $preview = OrderEditor::preview(orderEditorPlanBooking(), ['scheduled_at' => '2026-10-13T02:00:00+00:00']);

    expect($preview['changes'])->toBe(['schedule'])
        ->and($preview['summary'])->toBe('Booking moved to Tue 13 Oct, 10:00')
        ->and($preview['violations'])->toBe([]);
});

test('a booking cannot move to a time the service does not offer, nor lose its time', function () {
    $taken = OrderEditor::preview(orderEditorPlanBooking(), ['scheduled_at' => '2026-10-13T02:30:00+00:00']);
    expect($taken['violations'])->toBe(['schedule' => 'That time is not free for Deep Clean.']);

    orderEditorCoverageSchema();
    $cleared = OrderEditor::preview(orderEditorPlanBooking(), ['scheduled_at' => null]);
    expect($cleared['violations'])->toBe(['schedule' => 'A booking needs a time.'])
        ->and($cleared['summary'])->toBe('Time cleared (as soon as possible)');
});

test('a booking kept only as booking_at still knows when it was, and a booking of no product skips the slot check', function () {
    $legacy = OrderEditor::preview(orderEditorPlanBooking(['booking' => null, 'booking_at' => '2026-10-13T01:00:00+00:00']), ['scheduled_at' => '2026-10-13T01:00:00+00:00']);
    expect($legacy['changes'])->toBe([]);

    orderEditorCoverageSchema();
    $unknown = OrderEditor::preview(orderEditorPlanBooking([], ['quantity' => 1, 'subtotal' => 6000]), ['scheduled_at' => '2026-10-14T23:15:00+00:00']);
    expect($unknown['violations'])->toBe([])
        ->and($unknown['summary'])->toBe('Booking moved to Thu 15 Oct, 07:15');
});

test('tips follow the new subtotal and cash to collect follows the new total', function () {
    $order = orderEditorCoverageOrder([], ['gateway' => 'cash', 'tip' => '10%', 'delivery_tip' => 150, 'total' => 2330], ['cod_amount' => 2330, 'cod_payment_method' => 'cash']);

    $preview = OrderEditor::preview($order, ['items' => [['entity' => 'entity_tea', 'quantity' => 1], ['entity' => 'entity_cake']]]);

    expect($preview['order'])->toMatchArray(['tip' => 180, 'delivery_tip' => 150, 'cod_amount' => 2330])
        ->and($preview['proposed'])->toMatchArray(['subtotal' => 1300, 'tip' => 130, 'delivery_tip' => 150, 'total' => 1780, 'cod_amount' => 1780])
        ->and($preview['changes'])->toBe(['items', 'total', 'cod_amount'])
        ->and($preview['settlement'])->toMatchArray(['action' => 'cod_update', 'amount' => -550]);
});

test('promotions are re-evaluated with the codes the order used, and lost codes are named', function () {
    $order          = orderEditorCoverageOrder([], ['promotions' => [['code' => 'SAVE10', 'promotion' => 'promotion_save'], ['code' => 'FREESHIP'], ['name' => 'Automatic']]]);
    $engine         = orderEditorPlanEngine();
    $engine->result = new PromotionResult([[
        'promotion_uuid'  => 'save_uuid',
        'promotion_id'    => 'promotion_save',
        'name'            => 'Save 10%',
        'type'            => 'percentage',
        'code'            => 'SAVE10',
        'amount'          => 230,
        'delivery_amount' => 0,
    ]]);

    $preview = OrderEditor::preview($order, ['items' => [['entity' => 'entity_tea', 'quantity' => 3], ['entity' => 'entity_cake']]]);

    expect($engine->calls[0]['codes'])->toBe(['SAVE10', 'FREESHIP'])
        ->and($preview['proposed'])->toMatchArray([
            'discount'        => 230,
            'promotions'      => [['promotion' => 'promotion_save', 'name' => 'Save 10%', 'type' => 'percentage', 'code' => 'SAVE10', 'amount' => 230, 'delivery_amount' => 0]],
            'promotions_lost' => ['FREESHIP'],
            'total'           => 2270,
        ]);
});

test('promotions that cannot be evaluated keep the discount the order had', function () {
    $order           = orderEditorCoverageOrder([], ['discount' => 150, 'total' => 1850, 'promotions' => [['code' => 'SAVE150']]]);
    $engine          = orderEditorPlanEngine();
    $engine->failure = new RuntimeException('Promotions are unavailable');

    $preview = OrderEditor::preview($order, ['items' => [['entity' => 'entity_tea', 'quantity' => 1], ['entity' => 'entity_cake']]]);

    expect($preview['proposed'])->toMatchArray(['discount' => 150, 'promotions' => [['code' => 'SAVE150']], 'promotions_lost' => [], 'total' => 1350])
        ->and($GLOBALS['orderEditorCoverageReported'])->toHaveCount(1)
        ->and($GLOBALS['orderEditorCoverageReported'][0]->getMessage())->toBe('Promotions are unavailable');
});

test('an order whose store is gone is priced without promotions and from any product', function () {
    $order   = orderEditorCoverageOrder([], ['storefront_id' => 'store_gone']);
    $preview = OrderEditor::preview($order, ['items' => [['entity' => 'entity_tea'], ['entity' => 'entity_cake'], ['product' => 'product_foreign']]]);

    expect($preview['violations'])->toBe([])
        ->and($preview['summary'])->toBe('Added Elsewhere ×1')
        ->and($preview['proposed'])->toMatchArray(['subtotal' => 1900, 'discount' => 0, 'total' => 2100])
        ->and(orderEditorPlanEngine()->calls)->toBe([]);
});

test("today's opening hours come from the location the order is made at", function () {
    $order = orderEditorCoverageOrder();
    $db    = orderEditorCoverageDb();
    $db->table('store_locations')->insert([
        ['uuid' => 'first_location', 'public_id' => 'store_location_first', 'store_uuid' => 'store_uuid', 'place_uuid' => 'elsewhere_uuid', 'created_at' => '2026-01-01 00:00:00'],
        ['uuid' => 'bakery_location', 'public_id' => 'store_location_bakery', 'store_uuid' => 'store_uuid', 'place_uuid' => 'pickup_uuid', 'created_at' => '2026-02-01 00:00:00'],
    ]);
    $db->table('store_hours')->insert([
        ['store_location_uuid' => 'bakery_location', 'day_of_week' => 'Monday', 'start' => '17:00:00', 'end' => '22:00:00'],
        ['store_location_uuid' => 'bakery_location', 'day_of_week' => 'monday', 'start' => '08:00:00', 'end' => '12:00:00'],
        ['store_location_uuid' => 'bakery_location', 'day_of_week' => 'monday', 'start' => '23:00:00', 'end' => '02:00:00'],
        ['store_location_uuid' => 'bakery_location', 'day_of_week' => 'tuesday', 'start' => '08:00:00', 'end' => '12:00:00'],
        ['store_location_uuid' => 'first_location', 'day_of_week' => 'monday', 'start' => '06:00:00', 'end' => '07:00:00'],
    ]);

    expect(OrderEditor::hoursToday($order))->toBe([
        'timezone' => 'Asia/Singapore',
        'windows'  => [
            ['opens' => '08:00', 'closes' => '12:00', 'start' => '2026-10-12T08:00:00+08:00', 'end' => '2026-10-12T12:00:00+08:00'],
            ['opens' => '17:00', 'closes' => '22:00', 'start' => '2026-10-12T17:00:00+08:00', 'end' => '2026-10-12T22:00:00+08:00'],
        ],
    ]);
});

test("today's opening hours fall back to the store's first location, and a missing store has none", function () {
    $order = orderEditorCoverageOrder([], [], ['pickup_uuid' => null]);
    orderEditorCoverageDb()->table('store_locations')->insert([
        ['uuid' => 'later_location', 'public_id' => 'store_location_later', 'store_uuid' => 'store_uuid', 'place_uuid' => 'pickup_uuid', 'created_at' => '2026-02-01 00:00:00'],
        ['uuid' => 'first_location', 'public_id' => 'store_location_first', 'store_uuid' => 'store_uuid', 'place_uuid' => 'elsewhere_uuid', 'created_at' => '2026-01-01 00:00:00'],
    ]);
    orderEditorCoverageDb()->table('store_hours')->insert(['store_location_uuid' => 'first_location', 'day_of_week' => 'monday', 'start' => '06:00:00', 'end' => '07:00:00']);

    expect(OrderEditor::hoursToday($order)['windows'])->toBe([
        ['opens' => '06:00', 'closes' => '07:00', 'start' => '2026-10-12T06:00:00+08:00', 'end' => '2026-10-12T07:00:00+08:00'],
    ]);

    orderEditorCoverageSchema();
    expect(OrderEditor::hoursToday(orderEditorCoverageOrder([], ['storefront_id' => 'store_gone'])))->toBe(['timezone' => 'UTC', 'windows' => []]);
});
