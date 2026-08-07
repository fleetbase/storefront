<?php

use Fleetbase\Storefront\Http\Controllers\AnalyticsController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

function createAnalyticsControllerSchema(): void
{
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();

    foreach (['orders', 'transactions', 'stores', 'products', 'carts', 'checkouts', 'contacts', 'files'] as $table) {
        $schema->dropIfExists($table);
    }

    $schema->create('orders', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('type')->nullable();
        $table->string('status')->nullable();
        $table->string('customer_uuid')->nullable();
        $table->string('transaction_uuid')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('transactions', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->unsignedBigInteger('amount')->nullable();
        $table->string('currency')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('stores', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('currency')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('products', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('store_uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('carts', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->text('items')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('checkouts', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('store_uuid')->nullable();
        $table->string('order_uuid')->nullable();
        $table->boolean('captured')->default(false);
        $table->string('currency')->nullable();
        $table->text('cart_state')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('contacts', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('type')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('files', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('type')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
}

function analyticsRequest(): Request
{
    return Request::create('/analytics', 'GET', [
        'start' => '2026-07-01',
        'end'   => '2026-07-02',
    ]);
}

test('analytics endpoints return complete zero-state reporting contracts', function () {
    createAnalyticsControllerSchema();
    session(['company' => 'company_uuid']);
    $controller = new AnalyticsController();

    $overview  = $controller->overview(analyticsRequest())->getData(true);
    $trend     = $controller->revenueTrend(analyticsRequest())->getData(true);
    $statuses  = $controller->ordersByStatus(analyticsRequest())->getData(true);
    $products  = $controller->topProducts(analyticsRequest())->getData(true);
    $customers = $controller->customerInsights(analyticsRequest())->getData(true);

    expect($overview['period'])->toBe([
        'start' => '2026-07-01',
        'end'   => '2026-07-02',
    ])->and($overview['currency'])->toBe('USD')
        ->and($overview['metrics']['revenue']['value'])->toBe(0)
        ->and($overview['metrics']['orders']['value'])->toBe(0)
        ->and($overview['metrics']['cart_conversion']['value'])->toBe(0)
        ->and($trend['labels'])->toBe(['2026-07-01', '2026-07-02'])
        ->and($trend['summary'])->toBe([
            'revenue'  => 0,
            'orders'   => 0,
            'currency' => 'USD',
        ])
        ->and($statuses['labels'])->toBe([])
        ->and($statuses['total'])->toBe(0)
        ->and($products)->toBe(['products' => []])
        ->and($customers)->toBe([
            'new_customers'       => 0,
            'returning_customers' => 0,
            'repeat_rate'         => 0,
            'total_customers'     => 0,
            'known_customers'     => 0,
        ]);
});

test('analytics aggregates revenue statuses products conversion and returning customers', function () {
    createAnalyticsControllerSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('orders')->insert([
        [
            'uuid'             => 'order_completed',
            'company_uuid'     => 'company_uuid',
            'type'             => 'storefront',
            'status'           => 'completed',
            'customer_uuid'    => 'customer_uuid',
            'transaction_uuid' => null,
            'meta'             => json_encode(['total' => 2500, 'currency' => 'USD']),
            'created_at'       => '2026-07-01 10:00:00',
            'updated_at'       => '2026-07-01 10:00:00',
        ],
        [
            'uuid'             => 'order_active',
            'company_uuid'     => 'company_uuid',
            'type'             => 'storefront',
            'status'           => 'dispatched',
            'customer_uuid'    => 'customer_uuid',
            'transaction_uuid' => null,
            'meta'             => json_encode(['total' => 1500, 'currency' => 'USD']),
            'created_at'       => '2026-07-02 10:00:00',
            'updated_at'       => '2026-07-02 10:00:00',
        ],
        [
            'uuid'             => 'order_canceled',
            'company_uuid'     => 'company_uuid',
            'type'             => 'storefront',
            'status'           => 'canceled',
            'customer_uuid'    => 'other_customer',
            'transaction_uuid' => null,
            'meta'             => json_encode(['total' => 900, 'currency' => 'USD']),
            'created_at'       => '2026-07-02 11:00:00',
            'updated_at'       => '2026-07-02 11:00:00',
        ],
        [
            'uuid'             => 'order_picked_up',
            'company_uuid'     => 'company_uuid',
            'type'             => 'storefront',
            'status'           => 'picked_up',
            'customer_uuid'    => 'pickup_customer',
            'transaction_uuid' => 'transaction_pickup',
            'meta'             => json_encode(['currency' => 'USD']),
            'created_at'       => '2026-07-02 23:59:59',
            'updated_at'       => '2026-07-02 23:59:59',
        ],
    ]);
    $connection->table('transactions')->insert([
        'uuid'     => 'transaction_pickup',
        'amount'   => 700,
        'currency' => 'USD',
    ]);
    $connection->table('carts')->insert([
        [
            'uuid'         => 'cart_one',
            'company_uuid' => 'company_uuid',
            'items'        => '[]',
            'created_at'   => '2026-07-01 09:00:00',
            'updated_at'   => '2026-07-01 09:00:00',
        ],
        [
            'uuid'         => 'cart_two',
            'company_uuid' => 'company_uuid',
            'items'        => '[]',
            'created_at'   => '2026-07-02 09:00:00',
            'updated_at'   => '2026-07-02 09:00:00',
        ],
    ]);
    $connection->table('checkouts')->insert([
        'uuid'         => 'checkout_uuid',
        'company_uuid' => 'company_uuid',
        'order_uuid'   => 'order_completed',
        'captured'     => true,
        'currency'     => 'USD',
        'cart_state'   => json_encode([
            'items' => [
                [
                    'product_id' => 'product_coffee',
                    'name'       => 'Coffee',
                    'quantity'   => 2,
                    'subtotal'   => 1200,
                ],
            ],
        ]),
        'created_at' => '2026-07-01 10:00:00',
        'updated_at' => '2026-07-01 10:00:00',
    ]);
    $connection->table('contacts')->insert([
        'uuid'         => 'customer_uuid',
        'company_uuid' => 'company_uuid',
        'type'         => 'customer',
    ]);
    session(['company' => 'company_uuid']);
    $controller = new AnalyticsController();

    $overview  = $controller->overview(analyticsRequest())->getData(true);
    $statuses  = $controller->ordersByStatus(analyticsRequest())->getData(true);
    $products  = $controller->topProducts(analyticsRequest())->getData(true);
    $customers = $controller->customerInsights(analyticsRequest())->getData(true);

    expect($overview['metrics']['revenue']['value'])->toBe(4700)
        ->and($overview['metrics']['orders']['value'])->toBe(3)
        ->and($overview['metrics']['completed_orders']['value'])->toBe(2)
        ->and($overview['metrics']['active_orders']['value'])->toBe(1)
        ->and($overview['metrics']['cancellation_rate']['value'])->toBe(25)
        ->and($overview['metrics']['cart_conversion']['value'])->toBe(150)
        ->and($statuses['total'])->toBe(4)
        ->and($products['products'][0])->toMatchArray([
            'id'       => 'product_coffee',
            'name'     => 'Coffee',
            'quantity' => 2,
            'revenue'  => 1200,
            'currency' => 'USD',
        ])
        ->and($customers)->toMatchArray([
            'new_customers'       => 2,
            'returning_customers' => 1,
            'repeat_rate'         => 33.33,
            'total_customers'     => 3,
            'known_customers'     => 1,
        ]);
});

test('analytics store scope filters orders carts checkouts products and malformed cart state', function () {
    createAnalyticsControllerSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('stores')->insert([
        'uuid'         => 'store_uuid',
        'public_id'    => 'store_public',
        'company_uuid' => 'company_uuid',
        'currency'     => 'MNT',
    ]);
    $connection->table('orders')->insert([
        'uuid'          => 'store_order',
        'company_uuid'  => 'company_uuid',
        'type'          => 'storefront',
        'status'        => 'completed',
        'customer_uuid' => 'customer_uuid',
        'meta'          => json_encode(['total' => 3200, 'storefront_id' => 'store_public']),
        'created_at'    => '2026-07-01 10:00:00',
        'updated_at'    => '2026-07-01 10:00:00',
    ]);
    $connection->table('products')->insert([
        'uuid'         => 'product_uuid',
        'public_id'    => 'product_public',
        'company_uuid' => 'company_uuid',
        'store_uuid'   => 'store_uuid',
    ]);
    $connection->table('carts')->insert([
        'uuid'         => 'cart_matching',
        'company_uuid' => 'company_uuid',
        'items'        => json_encode([['store_id' => 'store_public']]),
        'created_at'   => '2026-07-01 09:00:00',
        'updated_at'   => '2026-07-01 09:00:00',
    ]);
    $connection->table('checkouts')->insert([
        'uuid'         => 'checkout_uuid',
        'company_uuid' => 'company_uuid',
        'store_uuid'   => 'store_uuid',
        'order_uuid'   => 'store_order',
        'captured'     => true,
        'currency'     => 'MNT',
        'cart_state'   => json_encode([
            'checkout_store_id' => 'store_public',
            'items'             => [
                ['product_id' => 'product_public', 'store_id' => 'store_public', 'quantity' => 2, 'subtotal' => 3200],
                ['product_id' => 'other_product', 'store_id' => 'other_store'],
                ['store_id'   => 'store_public'],
            ],
        ]),
        'created_at' => '2026-07-01 10:00:00',
        'updated_at' => '2026-07-01 10:00:00',
    ]);
    session(['company' => 'company_uuid']);
    $request = Request::create('/analytics', 'GET', [
        'start' => '2026-07-01',
        'end'   => '2026-07-02',
        'store' => 'store_public',
    ]);
    $controller = new AnalyticsController();

    $overview = $controller->overview($request)->getData(true);
    $trend    = $controller->revenueTrend($request)->getData(true);
    $products = $controller->topProducts($request)->getData(true);

    expect($overview['currency'])->toBe('MNT')
        ->and($overview['metrics']['products']['value'])->toBe(1)
        ->and($overview['metrics']['cart_conversion']['value'])->toBe(100)
        ->and($trend['summary'])->toBe(['revenue' => 3200, 'orders' => 1, 'currency' => 'MNT'])
        ->and($products['products'])->toHaveCount(1)
        ->and($products['products'][0])->toMatchArray([
            'id'       => 'product_public',
            'quantity' => 2,
            'revenue'  => 3200,
        ]);

    expect($controller->cartStateItems(collect(['items' => collect([['id' => 1]])])))->toBe([['id' => 1]])
        ->and($controller->cartStateItems((object) ['items' => (object) ['id' => 2]]))->toBe(['id' => 2])
        ->and($controller->cartStateItems('invalid'))->toBe([]);
});
