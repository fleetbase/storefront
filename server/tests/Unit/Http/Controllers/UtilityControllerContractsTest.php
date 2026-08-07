<?php

use Fleetbase\Storefront\Http\Controllers\FoodTruckController;
use Fleetbase\Storefront\Http\Controllers\StoreController;
use Fleetbase\Storefront\Imports\ProductsImport;
use Fleetbase\Storefront\Models\FoodTruck;
use Fleetbase\Storefront\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store as SessionStore;
use Illuminate\Support\Collection;

test('store controller lists only stores belonging to the request company', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('stores');
    $schema->dropIfExists('reviews');
    $schema->create('stores', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('company_uuid');
        $table->string('name');
        $table->string('description')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('reviews', function ($table) {
        $table->increments('id');
        $table->string('subject_uuid');
        $table->integer('rating');
        $table->timestamp('deleted_at')->nullable();
    });
    Store::withoutEvents(function () use ($connection) {
        $connection->table('stores')->insert([
            [
                'uuid'         => 'store_one',
                'company_uuid' => 'company_uuid',
                'name'         => 'Company store',
                'description'  => 'Visible',
                'created_at'   => now(),
                'updated_at'   => now(),
            ],
            [
                'uuid'         => 'store_other',
                'company_uuid' => 'other_company',
                'name'         => 'Other store',
                'description'  => 'Hidden',
                'created_at'   => now(),
                'updated_at'   => now(),
            ],
        ]);
    });
    $request = Request::create('/stores');
    $session = new SessionStore('storefront-controller-test', new ArraySessionHandler(120));
    $session->put('company', 'company_uuid');
    $request->setLaravelSession($session);

    $response = (new StoreController())->allStores($request);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true)['stores'])->toHaveCount(1)
        ->and($response->getData(true)['stores'][0]['name'])->toBe('Company store');
});

test('food truck controller adds vehicle eager loading to record queries', function () {
    $builder = FoodTruck::query();

    (new FoodTruckController())->onQueryRecord($builder);

    expect($builder->getEagerLoads())->toHaveKey('vehicle');
});

test('products import returns the heading-row collection unchanged', function () {
    $rows   = new Collection([['name' => 'Coffee'], ['name' => 'Tea']]);
    $import = new ProductsImport();

    expect($import->collection($rows))->toBe($rows);
});
