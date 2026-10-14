<?php

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Storefront\Http\Filter\OrderFilter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Seed a few storefront orders that differ by customer, checkout, network and food truck,
 * so each filter is checked by the rows it returns.
 */
function storefrontOrderFilterCoverageSeed(): void
{
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('orders');
    $schema->dropIfExists('contacts');
    $schema->create('orders', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('customer_uuid')->nullable();
        $table->string('customer_type')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('contacts', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });

    $connection->table('contacts')->insert([
        ['uuid' => '4b7f1d2e-0a6c-4e57-9d8b-3f0c1a2b3c4d', 'public_id' => 'contact_alice'],
        ['uuid' => 'c3d1f0a2-7b5e-4c88-a1f9-0e2d3c4b5a69', 'public_id' => 'contact_bruno'],
    ]);
    $connection->table('orders')->insert([
        [
            'uuid'          => 'order_a_uuid',
            'public_id'     => 'order_alice_checkout',
            'customer_uuid' => '4b7f1d2e-0a6c-4e57-9d8b-3f0c1a2b3c4d',
            'meta'          => json_encode(['storefront_id' => 'store_one', 'checkout_id' => 'chkt_multi', 'storefront_network_id' => 'network_market']),
        ],
        [
            'uuid'          => 'order_b_uuid',
            'public_id'     => 'order_alice_master',
            'customer_uuid' => '4b7f1d2e-0a6c-4e57-9d8b-3f0c1a2b3c4d',
            'meta'          => json_encode(['storefront_id' => 'store_two', 'checkout' => 'chkt_multi', 'food_truck_id' => 'food_truck_taco']),
        ],
        [
            'uuid'          => 'order_c_uuid',
            'public_id'     => 'order_bruno',
            'customer_uuid' => 'c3d1f0a2-7b5e-4c88-a1f9-0e2d3c4b5a69',
            'meta'          => json_encode(['storefront_id' => 'store_one', 'checkout_id' => 'chkt_single', 'food_truck_id' => 'food_truck_bao']),
        ],
    ]);
}

/** The public ids of the orders the storefront order filter keeps for the given query. */
function storefrontOrderFilterCoverageIds(array $query): array
{
    $request = Request::create('/storefront/orders', 'GET', $query);
    $builder = (new OrderFilter($request))->apply((new Order())->newQuery());

    return $builder->orderBy('id')->pluck('public_id')->all();
}

test('the storefront order filter finds a customer orders by contact public id or uuid', function () {
    storefrontOrderFilterCoverageSeed();

    expect(storefrontOrderFilterCoverageIds(['customer' => 'contact_alice']))->toBe(['order_alice_checkout', 'order_alice_master'])
        ->and(storefrontOrderFilterCoverageIds(['customer' => 'c3d1f0a2-7b5e-4c88-a1f9-0e2d3c4b5a69']))->toBe(['order_bruno'])
        // an unknown public id is matched as given, so nothing is found
        ->and(storefrontOrderFilterCoverageIds(['customer' => 'contact_nobody']))->toBe([])
        ->and(storefrontOrderFilterCoverageIds(['customer_uuid' => '4b7f1d2e-0a6c-4e57-9d8b-3f0c1a2b3c4d']))->toBe(['order_alice_checkout', 'order_alice_master']);
});

test('the storefront order filter narrows to a food truck, a checkout, a store and a network', function () {
    storefrontOrderFilterCoverageSeed();

    expect(storefrontOrderFilterCoverageIds(['food_truck' => 'food_truck_bao']))->toBe(['order_bruno'])
        // a multi-store checkout keeps its stores' orders (checkout_id) and the master (checkout)
        ->and(storefrontOrderFilterCoverageIds(['checkout' => 'chkt_multi']))->toBe(['order_alice_checkout', 'order_alice_master'])
        ->and(storefrontOrderFilterCoverageIds(['checkout' => 'chkt_single']))->toBe(['order_bruno'])
        ->and(storefrontOrderFilterCoverageIds(['storefront' => 'store_one']))->toBe(['order_alice_checkout', 'order_bruno'])
        ->and(storefrontOrderFilterCoverageIds(['network' => 'network_market']))->toBe(['order_alice_checkout'])
        ->and(storefrontOrderFilterCoverageIds(['network' => 'network_market', 'checkout' => 'chkt_single']))->toBe([]);
});
