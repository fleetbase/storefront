<?php

use Fleetbase\Storefront\Http\Controllers\ProductController;
use Fleetbase\Storefront\Http\Controllers\v1\ProductController as ApiProductController;
use Fleetbase\Storefront\Models\Product;
use Fleetbase\Storefront\Models\StoreLocation;
use Fleetbase\Storefront\Support\Inventory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** A request that validates itself the way the framework's `validate` macro does. */
class CatalogCoverageValidatingRequest extends Request
{
    public function validate(array $rules, ...$params): array
    {
        return app('validator')->make($this->all(), $rules)->validate();
    }
}

function catalogCoverageDb()
{
    return Model::getConnectionResolver()->connection('mysql');
}

function catalogCoverageRequest(array $input = [], string $method = 'GET'): CatalogCoverageValidatingRequest
{
    return CatalogCoverageValidatingRequest::create('/products', $method, $input);
}

function catalogCoverageSchema(): void
{
    $schema = catalogCoverageDb()->getSchemaBuilder();
    foreach (['inventory_reservations', 'inventory_movements', 'product_inventories', 'products', 'product_hours', 'store_locations', 'store_hours', 'food_trucks', 'places', 'stores', 'companies', 'networks', 'network_stores', 'entities', 'orders'] as $table) {
        $schema->dropIfExists($table);
    }

    $schema->create('products', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id');
        $table->string('company_uuid')->nullable();
        $table->string('store_uuid')->nullable();
        $table->string('category_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('slug')->nullable();
        $table->string('status')->nullable();
        $table->text('meta')->nullable();
        $table->boolean('is_available')->default(true);
        $table->boolean('is_bookable')->default(false);
        $table->boolean('is_on_sale')->default(false);
        $table->boolean('is_recommended')->default(false);
        $table->boolean('track_inventory')->default(false);
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('product_hours', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('product_uuid');
        $table->string('day_of_week');
        $table->string('start');
        $table->string('end');
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('store_locations', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id');
        $table->string('store_uuid')->nullable();
        $table->string('place_uuid')->nullable();
        $table->string('name')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('food_trucks', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id');
        $table->string('store_uuid')->nullable();
        $table->string('vehicle_uuid')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('places', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id')->nullable();
        $table->string('street1')->nullable();
        $table->string('city')->nullable();
        $table->string('country')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('stores', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('timezone')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('networks', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('network_stores', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('network_uuid');
        $table->string('store_uuid');
        $table->string('category_uuid')->nullable();
        $table->string('status')->default('active');
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('companies', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('timezone')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('entities', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('payload_uuid')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('orders', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('payload_uuid')->nullable();
        $table->string('status')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('product_inventories', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('product_uuid');
        $table->string('location_uuid');
        $table->string('location_type')->nullable();
        $table->integer('quantity')->default(0);
        $table->integer('reserved')->default(0);
        $table->integer('low_stock_threshold')->nullable();
        $table->boolean('allow_backorder')->default(false);
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('inventory_movements', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('company_uuid')->nullable();
        $table->string('inventory_uuid');
        $table->string('type');
        $table->integer('quantity');
        $table->integer('quantity_after')->nullable();
        $table->string('reference_uuid')->nullable();
        $table->string('reference_type')->nullable();
        $table->string('actor_uuid')->nullable();
        $table->string('note')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('inventory_reservations', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('company_uuid')->nullable();
        $table->string('inventory_uuid');
        $table->string('checkout_uuid');
        $table->string('order_uuid')->nullable();
        $table->string('cart_item_id')->nullable();
        $table->integer('quantity');
        $table->timestamp('expires_at')->nullable();
        $table->string('status')->default('held');
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
}

function catalogCoverageProduct(string $publicId, array $attributes = []): void
{
    catalogCoverageDb()->table('products')->insert(array_merge([
        'uuid'         => $publicId . '_uuid',
        'public_id'    => $publicId,
        'company_uuid' => 'company_uuid',
        'store_uuid'   => 'store_uuid',
        'name'         => ucfirst(str_replace('product_', '', $publicId)),
        'status'       => 'published',
    ], $attributes));
}

/** A store with one location (with an address) and one food truck. */
function catalogCoverageStore(): void
{
    catalogCoverageDb()->table('stores')->insert(['uuid' => 'store_uuid', 'public_id' => 'store_public', 'company_uuid' => 'company_uuid', 'timezone' => 'Asia/Singapore']);
    catalogCoverageDb()->table('places')->insert(['uuid' => 'place_uuid', 'public_id' => 'place_public', 'street1' => '1 Orchard Road', 'city' => 'Singapore']);
    catalogCoverageDb()->table('store_locations')->insert(['uuid' => 'location_uuid', 'public_id' => 'store_location_main', 'store_uuid' => 'store_uuid', 'place_uuid' => 'place_uuid', 'name' => 'Main']);
    catalogCoverageDb()->table('food_trucks')->insert(['uuid' => 'truck_uuid', 'public_id' => 'food_truck_one', 'store_uuid' => 'store_uuid']);
}

beforeEach(function () {
    catalogCoverageSchema();
    Inventory::flushEnabled();
    session(['company' => 'company_uuid', 'user' => null, 'storefront_store' => null, 'storefront_network' => null]);
    app()->instance('responsecache', new class {
        public function clear(): void
        {
        }
    });
    Model::setEventDispatcher(new Illuminate\Events\Dispatcher(app()));
    Model::clearBootedModels();
});

afterEach(function () {
    session(['storefront_store' => null, 'storefront_network' => null]);
    Carbon::setTestNow();
    Inventory::flushEnabled();
    Model::unsetEventDispatcher();
    Model::clearBootedModels();
});

test('the products summary counts categories, statuses and smart groups for a store', function () {
    catalogCoverageStore();
    catalogCoverageProduct('product_sale', ['category_uuid' => 'category_a', 'is_on_sale' => true]);
    catalogCoverageProduct('product_draft', ['category_uuid' => 'category_a', 'status' => 'draft', 'is_recommended' => true]);
    catalogCoverageProduct('product_sold_out', ['track_inventory' => true]);
    catalogCoverageProduct('product_low', ['category_uuid' => 'category_b', 'track_inventory' => true]);
    catalogCoverageProduct('product_elsewhere', ['store_uuid' => 'other_store_uuid']);
    catalogCoverageProduct('product_other_company', ['company_uuid' => 'other_company_uuid']);
    Inventory::set(Product::where('public_id', 'product_low')->first(), 'location_uuid', StoreLocation::class, ['quantity' => 2, 'low_stock_threshold' => 3]);

    $store = (new ProductController())->summary(catalogCoverageRequest(['store_uuid' => 'store_uuid']))->getData(true);
    $all   = (new ProductController())->summary(catalogCoverageRequest())->getData(true);

    expect($store)->toMatchArray([
        'total'         => 4,
        'uncategorized' => 1,
        'published'     => 3,
        'draft'         => 1,
        'out_of_stock'  => 1,
        'low_stock'     => 1,
        'on_sale'       => 1,
        'recommended'   => 1,
    ])
        ->and($store['by_category'])->toEqual(['category_a' => 2, 'category_b' => 1])
        ->and($store['by_status'])->toEqual(['draft' => 1, 'published' => 3])
        // The store is optional; another company's products are never counted.
        ->and($all['total'])->toBe(5)
        ->and($all['uncategorized'])->toBe(2);
});

test('a product\'s stock lists every location and truck of its store with recent changes', function () {
    catalogCoverageStore();
    catalogCoverageProduct('product_tea', ['track_inventory' => true]);
    Inventory::set(Product::where('public_id', 'product_tea')->first(), 'location_uuid', StoreLocation::class, ['quantity' => 4, 'low_stock_threshold' => 5], 'opening count');

    $missing = (new ProductController())->inventory('product_missing');
    $stock   = (new ProductController())->inventory('product_tea')->getData(true);
    $byId    = collect($stock['locations'])->keyBy('location');

    expect($missing->getStatusCode())->toBe(404)
        ->and($missing->getData(true))->toBe(['error' => 'Product not found'])
        ->and($stock['track_inventory'])->toBeTrue()
        ->and($stock['summary'])->toMatchArray(['tracked' => true, 'available_total' => 4, 'low' => true])
        ->and($byId['store_location_main'])->toMatchArray([
            'type'      => 'store_location',
            'name'      => 'Main',
            'quantity'  => 4,
            'reserved'  => 0,
            'available' => 4,
            'low'       => true,
            'stocked'   => true,
        ])
        ->and($byId['store_location_main']['address'])->toBe('1 ORCHARD ROAD - SINGAPORE')
        // A truck without stock is listed, unstocked, under its public id.
        ->and($byId['food_truck_one'])->toMatchArray([
            'type'                => 'food_truck',
            'name'                => 'food_truck_one',
            'address'             => null,
            'quantity'            => 0,
            'available'           => 0,
            'low_stock_threshold' => null,
            'allow_backorder'     => false,
            'low'                 => false,
            'stocked'             => false,
        ])
        ->and($stock['movements'])->toHaveCount(1)
        ->and($stock['movements'][0])->toMatchArray(['type' => 'restock', 'quantity' => 4, 'quantity_after' => 4, 'location' => 'location_uuid', 'note' => 'opening count']);
});

test('stock is updated per location of the product\'s store, and tracking can be switched', function () {
    catalogCoverageStore();
    catalogCoverageProduct('product_tea');
    $controller = new ProductController();

    $updated = $controller->updateInventory(catalogCoverageRequest([
        'track_inventory' => true,
        'note'            => 'delivery',
        'locations'       => [
            ['location' => 'store_location_main', 'quantity' => 6],
            ['location' => 'food_truck_one', 'quantity' => 2, 'low_stock_threshold' => 2, 'allow_backorder' => true],
        ],
    ], 'PUT'), 'product_tea')->getData(true);
    $byId = collect($updated['locations'])->keyBy('location');

    expect($updated['track_inventory'])->toBeTrue()
        ->and(Product::where('public_id', 'product_tea')->value('track_inventory'))->toBeTruthy()
        ->and($byId['store_location_main']['quantity'])->toBe(6)
        ->and($byId['food_truck_one'])->toMatchArray(['quantity' => 2, 'low_stock_threshold' => 2, 'allow_backorder' => true, 'low' => true])
        ->and(collect($updated['movements'])->pluck('note')->unique()->all())->toBe(['delivery'])
        ->and($updated['summary']['available_total'])->toBe(8);

    $foreign = $controller->updateInventory(catalogCoverageRequest(['locations' => [['location' => 'store_location_elsewhere', 'quantity' => 1]]], 'PUT'), 'product_tea');
    $missing = $controller->updateInventory(catalogCoverageRequest(['track_inventory' => false], 'PUT'), 'product_missing');

    expect($foreign->getStatusCode())->toBe(422)
        ->and($foreign->getData(true))->toBe(['error' => 'Location store_location_elsewhere does not belong to this store.'])
        ->and($missing->getStatusCode())->toBe(404)
        ->and(fn () => $controller->updateInventory(catalogCoverageRequest(['locations' => [['quantity' => 'many']]], 'PUT'), 'product_tea'))
        ->toThrow(ValidationException::class);
});

test('the console lists a service\'s free slots for a day', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-11 20:00:00', 'Asia/Singapore'));
    catalogCoverageStore();
    catalogCoverageProduct('product_massage', ['is_bookable' => true, 'meta' => json_encode(['service' => ['duration_minutes' => 90, 'capacity' => 2]])]);
    catalogCoverageDb()->table('product_hours')->insert(['product_uuid' => 'product_massage_uuid', 'day_of_week' => 'monday', 'start' => '09:00:00', 'end' => '12:00:00']);
    $controller = new ProductController();

    $slots = $controller->slots(catalogCoverageRequest(['date' => '2026-10-12']), 'product_massage')->getData(true);

    expect($slots['date'])->toBe('2026-10-12')
        ->and($slots['service'])->toMatchArray(['duration_minutes' => 90, 'capacity' => 2])
        ->and(array_column($slots['slots'], 'time'))->toBe(['09:00', '10:30'])
        ->and($slots['slots'][0]['start'])->toBe('2026-10-12T09:00:00+08:00')
        ->and($controller->slots(catalogCoverageRequest(['date' => '2026-10-12']), 'product_missing')->getStatusCode())->toBe(404)
        ->and(fn () => $controller->slots(catalogCoverageRequest(['date' => '12/10/2026']), 'product_massage'))->toThrow(ValidationException::class);
});

test('the storefront api lists slots only for bookable services of the storefront in session', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-11 20:00:00', 'Asia/Singapore'));
    catalogCoverageStore();
    catalogCoverageProduct('product_massage', ['is_bookable' => true]);
    catalogCoverageProduct('product_tea');
    catalogCoverageDb()->table('product_hours')->insert(['product_uuid' => 'product_massage_uuid', 'day_of_week' => 'monday', 'start' => '09:00:00', 'end' => '11:00:00']);
    catalogCoverageDb()->table('networks')->insert(['uuid' => 'network_uuid', 'public_id' => 'network_public']);
    catalogCoverageDb()->table('network_stores')->insert(['network_uuid' => 'network_uuid', 'store_uuid' => 'store_uuid']);
    $controller = new ApiProductController();
    $request    = catalogCoverageRequest(['date' => '2026-10-12']);

    session(['storefront_store' => 'store_uuid']);
    $fromStore = $controller->slots($request, 'product_massage')->getData(true);
    $product   = $controller->slots($request, 'product_tea');
    session(['storefront_store' => 'other_store_uuid']);
    $otherStore = $controller->slots($request, 'product_massage');
    session(['storefront_store' => null, 'storefront_network' => 'network_uuid']);
    $fromNetwork = $controller->slots($request, 'product_massage_uuid')->getData(true);
    session(['storefront_network' => 'other_network_uuid']);
    $otherNetwork = $controller->slots($request, 'product_massage');

    expect(array_column($fromStore['slots'], 'time'))->toBe(['09:00', '10:00'])
        ->and($fromStore['service'])->toMatchArray(['duration_minutes' => 60, 'capacity' => 1])
        ->and($fromNetwork['date'])->toBe('2026-10-12')
        ->and($fromNetwork['slots'])->toHaveCount(2)
        ->and($product->getStatusCode())->toBe(404)
        ->and($product->getData(true))->toBe(['error' => 'Bookable service not found.'])
        ->and($otherStore->getStatusCode())->toBe(404)
        ->and($otherNetwork->getStatusCode())->toBe(404)
        ->and(fn () => $controller->slots(catalogCoverageRequest([]), 'product_massage'))->toThrow(ValidationException::class);
});
