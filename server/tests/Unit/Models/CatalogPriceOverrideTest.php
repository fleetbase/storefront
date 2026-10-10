<?php

use Fleetbase\Storefront\Http\Resources\CatalogProduct as CatalogProductResource;
use Fleetbase\Storefront\Models\Cart;
use Fleetbase\Storefront\Models\Catalog;
use Fleetbase\Storefront\Models\CatalogCategory;
use Fleetbase\Storefront\Models\CatalogProduct;
use Fleetbase\Storefront\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class CatalogCartStub extends Cart
{
    public static ?Product $resolvedProduct = null;

    public static function findProduct(string $id): ?Product
    {
        return static::$resolvedProduct?->public_id === $id ? static::$resolvedProduct : null;
    }
}

function createCatalogOverrideSchema(): void
{
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();

    foreach (['categories', 'catalog_category_products', 'products', 'catalogs', 'carts', 'stores', 'store_locations'] as $table) {
        $schema->dropIfExists($table);
    }

    $schema->create('categories', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->string('owner_type')->nullable();
        $table->string('name');
        $table->string('for')->nullable();
        $table->integer('order')->nullable();
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
        $table->string('store_uuid')->nullable();
        $table->string('name')->nullable();
        $table->integer('price')->default(0);
        $table->integer('sale_price')->default(0);
        $table->boolean('is_on_sale')->default(false);
        $table->boolean('is_available')->default(true);
        $table->string('currency')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('catalogs', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('stores', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('currency')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('store_locations', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('store_uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('carts', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('currency')->nullable();
        $table->text('items')->nullable();
        $table->text('events')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    $connection->table('catalogs')->insert(['uuid' => 'catalog_uuid', 'public_id' => 'catalog_lunch']);
    $connection->table('stores')->insert(['uuid' => 'store_uuid', 'public_id' => 'store_main', 'currency' => 'SGD']);
    $connection->table('store_locations')->insert(['uuid' => 'store_location_uuid', 'public_id' => 'store_location_1', 'store_uuid' => 'store_uuid']);
    $connection->table('categories')->insert([
        'uuid' => '80000000-0000-4000-8000-000000000001', 'company_uuid' => 'company_uuid', 'owner_uuid' => 'catalog_uuid', 'owner_type' => Catalog::class, 'name' => 'Mains', 'for' => 'storefront_catalog', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $connection->table('products')->insert([
        ['uuid' => '90000000-0000-4000-8000-000000000001', 'public_id' => 'product_rice', 'store_uuid' => 'store_uuid', 'name' => 'Chicken Rice', 'price' => 800, 'sale_price' => 700, 'is_on_sale' => true, 'currency' => 'SGD'],
        ['uuid' => '90000000-0000-4000-8000-000000000002', 'public_id' => 'product_soup', 'store_uuid' => 'store_uuid', 'name' => 'Fish Soup', 'price' => 920, 'sale_price' => 0, 'is_on_sale' => false, 'currency' => 'SGD'],
    ]);
}

test('a category keeps per-catalog price and availability overrides on its products', function () {
    createCatalogOverrideSchema();
    $category = CatalogCategory::where('uuid', '80000000-0000-4000-8000-000000000001')->first();

    $category->setProducts(['90000000-0000-4000-8000-000000000001', '90000000-0000-4000-8000-000000000002'], [
        '90000000-0000-4000-8000-000000000002' => ['price' => 1000, 'is_available' => false],
    ]);

    $pivots = CatalogProduct::where('catalog_category_uuid', $category->uuid)->get()->keyBy('product_uuid');
    expect($pivots)->toHaveCount(2)
        ->and($pivots['90000000-0000-4000-8000-000000000001']->price)->toBeNull()
        ->and((int) $pivots['90000000-0000-4000-8000-000000000002']->price)->toBe(1000)
        ->and((bool) $pivots['90000000-0000-4000-8000-000000000002']->is_available)->toBeFalse()
        ->and($category->fresh()->productOverrides())->toBe(['90000000-0000-4000-8000-000000000002' => ['price' => 1000, 'is_available' => false]]);

    // Overrides can be changed on products that stay, and cleared with nulls.
    $category->setProducts(['90000000-0000-4000-8000-000000000001', '90000000-0000-4000-8000-000000000002'], [
        '90000000-0000-4000-8000-000000000001' => ['price' => '7.50'],
        '90000000-0000-4000-8000-000000000002' => ['price' => null, 'is_available' => null],
    ]);

    $pivots = CatalogProduct::where('catalog_category_uuid', $category->uuid)->get()->keyBy('product_uuid');
    expect((int) $pivots['90000000-0000-4000-8000-000000000001']->price)->toBe(750)
        ->and($pivots['90000000-0000-4000-8000-000000000002']->price)->toBeNull()
        ->and($pivots['90000000-0000-4000-8000-000000000002']->is_available)->toBeNull();
});

test('the catalog product resource serves the catalog price and availability instead of the store ones', function () {
    createCatalogOverrideSchema();
    $category = CatalogCategory::where('uuid', '80000000-0000-4000-8000-000000000001')->first();
    $category->setProducts([
        ['uuid' => '90000000-0000-4000-8000-000000000001', 'catalog_price' => 650],
        ['uuid' => '90000000-0000-4000-8000-000000000002', 'catalog_available' => false],
    ]);

    $products = $category->fresh()->products->keyBy('uuid');
    $serialize = function (Product $product): array {
        // The resource walks media, add-ons, variants and hours; none exist in this schema.
        foreach (['files', 'addonCategories', 'variants', 'hours'] as $relation) {
            $product->setRelation($relation, collect());
        }
        $product->setRelation('primaryImage', null);

        return (new CatalogProductResource($product))->toArray(Request::create('/catalogs', 'GET'));
    };
    $rice = $serialize($products['90000000-0000-4000-8000-000000000001']);
    $soup = $serialize($products['90000000-0000-4000-8000-000000000002']);

    expect($rice['price'])->toBe(650)
        ->and($rice['store_price'])->toBe(800)
        ->and($rice['catalog_price'])->toBe(650)
        ->and($rice['is_on_sale'])->toBeFalse()
        ->and($rice['sale_price'])->toBeNull()
        ->and($soup['price'])->toBe(920)
        ->and($soup['catalog_price'])->toBeNull()
        ->and($soup['is_available'])->toBeFalse()
        ->and($soup['catalog_available'])->toBeFalse();
});

test('a cart line added from a catalog is priced at the catalog price and keeps it on update', function () {
    createCatalogOverrideSchema();
    $category = CatalogCategory::where('uuid', '80000000-0000-4000-8000-000000000001')->first();
    $category->setProducts(['90000000-0000-4000-8000-000000000001', '90000000-0000-4000-8000-000000000002'], [
        '90000000-0000-4000-8000-000000000001' => ['price' => 650],
        '90000000-0000-4000-8000-000000000002' => ['is_available' => false],
    ]);
    $rice = Product::where('uuid', '90000000-0000-4000-8000-000000000001')->first();
    $soup = Product::where('uuid', '90000000-0000-4000-8000-000000000002')->first();
    foreach ([$rice, $soup] as $product) {
        $product->setRelation('files', collect());
        $product->setRelation('primaryImage', null);
    }

    $cart = new CatalogCartStub();
    $cart->forceFill(['uuid' => 'cart_uuid', 'public_id' => 'cart_1', 'currency' => 'SGD', 'items' => [], 'events' => []]);

    // Without a catalog the store sale price applies; through the catalog its price applies.
    $plain = $cart->addItem($rice, 1, [], [], 'store_location_1');
    $line  = $cart->addItem($rice, 2, [], [], 'store_location_1', null, null, 'catalog_lunch');

    expect((int) $plain->price)->toBe(700)
        ->and((int) $line->price)->toBe(650)
        ->and((int) $line->subtotal)->toBe(1300)
        ->and($line->catalog_id)->toBe('catalog_lunch');

    CatalogCartStub::$resolvedProduct = $rice;
    $updated                          = $cart->updateCartItem($line, 3, [], []);
    expect((int) $updated->price)->toBe(650)
        ->and((int) $updated->subtotal)->toBe(1950);

    expect(fn () => $cart->addItem($soup, 1, [], [], 'store_location_1', null, null, 'catalog_lunch'))
        ->toThrow(Exception::class, 'not available in the selected catalog');
});
