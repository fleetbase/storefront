<?php

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Storefront\Console\Commands\ReleaseInventoryReservations;
use Fleetbase\Storefront\Models\Cart;
use Fleetbase\Storefront\Models\Checkout;
use Fleetbase\Storefront\Models\FoodTruck;
use Fleetbase\Storefront\Models\InventoryMovement;
use Fleetbase\Storefront\Models\InventoryReservation;
use Fleetbase\Storefront\Models\Product;
use Fleetbase\Storefront\Models\ProductInventory;
use Fleetbase\Storefront\Models\StoreLocation;
use Fleetbase\Storefront\Support\Inventory;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class InventoryCoverageProbe extends Inventory
{
    public static function releaseGiven(Collection $reservations): int
    {
        return static::releaseReservations($reservations);
    }
}

function inventoryCoverageDb()
{
    return Model::getConnectionResolver()->connection('mysql');
}

function inventoryCoverageSchema(): void
{
    $schema = inventoryCoverageDb()->getSchemaBuilder();
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

function inventoryCoverageProduct(string $publicId = 'product_1', bool $tracked = true): Product
{
    inventoryCoverageDb()->table('products')->insert(['uuid' => $publicId . '_uuid', 'public_id' => $publicId, 'company_uuid' => 'company_uuid', 'store_uuid' => 'store_uuid', 'name' => 'Tea', 'track_inventory' => $tracked]);

    return Product::where('public_id', $publicId)->first();
}

function inventoryCoverageStocked(int $quantity): Product
{
    $product = inventoryCoverageProduct();
    inventoryCoverageDb()->table('store_locations')->insert(['uuid' => 'location_uuid', 'public_id' => 'store_location_1', 'store_uuid' => 'store_uuid']);
    Inventory::set($product, 'location_uuid', StoreLocation::class, ['quantity' => $quantity]);

    return $product;
}

function inventoryCoverageCheckout(): Checkout
{
    $checkout = new Checkout();
    $checkout->forceFill(['uuid' => (string) Str::uuid()]);

    return $checkout;
}

function inventoryCoverageCart(int $quantity, string $locationId = 'store_location_1'): Cart
{
    $cart = new Cart();
    $cart->forceFill(['items' => [(object) ['id' => 'line_1', 'product_id' => 'product_1', 'name' => 'Tea', 'quantity' => $quantity, 'store_location_id' => $locationId]]]);

    return $cart;
}

function inventoryCoverageOrder(string $uuid = 'order_uuid'): Order
{
    $order = new Order();
    $order->forceFill(['uuid' => $uuid, 'meta' => []]);

    return $order;
}

function inventoryCoverageRecord(): ProductInventory
{
    return ProductInventory::where('product_uuid', 'product_1_uuid')->first();
}

beforeEach(function () {
    inventoryCoverageSchema();
    Inventory::flushEnabled();
    session(['company' => 'company_uuid', 'user' => null]);
    app()->instance('responsecache', new class {
        public function clear(): void
        {
        }
    });
    Model::setEventDispatcher(new Illuminate\Events\Dispatcher(app()));
    Model::clearBootedModels();
});

afterEach(function () {
    config(['storefront.connection.db' => null]);
    Inventory::flushEnabled();
    Model::unsetEventDispatcher();
    Model::clearBootedModels();
});

test('stock is left alone when its tables cannot be reached', function () {
    inventoryCoverageStocked(4);
    $order = inventoryCoverageOrder();

    // An unknown connection makes the table check throw: stock counts as not set up.
    config(['storefront.connection.db' => 'storefront_coverage_missing_connection']);
    Inventory::flushEnabled();

    $checkout = inventoryCoverageCheckout();

    expect(Inventory::enabled())->toBeFalse()
        ->and(Inventory::reserve($checkout, inventoryCoverageCart(2)))->toBe([])
        ->and(Inventory::release($checkout))->toBe(0)
        ->and(Inventory::releaseExpired())->toBe(0)
        ->and(Inventory::restoreForOrder($order))->toBe(0);

    Inventory::applyEdit($order, ['product_1' => 2]);
    Inventory::commit($checkout, $order);

    config(['storefront.connection.db' => null]);
    Inventory::flushEnabled();

    expect(Inventory::enabled())->toBeTrue()
        ->and(inventoryCoverageRecord()->quantity)->toBe(4)
        ->and(inventoryCoverageRecord()->reserved)->toBe(0)
        ->and(InventoryReservation::query()->count())->toBe(0)
        ->and(InventoryMovement::whereIn('type', ['edit', 'sale'])->count())->toBe(0);
});

test('committing a checkout that held nothing, or whose stock record is gone, sells nothing', function () {
    inventoryCoverageStocked(5);
    $order = inventoryCoverageOrder();

    Inventory::commit(inventoryCoverageCheckout(), $order);
    expect(InventoryMovement::where('type', 'sale')->count())->toBe(0);

    $checkout = inventoryCoverageCheckout();
    Inventory::reserve($checkout, inventoryCoverageCart(2));
    inventoryCoverageDb()->table('product_inventories')->delete();

    Inventory::commit($checkout, $order);

    expect(InventoryReservation::where('checkout_uuid', $checkout->uuid)->value('status'))->toBe('held')
        ->and(InventoryMovement::where('type', 'sale')->count())->toBe(0);
});

test('a canceled order puts what it sold back on the shelf once', function () {
    inventoryCoverageStocked(5);
    $checkout = inventoryCoverageCheckout();
    $order    = inventoryCoverageOrder();
    Inventory::reserve($checkout, inventoryCoverageCart(2));
    Inventory::commit($checkout, $order);
    Inventory::applyEdit($order, ['product_1' => 1]);

    expect(inventoryCoverageRecord()->quantity)->toBe(2);

    expect(Inventory::restoreForOrder($order))->toBe(1)
        ->and(inventoryCoverageRecord()->quantity)->toBe(5)
        ->and(InventoryMovement::where('type', 'cancellation')->value('quantity'))->toBe(3)
        ->and(InventoryMovement::where('type', 'cancellation')->value('quantity_after'))->toBe(5)
        ->and($order->getMeta('inventory_restored_at'))->not->toBeNull()
        // Done once: a second cancel does not restock again.
        ->and(Inventory::restoreForOrder($order))->toBe(0)
        ->and(inventoryCoverageRecord()->quantity)->toBe(5);
});

test('an order that sold no tracked stock restores nothing and is not marked', function () {
    inventoryCoverageStocked(5);
    $order = inventoryCoverageOrder('order_without_stock');

    expect(Inventory::restoreForOrder($order))->toBe(0)
        ->and($order->getMeta('inventory_restored_at'))->toBeNull()
        ->and(inventoryCoverageRecord()->quantity)->toBe(5);
});

test('a sold stock record that was removed is skipped when restoring', function () {
    inventoryCoverageStocked(5);
    $checkout = inventoryCoverageCheckout();
    $order    = inventoryCoverageOrder();
    Inventory::reserve($checkout, inventoryCoverageCart(2));
    Inventory::commit($checkout, $order);
    inventoryCoverageDb()->table('product_inventories')->delete();

    // The sale is still counted, but there is no record to put it back on.
    expect(Inventory::restoreForOrder($order))->toBe(1)
        ->and(InventoryMovement::where('type', 'cancellation')->count())->toBe(0)
        ->and($order->getMeta('inventory_restored_at'))->not->toBeNull();
});

test('edits skip unchanged, unknown, untracked and unstocked products', function () {
    inventoryCoverageStocked(5);
    inventoryCoverageProduct('product_untracked', false);
    inventoryCoverageProduct('product_unstocked', true);
    $order = inventoryCoverageOrder();

    Inventory::applyEdit($order, [
        'product_1'         => 0,
        'product_missing'   => 3,
        'product_untracked' => 2,
        'product_unstocked' => 1,
    ]);

    expect(inventoryCoverageRecord()->quantity)->toBe(5)
        ->and(InventoryMovement::where('type', 'edit')->count())->toBe(0)
        ->and(ProductInventory::query()->count())->toBe(1);
});

test('a food truck line sells from the truck, and its stock is summarised as a food truck', function () {
    $product = inventoryCoverageProduct();
    inventoryCoverageDb()->table('store_locations')->insert(['uuid' => 'location_uuid', 'public_id' => 'store_location_1', 'store_uuid' => 'store_uuid']);
    inventoryCoverageDb()->table('food_trucks')->insert(['uuid' => 'truck_uuid', 'public_id' => 'food_truck_1', 'store_uuid' => 'store_uuid']);
    Inventory::set($product, 'location_uuid', StoreLocation::class, ['quantity' => 9]);
    $truckStock = Inventory::set($product, 'truck_uuid', FoodTruck::class, ['quantity' => 3, 'low_stock_threshold' => 3]);

    $found   = Inventory::inventoryFor($product, 'food_truck_1');
    $summary = Inventory::summary($product->fresh());
    $truck   = collect($summary['locations'])->firstWhere('location_type', 'food_truck');

    expect($found->uuid)->toBe($truckStock->uuid)
        ->and($found->location_uuid)->toBe('truck_uuid')
        ->and(Inventory::inventoryFor($product, 'food_truck_missing')->location_uuid)->toBe('location_uuid')
        ->and($truck)->toMatchArray(['location' => 'food_truck_1', 'available' => 3, 'low' => true])
        ->and($summary['available_total'])->toBe(12);
});

test('releasing skips holds that were sold or removed meanwhile', function () {
    inventoryCoverageStocked(5);
    $checkout = inventoryCoverageCheckout();
    Inventory::reserve($checkout, inventoryCoverageCart(2));
    $held = InventoryReservation::where('checkout_uuid', $checkout->uuid)->first();

    // Another request sold the hold between the lookup and the release.
    InventoryReservation::where('uuid', $held->uuid)->update(['status' => InventoryReservation::STATUS_COMMITTED]);
    $gone = new InventoryReservation();
    $gone->forceFill(['uuid' => 'reservation_missing']);

    expect(InventoryCoverageProbe::releaseGiven(collect([$held, $gone])))->toBe(0)
        ->and(inventoryCoverageRecord()->reserved)->toBe(2)
        ->and(InventoryMovement::where('type', 'release')->count())->toBe(0);
});

test('stock records, holds and movements resolve their product, location and record', function () {
    $product   = inventoryCoverageStocked(5);
    $checkout  = inventoryCoverageCheckout();
    Inventory::reserve($checkout, inventoryCoverageCart(2));
    $inventory = inventoryCoverageRecord();
    $hold      = InventoryReservation::where('checkout_uuid', $checkout->uuid)->first();
    $movement  = InventoryMovement::where('type', 'reservation')->first();

    expect($inventory->product->uuid)->toBe($product->uuid)
        ->and($inventory->location)->toBeInstanceOf(StoreLocation::class)
        ->and($inventory->location->public_id)->toBe('store_location_1')
        ->and($inventory->movements->pluck('type')->all())->toBe(['restock', 'reservation'])
        ->and($hold->inventory->uuid)->toBe($inventory->uuid)
        ->and($movement->inventory->uuid)->toBe($inventory->uuid);
});

test('the release command gives back expired holds and says how many', function () {
    inventoryCoverageStocked(5);
    $expired = inventoryCoverageCheckout();
    $current = inventoryCoverageCheckout();
    Inventory::reserve($expired, inventoryCoverageCart(2));
    Inventory::reserve($current, inventoryCoverageCart(1));
    InventoryReservation::where('checkout_uuid', $expired->uuid)->update(['expires_at' => now()->subMinute()]);

    $buffer  = new BufferedOutput();
    $command = new ReleaseInventoryReservations();
    $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

    expect($command->handle())->toBe(0)
        ->and($buffer->fetch())->toContain('Released 1 expired stock reservation(s).')
        ->and(inventoryCoverageRecord()->reserved)->toBe(1)
        ->and(InventoryReservation::where('checkout_uuid', $expired->uuid)->value('status'))->toBe('released')
        ->and(InventoryReservation::where('checkout_uuid', $current->uuid)->value('status'))->toBe('held');
});
