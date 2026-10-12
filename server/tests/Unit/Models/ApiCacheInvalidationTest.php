<?php

require_once __DIR__ . '/../../Support/ApiCacheTestHelpers.php';

use Fleetbase\Models\Transaction;
use Fleetbase\Storefront\Http\Controllers\CatalogController;
use Fleetbase\Storefront\Http\Controllers\NetworkController;
use Fleetbase\Storefront\Http\Controllers\v1\CheckoutController;
use Fleetbase\Storefront\Http\Requests\NetworkActionRequest;
use Fleetbase\Storefront\Models\Catalog;
use Fleetbase\Storefront\Models\FoodTruck;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Observers\CatalogObserver;
use Illuminate\Database\Eloquent\Model;

function createApiCacheInvalidationSchema(): void
{
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();

    foreach (['network_stores', 'categories', 'catalog_subjects', 'catalog_category_products', 'transactions', 'transaction_items'] as $table) {
        $schema->dropIfExists($table);
    }

    $schema->create('network_stores', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('network_uuid')->nullable();
        $table->string('store_uuid')->nullable();
        $table->string('category_uuid')->nullable();
        $table->string('status')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('categories', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->string('owner_type')->nullable();
        $table->string('name')->nullable();
        $table->string('for')->nullable();
        $table->timestamps();
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
    $schema->create('catalog_category_products', function ($table) {
        $table->string('uuid')->nullable();
        $table->string('catalog_category_uuid')->nullable();
        $table->string('product_uuid')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('transactions', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('transaction_items', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('transaction_uuid')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
}

test('network membership changes made through the query builder invalidate the cached network store lists', function () {
    createApiCacheInvalidationSchema();
    session(['company' => 'company_network']);
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('network_stores')->insert([
        ['network_uuid' => 'network_uuid', 'store_uuid' => 'store_a', 'category_uuid' => 'category_uuid', 'status' => 'pending'],
        ['network_uuid' => 'network_uuid', 'store_uuid' => 'store_b', 'category_uuid' => 'category_uuid', 'status' => 'active'],
    ]);
    $connection->table('categories')->insert(['uuid' => 'category_uuid', 'owner_uuid' => 'network_uuid', 'name' => 'Food', 'for' => 'storefront_network']);

    $controller       = new NetworkController();
    $setStatus        = new ReflectionMethod($controller, 'setMembershipStatus');
    $setStatus->setAccessible(true);
    $networkStores    = apiQueryCacheVersion('network_stores', 'company_network');
    $stores           = apiQueryCacheVersion('stores', 'company_network');

    $setStatus->invoke($controller, 'network_uuid', ['store_a'], 'active');

    expect($connection->table('network_stores')->where('store_uuid', 'store_a')->value('status'))->toBe('active')
        ->and(apiQueryCacheVersion('network_stores', 'company_network'))->toBe($networkStores + 1)
        ->and(apiQueryCacheVersion('stores', 'company_network'))->toBe($stores + 1);

    $categories = apiQueryCacheVersion('categories', 'company_network');
    $controller->deleteCategory('network_uuid', NetworkActionRequest::create('/', 'POST', ['category' => 'category_uuid']));

    expect($connection->table('network_stores')->whereNotNull('category_uuid')->count())->toBe(0)
        ->and(apiQueryCacheVersion('network_stores', 'company_network'))->toBe($networkStores + 2)
        ->and(apiQueryCacheVersion('categories', 'company_network'))->toBe($categories + 1);
});

test('removing catalog assignments through the query builder invalidates the cached assignment lists', function () {
    createApiCacheInvalidationSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('catalog_subjects')->insert([
        'uuid' => 'assignment_uuid', 'company_uuid' => 'company_catalog', 'catalog_uuid' => 'catalog_uuid', 'subject_uuid' => '60000000-0000-4000-8000-000000000001', 'subject_type' => FoodTruck::class,
    ]);
    $catalog = new Catalog();
    $catalog->forceFill(['uuid' => 'catalog_uuid', 'company_uuid' => 'company_catalog']);

    $controller = new CatalogController();
    $sync       = new ReflectionMethod($controller, 'syncSubjects');
    $sync->setAccessible(true);

    // Nothing to remove: no invalidation
    $before = apiQueryCacheVersion('catalog_subjects', 'company_catalog');
    $sync->invoke($controller, $catalog, Store::class, []);
    expect(apiQueryCacheVersion('catalog_subjects', 'company_catalog'))->toBe($before);

    $sync->invoke($controller, $catalog, FoodTruck::class, []);
    expect($connection->table('catalog_subjects')->whereNull('deleted_at')->count())->toBe(0)
        ->and(apiQueryCacheVersion('catalog_subjects', 'company_catalog'))->toBe($before + 1);
});

test('deleting a catalog invalidates the cached lists embedding its category products', function () {
    createApiCacheInvalidationSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('categories')->insert(['uuid' => 'catalog_category_uuid', 'company_uuid' => 'company_catalog', 'owner_uuid' => 'catalog_uuid', 'name' => 'Mains', 'for' => 'storefront_catalog']);
    $connection->table('catalog_category_products')->insert(['uuid' => 'pivot_uuid', 'catalog_category_uuid' => 'catalog_category_uuid', 'product_uuid' => 'product_uuid']);
    $catalog = new Catalog();
    $catalog->forceFill(['uuid' => 'catalog_uuid', 'company_uuid' => 'company_catalog']);

    $before = apiQueryCacheVersion('catalog_category_products', 'company_catalog');
    (new CatalogObserver())->deleted($catalog);

    expect($connection->table('catalog_category_products')->whereNull('deleted_at')->count())->toBe(0)
        ->and(apiQueryCacheVersion('catalog_category_products', 'company_catalog'))->toBe($before + 1);
});

test('discarding a failed capture invalidates the cached lists embedding its transaction items', function () {
    createApiCacheInvalidationSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('transactions')->insert(['uuid' => 'transaction_uuid', 'public_id' => 'transaction_1', 'company_uuid' => 'company_checkout']);
    $connection->table('transaction_items')->insert(['uuid' => 'item_uuid', 'transaction_uuid' => 'transaction_uuid']);
    $transaction = Transaction::where('uuid', 'transaction_uuid')->firstOrFail();

    $before  = apiQueryCacheVersion('transaction_items', 'company_checkout');
    $discard = new ReflectionMethod(CheckoutController::class, 'discardFailedCapture');
    $discard->setAccessible(true);
    $discard->invoke(null, $transaction, []);

    expect($connection->table('transaction_items')->whereNull('deleted_at')->count())->toBe(0)
        ->and(apiQueryCacheVersion('transaction_items', 'company_checkout'))->toBe($before + 1);
});
