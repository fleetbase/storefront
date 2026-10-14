<?php

use Fleetbase\Storefront\Http\Filter\CustomerFilter;
use Fleetbase\Storefront\Http\Filter\FoodTruckFilter;
use Fleetbase\Storefront\Http\Filter\ProductFilter;
use Fleetbase\Storefront\Models\Customer;
use Fleetbase\Storefront\Models\FoodTruck;
use Fleetbase\Storefront\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

function filterCoverageRequest(string $uri, array $query = []): Request
{
    $request = Request::create('/' . $uri, 'GET', $query);
    $request->setLaravelSession(request()->session());
    $request->session()->put('company', 'company_uuid');
    $request->setRouteResolver(fn () => new class($uri) {
        public array $action = [];

        public function __construct(private string $routeUri)
        {
        }

        public function uri(): string
        {
            return $this->routeUri;
        }
    });

    return $request;
}

/**
 * The uuids a filter keeps from the model's table, in id order.
 */
function filterCoverageResults(string $filter, Model $model, string $uri, array $query = []): array
{
    $builder = (new $filter(filterCoverageRequest($uri, $query)))->apply($model->newQuery());

    return $builder->orderBy($model->getTable() . '.id')->pluck($model->getTable() . '.uuid')->all();
}

function filterCoverageProductSchema(): void
{
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();

    foreach (['products', 'product_inventories'] as $table) {
        $schema->dropIfExists($table);
    }

    $schema->create('products', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('company_uuid')->nullable();
        $table->string('category_uuid')->nullable();
        $table->string('status')->nullable();
        $table->boolean('is_available')->default(true);
        $table->boolean('is_on_sale')->default(false);
        $table->boolean('is_recommended')->default(false);
        $table->boolean('track_inventory')->default(false);
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('product_inventories', function ($table) {
        $table->increments('id');
        $table->string('product_uuid');
        $table->integer('quantity')->default(0);
        $table->integer('reserved')->default(0);
        $table->integer('low_stock_threshold')->nullable();
        $table->boolean('allow_backorder')->default(false);
        $table->timestamps();
        $table->softDeletes();
    });

    $connection->table('products')->insert([
        // Published, filed, in stock with plenty left
        ['uuid' => 'product_coffee', 'company_uuid' => 'company_uuid', 'category_uuid' => 'category_drinks', 'status' => 'published', 'is_available' => true, 'is_on_sale' => true, 'is_recommended' => false, 'track_inventory' => true],
        // Draft, uncategorized, switched off
        ['uuid' => 'product_draft', 'company_uuid' => 'company_uuid', 'category_uuid' => null, 'status' => 'draft', 'is_available' => false, 'is_on_sale' => false, 'is_recommended' => true, 'track_inventory' => false],
        // Archived, tracks stock and is running low
        ['uuid' => 'product_low', 'company_uuid' => 'company_uuid', 'category_uuid' => 'category_food', 'status' => 'archived', 'is_available' => true, 'is_on_sale' => false, 'is_recommended' => true, 'track_inventory' => true],
        // Published, tracks stock and has none left
        ['uuid' => 'product_sold_out', 'company_uuid' => 'company_uuid', 'category_uuid' => 'category_food', 'status' => 'published', 'is_available' => true, 'is_on_sale' => true, 'is_recommended' => false, 'track_inventory' => true],
    ]);
    $connection->table('product_inventories')->insert([
        ['product_uuid' => 'product_coffee', 'quantity' => 50, 'reserved' => 0, 'low_stock_threshold' => 5],
        ['product_uuid' => 'product_low', 'quantity' => 4, 'reserved' => 1, 'low_stock_threshold' => 5],
        ['product_uuid' => 'product_sold_out', 'quantity' => 2, 'reserved' => 2, 'low_stock_threshold' => 5],
    ]);
}

test('the product status filter takes a comma separated list or an array and ignores an empty list', function () {
    filterCoverageProductSchema();
    $product = new Product();

    expect(filterCoverageResults(ProductFilter::class, $product, 'v1/storefront/products', ['status' => 'published']))->toBe(['product_coffee', 'product_sold_out'])
        ->and(filterCoverageResults(ProductFilter::class, $product, 'v1/storefront/products', ['status' => 'draft,archived']))->toBe(['product_draft', 'product_low'])
        ->and(filterCoverageResults(ProductFilter::class, $product, 'v1/storefront/products', ['status' => ['draft', 'published']]))->toBe(['product_coffee', 'product_draft', 'product_sold_out'])
        ->and(filterCoverageResults(ProductFilter::class, $product, 'v1/storefront/products', ['status' => ',']))->toBe(['product_coffee', 'product_draft', 'product_low', 'product_sold_out']);
});

test('the product uncategorized filter keeps only unfiled products when it is on', function () {
    filterCoverageProductSchema();
    $product = new Product();

    expect(filterCoverageResults(ProductFilter::class, $product, 'v1/storefront/products', ['uncategorized' => 'true']))->toBe(['product_draft'])
        ->and(filterCoverageResults(ProductFilter::class, $product, 'v1/storefront/products', ['uncategorized' => 'false']))->toHaveCount(4);
});

test('the product availability, sale and recommended filters match the flag either way', function () {
    filterCoverageProductSchema();
    $product = new Product();

    expect(filterCoverageResults(ProductFilter::class, $product, 'v1/storefront/products', ['available' => 'false']))->toBe(['product_draft'])
        ->and(filterCoverageResults(ProductFilter::class, $product, 'v1/storefront/products', ['available' => 'true']))->toBe(['product_coffee', 'product_low', 'product_sold_out'])
        ->and(filterCoverageResults(ProductFilter::class, $product, 'v1/storefront/products', ['on_sale' => 'true']))->toBe(['product_coffee', 'product_sold_out'])
        ->and(filterCoverageResults(ProductFilter::class, $product, 'v1/storefront/products', ['on_sale' => 'no']))->toBe(['product_draft', 'product_low'])
        ->and(filterCoverageResults(ProductFilter::class, $product, 'v1/storefront/products', ['recommended' => '1']))->toBe(['product_draft', 'product_low'])
        ->and(filterCoverageResults(ProductFilter::class, $product, 'v1/storefront/products', ['recommended' => 'off']))->toBe(['product_coffee', 'product_sold_out']);
});

test('the product stock filter finds out of stock and low stock products and ignores other states', function () {
    filterCoverageProductSchema();
    $product = new Product();

    expect(filterCoverageResults(ProductFilter::class, $product, 'v1/storefront/products', ['stock' => 'out']))->toBe(['product_draft', 'product_sold_out'])
        ->and(filterCoverageResults(ProductFilter::class, $product, 'v1/storefront/products', ['stock' => 'low']))->toBe(['product_low'])
        ->and(filterCoverageResults(ProductFilter::class, $product, 'v1/storefront/products', ['stock' => 'plenty']))->toHaveCount(4);
});

test('the product category filter applies to console requests and is left to the public controller otherwise', function () {
    $internal = (new ProductFilter(filterCoverageRequest('int/v1/storefront/products', ['category' => 'category_food'])))->apply((new Product())->newQuery());
    $public   = (new ProductFilter(filterCoverageRequest('v1/storefront/products', ['category' => 'category_public_id'])))->apply((new Product())->newQuery());

    expect($internal->toSql())->toContain('"category_uuid" = ?')
        ->and($internal->getBindings())->toContain('category_food')
        ->and($public->toSql())->not->toContain('category_uuid')
        ->and($public->getBindings())->not->toContain('category_public_id');
});

test('the customer network filter keeps customers who ordered through the network', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    foreach (['contacts', 'orders'] as $table) {
        $schema->dropIfExists($table);
    }
    $schema->create('contacts', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('company_uuid')->nullable();
        $table->string('type')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('orders', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('customer_uuid')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $connection->table('contacts')->insert([
        ['uuid' => 'customer_network', 'company_uuid' => 'company_uuid', 'type' => 'customer'],
        ['uuid' => 'customer_store', 'company_uuid' => 'company_uuid', 'type' => 'customer'],
        ['uuid' => 'customer_deleted_order', 'company_uuid' => 'company_uuid', 'type' => 'customer'],
        ['uuid' => 'customer_other_company', 'company_uuid' => 'other_company', 'type' => 'customer'],
    ]);
    $connection->table('orders')->insert([
        ['uuid' => 'order_network', 'customer_uuid' => 'customer_network', 'meta' => json_encode(['storefront_network_id' => 'network_public', 'storefront_id' => 'store_public']), 'deleted_at' => null],
        ['uuid' => 'order_store', 'customer_uuid' => 'customer_store', 'meta' => json_encode(['storefront_id' => 'store_public']), 'deleted_at' => null],
        ['uuid' => 'order_deleted', 'customer_uuid' => 'customer_deleted_order', 'meta' => json_encode(['storefront_network_id' => 'network_public']), 'deleted_at' => '2026-10-01 00:00:00'],
        ['uuid' => 'order_other', 'customer_uuid' => 'customer_other_company', 'meta' => json_encode(['storefront_network_id' => 'network_public']), 'deleted_at' => null],
    ]);
    $customer = new Customer();

    expect(filterCoverageResults(CustomerFilter::class, $customer, 'v1/storefront/customers', ['network' => 'network_public']))->toBe(['customer_network'])
        ->and(filterCoverageResults(CustomerFilter::class, $customer, 'v1/storefront/customers', ['storefront' => 'store_public']))->toBe(['customer_network', 'customer_store'])
        ->and(filterCoverageResults(CustomerFilter::class, $customer, 'v1/storefront/customers', ['network' => 'network_elsewhere']))->toBe([]);
});

test('the food truck owned-by-network filter keeps only the trucks the network runs itself', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('food_trucks');
    $schema->create('food_trucks', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('company_uuid')->nullable();
        $table->string('store_uuid')->nullable();
        $table->string('network_uuid')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $connection->table('food_trucks')->insert([
        ['uuid' => 'truck_network', 'company_uuid' => 'company_uuid', 'store_uuid' => null, 'network_uuid' => 'network_uuid'],
        ['uuid' => 'truck_member_store', 'company_uuid' => 'company_uuid', 'store_uuid' => 'store_uuid', 'network_uuid' => null],
        ['uuid' => 'truck_other_network', 'company_uuid' => 'company_uuid', 'store_uuid' => null, 'network_uuid' => 'other_network_uuid'],
        ['uuid' => 'truck_other_company', 'company_uuid' => 'other_company', 'store_uuid' => null, 'network_uuid' => 'network_uuid'],
    ]);

    expect(filterCoverageResults(FoodTruckFilter::class, new FoodTruck(), 'int/v1/storefront/food-trucks', ['owned_by_network' => 'network_uuid']))->toBe(['truck_network'])
        ->and(filterCoverageResults(FoodTruckFilter::class, new FoodTruck(), 'int/v1/storefront/food-trucks'))->toBe(['truck_network', 'truck_member_store', 'truck_other_network']);
});
