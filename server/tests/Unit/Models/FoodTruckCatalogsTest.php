<?php

require_once __DIR__ . '/../../Support/ApiCacheTestHelpers.php';

use Fleetbase\Storefront\Models\FoodTruck;
use Illuminate\Database\Eloquent\Model;

function createFoodTruckCatalogSchema(): void
{
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();

    foreach (['catalogs', 'catalog_subjects'] as $table) {
        $schema->dropIfExists($table);
    }

    $schema->create('catalogs', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('name')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('catalog_subjects', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('catalog_uuid')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('created_by_uuid')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });

    $connection->table('catalogs')->insert([
        ['uuid' => '70000000-0000-4000-8000-000000000001', 'public_id' => 'catalog_breakfast', 'name' => 'Breakfast'],
        ['uuid' => '70000000-0000-4000-8000-000000000002', 'public_id' => 'catalog_lunch', 'name' => 'Lunch'],
    ]);
}

test('syncing a truck\'s catalogs answers with the catalogs it now has, not the ones loaded before', function () {
    createFoodTruckCatalogSchema();

    $truck = new FoodTruck();
    $truck->forceFill(['uuid' => '60000000-0000-4000-8000-000000000001', 'company_uuid' => 'company_uuid']);
    $truck->exists = true;

    $truck->setCatalogs(['70000000-0000-4000-8000-000000000001']);
    expect($truck->catalogs->pluck('name')->all())->toBe(['Breakfast']);

    // The relation is loaded now; adding a catalog must not answer with the stale list.
    $truck->setCatalogs([['uuid' => '70000000-0000-4000-8000-000000000001'], ['uuid' => '70000000-0000-4000-8000-000000000002']]);
    expect($truck->catalogs->pluck('name')->sort()->values()->all())->toBe(['Breakfast', 'Lunch']);

    $truck->setCatalogs(['70000000-0000-4000-8000-000000000002']);
    expect($truck->catalogs->pluck('name')->all())->toBe(['Lunch']);
});

test('removing a truck\'s catalogs invalidates the cached lists embedding the assignments', function () {
    createFoodTruckCatalogSchema();

    $truck = new FoodTruck();
    $truck->forceFill(['uuid' => '60000000-0000-4000-8000-000000000001', 'company_uuid' => 'company_uuid']);
    $truck->exists = true;

    $truck->setCatalogs(['70000000-0000-4000-8000-000000000001', '70000000-0000-4000-8000-000000000002']);
    $version = apiQueryCacheVersion('catalog_subjects', 'company_uuid');

    $truck->setCatalogs(['70000000-0000-4000-8000-000000000002']);

    expect($truck->catalogs->pluck('name')->all())->toBe(['Lunch'])
        ->and(apiQueryCacheVersion('catalog_subjects', 'company_uuid'))->toBe($version + 1);
});
