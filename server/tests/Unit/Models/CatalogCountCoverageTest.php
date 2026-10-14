<?php

use Fleetbase\Storefront\Models\Cart;
use Fleetbase\Storefront\Models\Catalog;
use Fleetbase\Storefront\Models\Product;
use Illuminate\Database\Eloquent\Model;

function catalogCountCoverageSchema(): void
{
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();

    foreach (['categories', 'catalog_category_products', 'products', 'catalogs'] as $table) {
        $schema->dropIfExists($table);
    }

    $schema->create('categories', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->string('owner_type')->nullable();
        $table->string('name');
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('catalog_category_products', function ($table) {
        $table->string('uuid')->nullable();
        $table->string('catalog_category_uuid')->nullable();
        $table->string('product_uuid')->nullable();
        $table->integer('price')->nullable();
        $table->boolean('is_available')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('products', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('name')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('catalogs', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });

    $connection->table('catalogs')->insert([
        ['uuid' => 'catalog_lunch_uuid', 'public_id' => 'catalog_lunch'],
        ['uuid' => 'catalog_dinner_uuid', 'public_id' => 'catalog_dinner'],
    ]);
    $connection->table('categories')->insert([
        ['uuid' => 'lunch_mains', 'owner_uuid' => 'catalog_lunch_uuid', 'owner_type' => Catalog::class, 'name' => 'Mains'],
        ['uuid' => 'lunch_sides', 'owner_uuid' => 'catalog_lunch_uuid', 'owner_type' => Catalog::class, 'name' => 'Sides'],
        ['uuid' => 'dinner_mains', 'owner_uuid' => 'catalog_dinner_uuid', 'owner_type' => Catalog::class, 'name' => 'Mains'],
    ]);
    $connection->table('products')->insert([
        ['uuid' => 'product_rice_uuid', 'public_id' => 'product_rice', 'name' => 'Chicken Rice'],
        ['uuid' => 'product_soup_uuid', 'public_id' => 'product_soup', 'name' => 'Fish Soup'],
        ['uuid' => 'product_tea_uuid', 'public_id' => 'product_tea', 'name' => 'Tea'],
    ]);
    $connection->table('catalog_category_products')->insert([
        // Rice is in two lunch categories and the dinner catalog: two catalogs.
        ['uuid' => 'pivot_1', 'catalog_category_uuid' => 'lunch_mains', 'product_uuid' => 'product_rice_uuid', 'deleted_at' => null],
        ['uuid' => 'pivot_2', 'catalog_category_uuid' => 'lunch_sides', 'product_uuid' => 'product_rice_uuid', 'deleted_at' => null],
        ['uuid' => 'pivot_3', 'catalog_category_uuid' => 'dinner_mains', 'product_uuid' => 'product_rice_uuid', 'deleted_at' => null],
        // Soup was removed from the dinner catalog.
        ['uuid' => 'pivot_4', 'catalog_category_uuid' => 'dinner_mains', 'product_uuid' => 'product_soup_uuid', 'deleted_at' => '2026-10-01 00:00:00'],
    ]);
}

test('a product counts the distinct catalogs it is sold in when the list did not select it', function () {
    catalogCountCoverageSchema();

    $rice = Product::where('public_id', 'product_rice')->first();
    $soup = Product::where('public_id', 'product_soup')->first();
    $tea  = Product::where('public_id', 'product_tea')->first();

    // A count selected with the list (catalogsCountQuery) is used as is.
    $listed = Product::where('public_id', 'product_tea')->first();
    $listed->setRawAttributes(array_merge($listed->getAttributes(), ['catalogs_count' => '3']));

    expect($rice->catalogs_count)->toBe(2)
        ->and($soup->catalogs_count)->toBe(0)
        ->and($tea->catalogs_count)->toBe(0)
        ->and($listed->catalogs_count)->toBe(3);
});

test('a catalog price override needs a product, a catalog that exists, and the product in it', function () {
    catalogCountCoverageSchema();
    $rice = Product::where('public_id', 'product_rice')->first();
    $soup = Product::where('public_id', 'product_soup')->first();

    expect(Cart::catalogOverride(null, 'catalog_lunch'))->toBeNull()
        ->and(Cart::catalogOverride($rice, null))->toBeNull()
        ->and(Cart::catalogOverride($rice, ''))->toBeNull()
        ->and(Cart::catalogOverride($rice, 'catalog_missing'))->toBeNull()
        // Soup was taken out of the dinner catalog, and was never in lunch.
        ->and(Cart::catalogOverride($soup, 'catalog_dinner'))->toBeNull()
        ->and(Cart::catalogOverride($soup, 'catalog_lunch'))->toBeNull()
        ->and(Cart::catalogOverride($rice, 'catalog_lunch'))->toBe(['price' => null, 'is_available' => null]);
});
