<?php

require_once __DIR__ . '/../../../Support/OrderSettlementTestHelpers.php';

use Fleetbase\Storefront\Http\Controllers\v1\OrderController;
use Illuminate\Http\Request;

const CUSTOMER_ORDER_STORES_CUSTOMER = '11111111-1111-4111-8111-111111111111';

/** Sign the request in as the customer (or no one) through the Customer-Token header. */
function customerOrderStoresSignIn(?string $token): void
{
    $request = Request::create('/orders');
    if ($token) {
        $request->headers->set('Customer-Token', $token);
    }
    $request->setLaravelSession(new Illuminate\Session\Store('customer-order-stores', new Illuminate\Session\ArraySessionHandler(120)));
    app()->instance('request', $request);
}

function customerOrderStoresInsertOrder(string $id, array $meta, array $attributes = []): void
{
    settlementCoverageDb()->table('orders')->insert(array_merge([
        'uuid'          => $id . '_uuid',
        'public_id'     => $id,
        'company_uuid'  => 'settle_company_uuid',
        'customer_uuid' => CUSTOMER_ORDER_STORES_CUSTOMER,
        'status'        => 'created',
        'meta'          => json_encode($meta),
        'created_at'    => now(),
        'updated_at'    => now(),
    ], $attributes));
}

