<?php

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Storefront\Http\Resources\Index\Order as IndexOrderResource;
use Fleetbase\Storefront\Http\Resources\Order as OrderResource;
use Fleetbase\Storefront\Models\FoodTruck;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

function foodTruckSummarySchema(): void
{
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    foreach (['food_trucks', 'vehicles'] as $table) {
        $schema->dropIfExists($table);
    }

    $schema->create('food_trucks', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('vehicle_uuid')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('vehicles', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('name')->nullable();
        $table->string('make')->nullable();
        $table->string('model')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    $connection->table('vehicles')->insert([
        ['uuid' => 'vehicle_named', 'name' => 'Taco Truck', 'make' => null, 'model' => null],
        ['uuid' => 'vehicle_unnamed', 'name' => null, 'make' => 'Isuzu', 'model' => 'Elf'],
    ]);
    $connection->table('food_trucks')->insert([
        ['uuid' => 'truck_one', 'public_id' => 'food_truck_one', 'company_uuid' => 'company_uuid', 'vehicle_uuid' => 'vehicle_named'],
        ['uuid' => 'truck_two', 'public_id' => 'food_truck_two', 'company_uuid' => 'company_uuid', 'vehicle_uuid' => 'vehicle_unnamed'],
        ['uuid' => 'truck_bare', 'public_id' => 'food_truck_bare', 'company_uuid' => 'company_uuid', 'vehicle_uuid' => null],
        ['uuid' => 'truck_other', 'public_id' => 'food_truck_other', 'company_uuid' => 'other_company', 'vehicle_uuid' => 'vehicle_named'],
    ]);
}

beforeEach(function () {
    foodTruckSummarySchema();
    // Each test is its own request, so the names looked up for the last one are not reused.
    app()->instance('request', Request::create('/storefront/int/v1/orders'));
});

test('a truck is named by its vehicle, and a page of orders looks its company\'s trucks up once', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    try {
        $summaries = [
            FoodTruck::summaryFor('food_truck_one', 'company_uuid'),
            FoodTruck::summaryFor('food_truck_two', 'company_uuid'),
            FoodTruck::summaryFor('food_truck_bare', 'company_uuid'),
            FoodTruck::summaryFor('food_truck_one', 'company_uuid'),
            // Another company's truck is not among this company's.
            FoodTruck::summaryFor('food_truck_other', 'company_uuid'),
        ];
        $queries = count($connection->getQueryLog());
    } finally {
        $connection->disableQueryLog();
    }

    expect($summaries)->toBe([
        ['id' => 'food_truck_one', 'name' => 'Taco Truck'],
        ['id' => 'food_truck_two', 'name' => 'Isuzu Elf'],
        ['id' => 'food_truck_bare', 'name' => null],
        ['id' => 'food_truck_one', 'name' => 'Taco Truck'],
        ['id' => 'food_truck_other', 'name' => null],
    ])
        // The trucks, then their vehicles: once for the whole page.
        ->and($queries)->toBe(2)
        ->and(FoodTruck::summaryFor('food_truck_other', 'other_company'))->toBe(['id' => 'food_truck_other', 'name' => 'Taco Truck']);
});

test('order lists and the order panel say which truck an order was placed at', function () {
    $truckOrder = (new Order())->forceFill(['company_uuid' => 'company_uuid', 'meta' => ['storefront_id' => 'store_public', 'food_truck_id' => 'food_truck_one']]);
    $storeOrder = (new Order())->forceFill(['company_uuid' => 'company_uuid', 'meta' => ['storefront_id' => 'store_public']]);
    $meta       = function ($resource) {
        return (new ReflectionMethod($resource, 'storefrontOrderMeta'))->invoke($resource);
    };

    expect($meta(new IndexOrderResource($truckOrder)))->toBe([
        'storefront_id' => 'store_public',
        'food_truck_id' => 'food_truck_one',
        'food_truck'    => ['id' => 'food_truck_one', 'name' => 'Taco Truck'],
    ])
        ->and($meta(new OrderResource($truckOrder)))->toMatchArray([
            'food_truck_id' => 'food_truck_one',
            'food_truck'    => ['id' => 'food_truck_one', 'name' => 'Taco Truck'],
        ])
        ->and($meta(new IndexOrderResource($storeOrder)))->not->toHaveKey('food_truck')
        ->and($meta(new OrderResource($storeOrder)))->not->toHaveKey('food_truck');
});
