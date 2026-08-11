<?php

use Fleetbase\Storefront\Models\Cart;
use Fleetbase\Storefront\Models\Product;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Models\StoreLocation;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Schema\Blueprint;

class CartLifecycleStub extends Cart
{
    public static ?Product $resolvedProduct = null;

    public static function findProduct(string $id): ?Product
    {
        return static::$resolvedProduct?->public_id === $id ? static::$resolvedProduct : null;
    }
}

function createCartLifecycleSchema(): void
{
    $schema = Capsule::schema('mysql');
    $schema->dropIfExists('store_locations');
    $schema->dropIfExists('stores');
    $schema->dropIfExists('carts');
    $schema->create('stores', function (Blueprint $table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('store_locations', function (Blueprint $table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('store_uuid');
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('carts', function (Blueprint $table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('checkout_uuid')->nullable();
        $table->string('customer_id')->nullable();
        $table->string('unique_identifier')->nullable();
        $table->string('currency')->nullable();
        $table->string('discount_code')->nullable();
        $table->text('items')->nullable();
        $table->text('events')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
}

function cartWithItems(): Cart
{
    $cart = new Cart();
    $cart->forceFill([
        'currency' => 'USD',
        'items'    => [
            [
                'id'       => 'cart_item_1',
                'store_id' => 'store_alpha',
                'quantity' => 2,
                'subtotal' => 2500,
            ],
            [
                'id'       => 'cart_item_2',
                'store_id' => 'store_beta',
                'quantity' => 1,
                'subtotal' => 1750,
            ],
            [
                'id'       => 'cart_item_3',
                'store_id' => 'store_alpha',
                'quantity' => 3,
                'subtotal' => 900,
            ],
        ],
        'events' => [
            ['event' => 'cart.created', 'time' => 100],
            ['event' => 'cart.item_added', 'time' => 200],
        ],
    ]);

    return $cart;
}

test('cart serializes items and events while exposing commerce totals', function () {
    $cart = cartWithItems();

    expect($cart->getAttributes()['items'])->toBeJson()
        ->and($cart->getAttributes()['events'])->toBeJson()
        ->and($cart->items)->toHaveCount(3)
        ->and($cart->events)->toHaveCount(2)
        ->and($cart->subtotal)->toBe(5150)
        ->and($cart->total_items)->toBe(6)
        ->and($cart->total_unique_items)->toBe(3)
        ->and($cart->last_event->event)->toBe('cart.item_added')
        ->and($cart->is_multi_cart)->toBeTrue()
        ->and($cart->checkout_store_id)->toBe('store_alpha')
        ->and($cart->checkout_store_ids)->toBe(['store_alpha', 'store_beta']);
});

test('cart accessors accept already-decoded values and empty state', function () {
    $cart = new Cart();

    expect($cart->getItemsAttribute([(object) ['id' => 'item_1']]))->toHaveCount(1)
        ->and($cart->getEventsAttribute([(object) ['event' => 'created']]))->toHaveCount(1);

    $cart->setRawAttributes([
        'items'  => '[]',
        'events' => '[]',
    ]);

    expect($cart->items)->toBe([])
        ->and($cart->events)->toBe([])
        ->and($cart->subtotal)->toBe(0)
        ->and($cart->total_items)->toBe(0)
        ->and($cart->total_unique_items)->toBe(0)
        ->and($cart->last_event)->toBeNull()
        ->and($cart->is_multi_cart)->toBeFalse()
        ->and($cart->checkout_store_id)->toBeNull()
        ->and($cart->checkout_store_ids)->toBe([]);
});

test('cart scopes item and subtotal views to a storefront identifier or model', function () {
    $cart  = cartWithItems();
    $store = new Store();
    $store->forceFill(['public_id' => 'store_alpha']);

    expect(array_values($cart->getItemsForStore('store_alpha')))->toHaveCount(2)
        ->and(array_values($cart->getItemsForStore($store)))->toHaveCount(2)
        ->and($cart->getSubtotalForStore('store_alpha'))->toBe(3400)
        ->and($cart->getSubtotalForStore('store_beta'))->toBe(1750)
        ->and($cart->getSubtotalForStore('store_missing'))->toBe(0);
});

test('cart finds items and indexes and records unsaved domain events', function () {
    $cart = cartWithItems();

    expect($cart->findCartItem('cart_item_2')->store_id)->toBe('store_beta')
        ->and($cart->findCartItem('missing'))->toBeNull()
        ->and($cart->findCartItemIndex('cart_item_3'))->toBe(2)
        ->and($cart->findCartItemIndex('missing'))->toBe(-1)
        ->and($cart->createEvent('cart.discount_applied', 'cart_item_1', false))->toBe($cart)
        ->and($cart->last_event->event)->toBe('cart.discount_applied')
        ->and($cart->last_event->cart_item_id)->toBe('cart_item_1');
});

test('cart currency behavior uses explicit session and caller fallback values', function () {
    session(['storefront_currency' => 'MNT']);

    $cart = new Cart();

    expect($cart->updateCurrency(null, false))->toBe($cart)
        ->and($cart->currency)->toBe('MNT')
        ->and($cart->getCurrency('USD'))->toBe('MNT');

    $cart->updateCurrency('EUR', false);

    expect($cart->currency)->toBe('EUR')
        ->and($cart->getCurrency('USD'))->toBe('EUR');

    session(['storefront_currency' => null]);
    $cart->setRawAttributes([]);

    expect($cart->getCurrency('USD'))->toBe('USD');
});

test('cart calculates product subtotals across sale variant and addon pricing', function () {
    $product = new Product();
    $product->forceFill([
        'price'      => 1000,
        'sale_price' => 800,
        'is_on_sale' => true,
    ]);

    expect(Cart::calculateProductSubtotal(
        $product,
        2,
        [
            ['additional_cost' => 100],
            ['additional_cost' => 50],
        ],
        [
            ['price' => 200, 'is_on_sale' => false],
            ['price' => 300, 'sale_price' => 125, 'is_on_sale' => true],
        ]
    ))->toBe(2550);

    $product->is_on_sale = false;

    expect(Cart::calculateProductSubtotal($product, 3))->toBe(3000);
});

test('cart rejects unsupported product and line-item inputs', function () {
    expect(fn () => (new Cart())->add(new stdClass()))
        ->toThrow(Exception::class, 'Invalid product provided to cart!');

    expect(fn () => (new Cart())->updateItem([]))
        ->toThrow(Exception::class, 'Invalid cart item provided to cart!');

    expect(fn () => (new Cart())->remove([]))
        ->toThrow(Exception::class, 'Invalid cart item provided to cart!');
});

test('cart exposes its package and cross-package relationship contracts', function () {
    $cart = new Cart();

    expect($cart->company()->getForeignKeyName())->toBe('company_uuid')
        ->and($cart->user()->getForeignKeyName())->toBe('user_uuid')
        ->and($cart->customer()->getForeignKeyName())->toBe('public_id')
        ->and($cart->checkout()->getForeignKeyName())->toBe('checkout_uuid');
});

test('cart persists add update remove empty event and currency lifecycle behavior', function () {
    createCartLifecycleSchema();
    Capsule::connection('mysql')->table('carts')->insert([
        'uuid'       => 'cart_uuid',
        'public_id'  => 'cart_public',
        'items'      => '[]',
        'events'     => '[]',
        'currency'   => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    Capsule::connection('mysql')->table('stores')->insert([
        'uuid'       => 'store_uuid',
        'public_id'  => 'store_public',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    Capsule::connection('mysql')->table('store_locations')->insert([
        'uuid'       => 'location_uuid',
        'public_id'  => 'location_public',
        'store_uuid' => 'store_uuid',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $store = new Store();
    $store->forceFill(['public_id' => 'store_public']);
    $location = new StoreLocation();
    $location->forceFill(['public_id' => 'location_default']);
    $store->setRelation('locations', new EloquentCollection([$location]));
    $product = new Product();
    $product->forceFill([
        'public_id'  => 'product_public',
        'store_uuid' => 'store_uuid',
        'name'       => 'Express Delivery',
        'description'=> 'Same-day service',
        'price'      => 1000,
        'sale_price' => 800,
        'is_on_sale' => true,
        'currency'   => 'MNT',
        'meta'       => ['fragile' => true],
    ]);
    $product->setRelation('store', $store);
    $product->setRelation('primaryImage', null);
    $product->setRelation('files', new EloquentCollection());
    CartLifecycleStub::$resolvedProduct = $product;

    $cart  = CartLifecycleStub::where('uuid', 'cart_uuid')->firstOrFail();
    $added = $cart->add(
        'product_public',
        2,
        [['additional_cost' => 100]],
        [['price' => 200, 'is_on_sale' => false]],
        'food_truck_mobile',
        '2026-07-28 09:00:00',
        123
    );

    expect($added->store_id)->toBe('store_public')
        ->and($added->food_truck_id)->toBe('food_truck_mobile')
        ->and($added->store_location_id)->toBe('location_default')
        ->and($added->price)->toBe(800)
        ->and($added->subtotal)->toBe(2200)
        ->and($added->created_at)->toBe(123)
        ->and($cart->currency)->toBe('MNT')
        ->and($cart->last_event->event)->toBe('cart.item_added');

    $updated = $cart->updateItem(
        $added->id,
        3,
        [['additional_cost' => 50]],
        [],
        '2026-07-29 10:00:00'
    );

    expect($updated->quantity)->toBe(3)
        ->and($updated->subtotal)->toBe(2550)
        ->and($updated->scheduled_at)->toBe('2026-07-29 10:00:00')
        ->and($cart->last_event->event)->toBe('cart.item_updated')
        ->and($cart->updateCartItemById($added->id, 1)->quantity)->toBe(1)
        ->and($cart->remove($added->id))->toBe($cart)
        ->and($cart->items)->toBe([])
        ->and($cart->last_event->event)->toBe('cart.item_removed');

    $direct = $cart->addItem($product, storeLocationId: 'location_public');
    expect($direct->store_location_id)->toBe('location_public')
        ->and($cart->removeItemById($direct->id))->toBe($cart)
        ->and($cart->addItem($product)->store_location_id)->toBe('location_default')
        ->and($cart->createEvent('cart.saved'))->toBe($cart)
        ->and($cart->updateCurrency('EUR', true))->toBe($cart)
        ->and($cart->empty())->toBe($cart)
        ->and($cart->currency)->toBeNull()
        ->and($cart->last_event->event)->toBe('cart.emptied');
});
