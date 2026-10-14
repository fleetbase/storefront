<?php

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Storefront\Models\Cart;
use Fleetbase\Storefront\Models\Checkout;
use Fleetbase\Storefront\Models\InventoryMovement;
use Fleetbase\Storefront\Models\InventoryReservation;
use Fleetbase\Storefront\Models\Product;
use Fleetbase\Storefront\Models\ProductInventory;
use Fleetbase\Storefront\Models\StoreLocation;
use Fleetbase\Storefront\Support\Inventory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

function inventoryDb()
{
    return Model::getConnectionResolver()->connection('mysql');
}

function createInventorySchema(): void
{
    $schema = inventoryDb()->getSchemaBuilder();
    foreach (['inventory_reservations', 'inventory_movements', 'product_inventories', 'products', 'store_locations', 'food_trucks'] as $table) {
        $schema->dropIfExists($table);
    }

    $schema->create('products', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id');
        $table->string('company_uuid')->nullable();
        $table->string('store_uuid')->nullable();
        $table->string('name')->nullable();
        $table->boolean('is_available')->default(true);
        $table->boolean('track_inventory')->default(false);
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    foreach (['store_locations', 'food_trucks'] as $name) {
        $schema->create($name, function ($table) {
            $table->increments('id');
            $table->string('uuid');
            $table->string('public_id');
            $table->string('store_uuid')->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
        });
    }
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

function stockedProduct(int $quantity, ?int $lowAt = null, bool $backorder = false, bool $tracked = true): Product
{
    inventoryDb()->table('products')->insert(['uuid' => 'product_uuid', 'public_id' => 'product_1', 'company_uuid' => 'company_uuid', 'store_uuid' => 'store_uuid', 'name' => 'Tea', 'track_inventory' => $tracked]);
    inventoryDb()->table('store_locations')->insert(['uuid' => 'location_uuid', 'public_id' => 'store_location_1', 'store_uuid' => 'store_uuid']);
    $product = Product::where('uuid', 'product_uuid')->first();
    Inventory::set($product, 'location_uuid', StoreLocation::class, ['quantity' => $quantity, 'low_stock_threshold' => $lowAt, 'allow_backorder' => $backorder]);

    return $product;
}

function stockCheckout(): Checkout
{
    $checkout = new Checkout();
    $checkout->forceFill(['uuid' => (string) Str::uuid()]);

    return $checkout;
}

function stockCart(int $quantity, string $line = 'line_1'): Cart
{
    $cart = new Cart();
    $cart->forceFill(['items' => [(object) ['id' => $line, 'product_id' => 'product_1', 'name' => 'Tea', 'quantity' => $quantity, 'store_location_id' => 'store_location_1']]]);

    return $cart;
}

function stockRecord(): ProductInventory
{
    return ProductInventory::where('product_uuid', 'product_uuid')->first();
}

beforeEach(function () {
    createInventorySchema();
    Inventory::flushEnabled();
    session(['company' => 'company_uuid', 'user' => null]);
    // Stock records get their uuids from model events; core's model observer clears the response cache.
    app()->instance('responsecache', new class {
        public function clear(): void
        {
        }
    });
    Model::setEventDispatcher(new Illuminate\Events\Dispatcher(app()));
    Model::clearBootedModels();
});

afterEach(function () {
    Inventory::flushEnabled();
    Model::unsetEventDispatcher();
    Model::clearBootedModels();
});

test('a checkout holds stock, and a second checkout cannot take what is held', function () {
    stockedProduct(3);

    expect(Inventory::reserve(stockCheckout(), stockCart(2)))->toBe([])
        ->and(stockRecord()->available())->toBe(1)
        ->and(Inventory::reserve(stockCheckout(), stockCart(2, 'line_2')))->toBe([['id' => 'line_2', 'product_id' => 'product_1', 'name' => 'Tea', 'available' => 1]])
        ->and(stockRecord()->reserved)->toBe(2);
});

test('placing the order sells what the checkout held; abandoning it gives it back', function () {
    stockedProduct(5);
    $paid      = stockCheckout();
    $abandoned = stockCheckout();
    Inventory::reserve($paid, stockCart(2));
    Inventory::reserve($abandoned, stockCart(1));

    $order = new Order();
    $order->forceFill(['uuid' => 'order_uuid']);
    Inventory::commit($paid, $order);
    Inventory::release($abandoned);

    expect(stockRecord()->quantity)->toBe(3)
        ->and(stockRecord()->reserved)->toBe(0)
        ->and(InventoryReservation::where('checkout_uuid', $paid->uuid)->value('status'))->toBe('committed')
        ->and(InventoryReservation::where('checkout_uuid', $abandoned->uuid)->value('status'))->toBe('released')
        ->and(InventoryMovement::where('reference_uuid', 'order_uuid')->where('type', 'sale')->sum('quantity'))->toBe(-2);
});

test('holds expire, and a paid checkout whose hold expired still sells', function () {
    stockedProduct(2);
    $checkout = stockCheckout();
    Inventory::reserve($checkout, stockCart(2));
    InventoryReservation::query()->update(['expires_at' => now()->subMinute()]);

    expect(Inventory::releaseExpired())->toBe(1)
        ->and(stockRecord()->reserved)->toBe(0);

    $order = new Order();
    $order->forceFill(['uuid' => 'order_uuid']);
    Inventory::commit($checkout, $order);

    expect(stockRecord()->quantity)->toBe(0)
        ->and(stockRecord()->reserved)->toBe(0);
});

test('backorders sell past zero and untracked products are never limited', function () {
    stockedProduct(0, null, true);

    expect(Inventory::reserve(stockCheckout(), stockCart(4)))->toBe([]);

    createInventorySchema();
    stockedProduct(0, null, false, false);

    expect(Inventory::reserve(stockCheckout(), stockCart(4)))->toBe([])
        ->and(InventoryReservation::count())->toBe(0)
        ->and(Inventory::summary(Product::first()))->toMatchArray(['tracked' => false, 'in_stock' => true]);
});

test('smart groups: low at the threshold, out when nothing can be sold', function () {
    $product = stockedProduct(2, 2);

    expect(Product::lowStock()->count())->toBe(1)
        ->and(Product::outOfStock()->count())->toBe(0)
        ->and(Inventory::summary($product))->toMatchArray(['tracked' => true, 'in_stock' => true, 'available_total' => 2, 'low' => true]);

    Inventory::reserve(stockCheckout(), stockCart(2));

    expect(Product::outOfStock()->count())->toBe(1)
        ->and(Product::lowStock()->count())->toBe(0);
});

test('edits put removed items back and take added ones', function () {
    stockedProduct(5);
    $order = new Order();
    $order->forceFill(['uuid' => 'order_uuid']);

    Inventory::applyEdit($order, ['product_1' => -2]);
    expect(stockRecord()->quantity)->toBe(7);

    Inventory::applyEdit($order, ['product_1' => 3]);
    expect(stockRecord()->quantity)->toBe(4)
        ->and(InventoryMovement::where('type', 'edit')->pluck('quantity')->all())->toBe([2, -3]);
});

test('setting the count by hand records a restock or an adjustment', function () {
    $product = stockedProduct(5);
    Inventory::set($product, 'location_uuid', StoreLocation::class, ['quantity' => 3], 'damaged');

    expect(InventoryMovement::orderBy('id')->pluck('type')->all())->toBe(['restock', 'adjustment'])
        ->and(InventoryMovement::orderByDesc('id')->value('note'))->toBe('damaged')
        ->and(stockRecord()->quantity)->toBe(3);
});
