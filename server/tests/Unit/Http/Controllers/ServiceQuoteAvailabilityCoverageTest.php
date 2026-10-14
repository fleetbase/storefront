<?php

use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Support\DistanceMatrix;
use Fleetbase\Storefront\Http\Controllers\v1\ServiceQuoteController;
use Fleetbase\Storefront\Http\Requests\GetServiceQuoteFromCart;
use Fleetbase\Storefront\Models\Cart;
use Illuminate\Database\Eloquent\Model;

class ServiceQuoteAvailabilityProbe extends ServiceQuoteController
{
    public function unavailable(?Cart $cart, string|array|null $origin = null)
    {
        return $this->unavailableCartItems($cart, $origin);
    }

    public function networkMatrix($origins, Place $destination): object
    {
        return $this->getNetworkDistanceMatrix($origins, $destination);
    }
}

function quoteCoverageDb()
{
    return Model::getConnectionResolver()->connection('mysql');
}

function quoteCoverageSchema(): void
{
    $schema = quoteCoverageDb()->getSchemaBuilder();
    foreach (['carts', 'products', 'food_trucks', 'store_locations', 'places'] as $table) {
        $schema->dropIfExists($table);
    }

    $schema->create('carts', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('checkout_uuid')->nullable();
        $table->string('status')->nullable();
        $table->string('customer_id')->nullable();
        $table->string('unique_identifier')->nullable();
        $table->string('currency')->nullable();
        $table->string('discount_code')->nullable();
        $table->text('items')->nullable();
        $table->text('events')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('products', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('name')->nullable();
        $table->boolean('is_available')->default(true);
        $table->string('status')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('food_trucks', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('store_locations', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('store_uuid')->nullable();
        $table->string('place_uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('places', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });

    quoteCoverageDb()->table('products')->insert([
        ['uuid' => 'tea_uuid', 'public_id' => 'product_tea', 'name' => 'Tea', 'is_available' => true, 'status' => 'published'],
        ['uuid' => 'cake_uuid', 'public_id' => 'product_cake', 'name' => 'Cake', 'is_available' => false, 'status' => 'published'],
        ['uuid' => 'pie_uuid', 'public_id' => 'product_pie', 'name' => 'Pie', 'is_available' => true, 'status' => 'draft'],
    ]);
    quoteCoverageDb()->table('food_trucks')->insert(['uuid' => 'truck_uuid', 'public_id' => 'food_truck_here']);
    quoteCoverageDb()->table('store_locations')->insert(['uuid' => 'location_uuid', 'public_id' => 'store_location_here', 'store_uuid' => 'store_uuid']);
}

function quoteCoverageCart(array $items): Cart
{
    $cart = new Cart();
    $cart->forceFill(['items' => array_map(fn ($item) => (object) $item, $items)]);

    return $cart;
}

beforeEach(function () {
    quoteCoverageSchema();
});

afterEach(function () {
    session(['storefront_key' => null]);
});

test('cart lines whose product, truck or location is gone are named as unavailable', function () {
    $probe = new ServiceQuoteAvailabilityProbe();
    $cart  = quoteCoverageCart([
        ['id' => 'line_ok', 'product_id' => 'product_tea', 'name' => 'Tea', 'food_truck_id' => 'food_truck_here'],
        ['id' => 'line_deleted', 'product_id' => 'product_gone', 'name' => 'Gone'],
        ['id' => 'line_off', 'product_id' => 'product_cake', 'name' => 'Cake'],
        ['id' => 'line_draft', 'product_id' => 'product_pie', 'name' => 'Pie'],
        ['id' => 'line_truck', 'product_id' => 'product_tea', 'name' => 'Tea', 'food_truck_id' => 'food_truck_gone'],
        ['id' => 'line_location', 'product_id' => 'product_tea', 'name' => 'Tea', 'store_location_id' => 'store_location_gone'],
        ['id' => 'line_located', 'product_id' => 'product_tea', 'name' => 'Tea', 'store_location_id' => 'store_location_here'],
    ]);

    $response = $probe->unavailable($cart, 'food_truck_here');

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['code'])->toBe('cart_items_unavailable')
        ->and($response->getData(true)['errors'])->toBe(['Some items in your cart are no longer available.'])
        ->and(array_column($response->getData(true)['items'], 'id'))->toBe(['line_deleted', 'line_off', 'line_draft', 'line_truck', 'line_location'])
        ->and($response->getData(true)['items'][0])->toBe(['id' => 'line_deleted', 'product_id' => 'product_gone', 'name' => 'Gone'])
        ->and($probe->unavailable(quoteCoverageCart([['id' => 'line_ok', 'product_id' => 'product_tea', 'food_truck_id' => 'food_truck_here']]), 'food_truck_here'))->toBeNull()
        ->and($probe->unavailable(null))->toBeNull();
});

test('a truck the app delivers from that is gone makes its lines, or the cart, unavailable', function () {
    $probe = new ServiceQuoteAvailabilityProbe();

    $fromGoneTruck = $probe->unavailable(quoteCoverageCart([
        ['id' => 'line_truck', 'product_id' => 'product_tea', 'name' => 'Tea', 'food_truck_id' => 'food_truck_gone'],
    ]), ['food_truck_gone']);
    $noLineNamesIt = $probe->unavailable(quoteCoverageCart([
        ['id' => 'line_ok', 'product_id' => 'product_tea', 'name' => 'Tea'],
    ]), 'food_truck_gone');

    expect(array_column($fromGoneTruck->getData(true)['items'], 'id'))->toBe(['line_truck'])
        ->and($noLineNamesIt->getStatusCode())->toBe(422)
        ->and($noLineNamesIt->getData(true)['items'])->toBe([]);
});

test('a quote for a cart with unavailable lines is refused before any address is looked up', function () {
    quoteCoverageDb()->table('carts')->insert([
        'uuid'              => 'cart_uuid',
        'public_id'         => 'cart_coverage',
        'company_uuid'      => 'company_uuid',
        'unique_identifier' => 'browser-cart-coverage',
        'currency'          => 'USD',
        'items'             => json_encode([['id' => 'line_off', 'product_id' => 'product_cake', 'name' => 'Cake']]),
        'events'            => '[]',
    ]);
    session(['company' => 'company_uuid', 'storefront_key' => 'store_public', 'storefront_store' => null]);

    $response = (new ServiceQuoteController())->fromCart(GetServiceQuoteFromCart::create('/quote', 'POST', [
        'origin'      => 'place_missing',
        'destination' => 'place_missing',
        'cart'        => 'browser-cart-coverage',
    ]));

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['code'])->toBe('cart_items_unavailable')
        ->and($response->getData(true)['items'])->toBe([['id' => 'line_off', 'product_id' => 'product_cake', 'name' => 'Cake']]);
});

test('a network route without stores to collect from has no distance', function () {
    $matrix = (new ServiceQuoteAvailabilityProbe())->networkMatrix([null, null], new Place());

    expect($matrix)->toBeInstanceOf(DistanceMatrix::class)
        ->and((float) $matrix->distance)->toBe(0.0)
        ->and((float) $matrix->time)->toBe(0.0);
});