beforeEach(function () {
    settlementCoverageSchema();
    $schema = settlementCoverageDb()->getSchemaBuilder();
    foreach (['personal_access_tokens', 'contacts'] as $table) {
        $schema->dropIfExists($table);
    }
    $schema->create('personal_access_tokens', function ($table) {
        $table->increments('id');
        $table->string('tokenable_type')->nullable();
        $table->integer('tokenable_id')->nullable();
        $table->string('name');
        $table->string('token');
        $table->text('abilities')->nullable();
        $table->timestamp('last_used_at')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
    });
    $schema->create('contacts', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id')->nullable();
        $table->string('type')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    settlementCoverageDb()->table('contacts')->insert(['uuid' => CUSTOMER_ORDER_STORES_CUSTOMER, 'public_id' => 'contact_customer', 'type' => 'customer']);
    settlementCoverageDb()->table('personal_access_tokens')->insert([
        'name'       => CUSTOMER_ORDER_STORES_CUSTOMER,
        'token'      => hash('sha256', 'customer-order-stores-secret'),
        'abilities'  => '["*"]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

afterEach(function () {
    settlementCoverageRestoreStripe();
    customerOrderStoresSignIn(null);
});

test('qpay calling back for an unknown or deleted order is told it is unknown', function () {
    customerOrderStoresInsertOrder('order_deleted', ['balance_due' => 700], ['deleted_at' => now()]);
    $controller = new OrderController();

    $unknown = $controller->balancePaid(Request::create('/orders/balance-paid', 'POST', ['order' => 'order_nowhere']));
    $deleted = $controller->balancePaid(Request::create('/orders/balance-paid', 'POST', ['order' => 'order_deleted']));

    expect($unknown->getStatusCode())->toBe(404)
        ->and($unknown->getData(true))->toBe(['status' => 'unknown'])
        ->and($deleted->getStatusCode())->toBe(404);
});

test('a balance callback is only a nudge: the order is paid when the gateway says so', function () {
    settlementCoverageGateway('stripe', ['secret_key' => 'sk_test_balance_callback']);
    $link = fn (string $reference) => ['total' => 5000, 'currency' => 'USD', 'gateway' => 'stripe', 'balance_due' => 700, 'balance_payment' => ['gateway' => 'stripe', 'reference' => $reference, 'amount' => 700]];
    customerOrderStoresInsertOrder('order_nothing_due', ['total' => 5000, 'gateway' => 'stripe'], ['company_uuid' => 'company_nothing_due']);
    customerOrderStoresInsertOrder('order_paid_link', $link('plink_paid'), ['company_uuid' => 'company_paid_link', 'customer_uuid' => null]);
    customerOrderStoresInsertOrder('order_unpaid_link', $link('plink_unpaid'), ['customer_uuid' => null]);
    settlementCoverageCheckout(['uuid' => 'paid_checkout_uuid', 'public_id' => 'checkout_paid', 'order_uuid' => 'order_paid_link_uuid']);
    settlementCoverageCheckout(['uuid' => 'unpaid_checkout_uuid', 'public_id' => 'checkout_unpaid', 'order_uuid' => 'order_unpaid_link_uuid']);
    $stripe = settlementCoverageStripe([
        'GET /v1/checkout/sessions'            => [200, ['object' => 'list', 'data' => [['id' => 'cs_paid', 'object' => 'checkout.session', 'payment_status' => 'paid', 'amount_total' => 700, 'payment_intent' => 'pi_paid_link']]]],
        'POST /v1/payment_links/plink_paid'    => [200, ['id' => 'plink_paid', 'object' => 'payment_link', 'active' => false]],
    ]);
    $controller = new OrderController();

    $nothingDue     = $controller->balancePaid(Request::create('/orders/balance-paid', 'POST', ['order' => 'order_nothing_due']));
    $sessionCompany = session('company');
    $paid           = $controller->balancePaid(Request::create('/orders/balance-paid', 'POST', ['order' => 'order_paid_link']));
    $paidCompany    = session('company');
    // Stripe can no longer be reached: the callback is answered and the balance left as it is.
    $stripe->routes = [];
    $outage         = $controller->balancePaid(Request::create('/orders/balance-paid', 'POST', ['order' => 'order_unpaid_link']));

    expect($nothingDue->getData(true))->toBe(['status' => 'pending'])
        ->and($sessionCompany)->toBe('company_nothing_due')
        ->and($paid->getData(true))->toBe(['status' => 'paid'])
        ->and($paidCompany)->toBe('company_paid_link')
        ->and(settlementCoverageStoredMeta('order_paid_link_uuid')['balance_due'])->toBe(0)
        ->and(settlementCoverageStoredMeta('order_paid_link_uuid')['payment_adjustments'][0])->toMatchArray(['type' => 'charge', 'amount' => 700, 'reference' => 'pi_paid_link'])
        ->and($outage->getStatusCode())->toBe(200)
        ->and($outage->getData(true))->toBe(['status' => 'pending'])
        ->and(settlementCoverageStoredMeta('order_unpaid_link_uuid')['balance_due'])->toBe(700);
});

test('the stores of an order are only shown to the customer who placed it', function () {
    customerOrderStoresInsertOrder('order_theirs', ['is_master_order' => true], ['customer_uuid' => '22222222-2222-4222-8222-222222222222']);

    customerOrderStoresSignIn(null);
    $signedOut = (new OrderController())->getStores('order_theirs');

    customerOrderStoresSignIn('customer-order-stores-secret');
    $controller = new OrderController();
    $missing    = $controller->getStores('order_nowhere');
    $theirs     = $controller->getStores('order_theirs');

    expect($signedOut->getData(true))->toBe(['error' => 'Customer is not authenticated.'])
        ->and($missing->getStatusCode())->toBe(404)
        ->and($missing->getData(true))->toBe(['error' => 'No order found.'])
        ->and($theirs->getStatusCode())->toBe(403)
        ->and($theirs->getData(true))->toBe(['error' => 'Not authorized to view this order.']);
});

test('an order from a single store has no breakdown by store', function () {
    customerOrderStoresInsertOrder('order_single', ['storefront_id' => 'store_settle']);
    customerOrderStoresInsertOrder('order_master_alone', ['is_master_order' => true, 'related_orders' => ['', null]]);
    customerOrderStoresSignIn('customer-order-stores-secret');
    $controller = new OrderController();

    expect($controller->getStores('order_single')->getData(true))->toBe(['stores' => []])
        ->and($controller->getStores('order_master_alone')->getData(true))->toBe(['stores' => []]);
});

test('a network order is broken down by the stores preparing it, in the order they were placed', function () {
    settlementCoverageDb()->table('stores')->where('public_id', 'store_settle')->update(['phone' => '+97611112222']);
    settlementCoverageDb()->table('places')->insert(['uuid' => 'bakery_place_uuid', 'public_id' => 'place_bakery', 'name' => 'Corner Bakery', 'street1' => '1 Peace Avenue', 'city' => 'Ulaanbaatar']);
    settlementCoverageDb()->table('payloads')->insert(['uuid' => 'bakery_payload_uuid', 'pickup_uuid' => 'bakery_place_uuid']);
    settlementCoverageDb()->table('entities')->insert([
        ['uuid' => 'croissant_uuid', 'public_id' => 'entity_croissant', 'payload_uuid' => 'bakery_payload_uuid', 'name' => 'Croissant', 'meta' => json_encode(['quantity' => 2, 'subtotal' => 1800, 'variants' => [['name' => 'Almond']], 'addons' => [['name' => 'Jam']], 'image_url' => 'https://cdn.test/croissant.png'])],
        ['uuid' => 'coffee_uuid', 'public_id' => 'entity_coffee', 'payload_uuid' => 'bakery_payload_uuid', 'name' => 'Coffee', 'meta' => json_encode([])],
    ]);
    customerOrderStoresInsertOrder('order_master', ['is_master_order' => true, 'related_orders' => ['order_child_stall', 'order_child_theirs', 'order_child_bakery', 'order_child_missing']]);
    customerOrderStoresInsertOrder('order_child_bakery', ['storefront_id' => 'store_settle', 'subtotal' => 3800, 'discount' => 200, 'tip' => 100, 'currency' => 'USD'], ['status' => 'preparing', 'payload_uuid' => 'bakery_payload_uuid']);
    customerOrderStoresInsertOrder('order_child_stall', ['storefront_id' => 'store_closed', 'storefront' => 'Night Market Stall', 'subtotal' => 1200, 'currency' => 'USD']);
    customerOrderStoresInsertOrder('order_child_theirs', ['storefront_id' => 'store_settle'], ['customer_uuid' => '22222222-2222-4222-8222-222222222222']);
    customerOrderStoresSignIn('customer-order-stores-secret');

    $stores = (new OrderController())->getStores('order_master')->getData(true)['stores'];

    // Placed with the stall first; another customer's order is never part of it.
    expect(array_column($stores, 'order'))->toBe(['order_child_stall', 'order_child_bakery'])
        // A store that cannot be found is still named from the order.
        ->and($stores[0])->toMatchArray([
            'status'   => 'created',
            'store'    => ['id' => 'store_closed', 'name' => 'Night Market Stall', 'logo_url' => null, 'phone' => null, 'address' => null],
            'items'    => [],
            'subtotal' => 1200,
            'discount' => 0,
            'tip'      => 0,
            'currency' => 'USD',
        ])
        ->and($stores[0]['label'])->toBe('Created')
        ->and($stores[1])->toMatchArray(['status' => 'preparing', 'subtotal' => 3800, 'discount' => 200, 'tip' => 100, 'currency' => 'USD'])
        ->and($stores[1]['label'])->toBe('Preparing')
        ->and($stores[1]['store'])->toMatchArray(['id' => 'store_settle', 'name' => 'Corner Bakery', 'phone' => '+97611112222'])
        ->and($stores[1]['store']['logo_url'])->toBeString()
        ->and($stores[1]['store']['address'])->toBe('CORNER BAKERY - 1 PEACE AVENUE, ULAANBAATAR')
        ->and($stores[1]['items'])->toBe([
            ['id' => 'entity_croissant', 'name' => 'Croissant', 'quantity' => 2, 'subtotal' => 1800, 'variants' => [['name' => 'Almond']], 'addons' => [['name' => 'Jam']], 'image_url' => 'https://cdn.test/croissant.png'],
            ['id' => 'entity_coffee', 'name' => 'Coffee', 'quantity' => 1, 'subtotal' => 0, 'variants' => [], 'addons' => [], 'image_url' => $stores[1]['items'][1]['image_url']],
        ])
        // Without its own image an item shows the default parcel image.
        ->and($stores[1]['items'][1]['image_url'])->toStartWith('https://');
});
