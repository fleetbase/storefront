<?php

use Fleetbase\Storefront\Http\Controllers\v1\CategoryController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store as SessionStore;

function createCategoryApiControllerSchema(): void
{
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('categories');
    $schema->create('categories', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->string('parent_uuid')->nullable();
        $table->string('icon_file_uuid')->nullable();
        $table->string('name')->nullable();
        $table->text('description')->nullable();
        $table->text('tags')->nullable();
        $table->text('translations')->nullable();
        $table->string('icon')->nullable();
        $table->string('slug')->nullable();
        $table->string('for')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
}

function categoryApiRequest(array $input = []): Request
{
    $request = Request::create('/categories', 'GET', $input);
    $request->setLaravelSession(new SessionStore(
        'category-api-controller-test',
        new ArraySessionHandler(120)
    ));
    app()->instance('request', $request);

    return $request;
}

test('storefront category query scopes owner purpose and parent-only results', function () {
    createCategoryApiControllerSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('product_addon_categories');
    $schema->dropIfExists('product_variants');
    $schema->dropIfExists('files');
    $schema->dropIfExists('products');
    $schema->create('products', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('category_uuid')->nullable();
        $table->string('store_uuid')->nullable();
        $table->boolean('is_available')->default(true);
        $table->string('status')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('product_addon_categories', function ($table) {
        $table->increments('id');
        $table->string('product_uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('product_variants', function ($table) {
        $table->increments('id');
        $table->string('product_uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('files', function ($table) {
        $table->increments('id');
        $table->string('subject_uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $connection->table('categories')->insert([
        [
            'uuid'       => 'parent_uuid',
            'public_id'  => 'category_parent',
            'owner_uuid' => 'store_uuid',
            'parent_uuid'=> null,
            'name'       => 'Parent',
            'for'        => 'storefront_product',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'uuid'        => 'child_uuid',
            'public_id'   => 'category_child',
            'owner_uuid'  => 'store_uuid',
            'parent_uuid' => 'parent_uuid',
            'name'        => 'Child',
            'for'         => 'storefront_product',
            'created_at'  => now(),
            'updated_at'  => now(),
        ],
        [
            'uuid'       => 'other_uuid',
            'public_id'  => 'category_other',
            'owner_uuid' => 'other_store',
            'parent_uuid'=> null,
            'name'       => 'Other',
            'for'        => 'storefront_product',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);
    $connection->table('products')->insert([
        ['uuid' => 'product_uuid', 'public_id' => 'product_public', 'category_uuid' => 'child_uuid', 'store_uuid' => 'store_uuid', 'is_available' => true, 'status' => 'published'],
        ['uuid' => 'draft_product_uuid', 'public_id' => 'product_draft', 'category_uuid' => 'child_uuid', 'store_uuid' => 'store_uuid', 'is_available' => true, 'status' => 'draft'],
        ['uuid' => 'foreign_product_uuid', 'public_id' => 'product_foreign', 'category_uuid' => 'child_uuid', 'store_uuid' => 'other_store', 'is_available' => true, 'status' => 'published'],
    ]);
    session([
        'storefront_store'   => 'store_uuid',
        'storefront_network' => null,
    ]);

    $resource = (new CategoryController())->query(categoryApiRequest([
        'parents_only' => true,
    ]));
    $childResource = (new CategoryController())->query(categoryApiRequest([
        'parent'        => 'category_parent',
        'with_products' => true,
    ]));

    expect($resource->resource)->toHaveCount(1)
        ->and($resource->resource->first()->uuid)->toBe('parent_uuid')
        ->and($childResource->resource)->toHaveCount(1)
        ->and($childResource->resource->first()->uuid)->toBe('child_uuid')
        ->and($childResource->resource->first()->products)->toHaveCount(1)
        ->and($childResource->resource->first()->products->first()->resource->uuid)->toBe('product_uuid');
});

test('network category query scopes categories to the active network', function () {
    createCategoryApiControllerSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('network_stores');
    $schema->dropIfExists('networks');
    $schema->dropIfExists('stores');
    $schema->create('stores', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('networks', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('network_stores', function ($table) {
        $table->increments('id');
        $table->string('network_uuid');
        $table->string('store_uuid');
        $table->string('category_uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $connection->table('categories')->insert([
        [
            'uuid'       => 'network_category_uuid',
            'public_id'  => 'category_network',
            'owner_uuid' => 'network_uuid',
            'parent_uuid'=> null,
            'name'       => 'Network category',
            'for'        => 'storefront_network',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'uuid'       => 'other_network_uuid',
            'public_id'  => 'category_other',
            'owner_uuid' => 'other_network',
            'parent_uuid'=> null,
            'name'       => 'Other',
            'for'        => 'storefront_network',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'uuid'        => 'network_child_uuid',
            'public_id'   => 'category_network_child',
            'owner_uuid'  => 'network_uuid',
            'parent_uuid' => 'network_category_uuid',
            'name'        => 'Network child',
            'for'         => 'storefront_network',
            'created_at'  => now(),
            'updated_at'  => now(),
        ],
    ]);
    $connection->table('stores')->insert([
        'uuid'      => 'store_uuid',
        'public_id' => 'store_public',
    ]);
    $connection->table('networks')->insert(['uuid' => 'network_uuid']);
    $connection->table('network_stores')->insert([
        'network_uuid'  => 'network_uuid',
        'store_uuid'    => 'store_uuid',
        'category_uuid' => 'network_category_uuid',
    ]);
    session([
        'storefront_store'   => null,
        'storefront_network' => 'network_uuid',
    ]);

    $resource = (new CategoryController())->query(categoryApiRequest([
        'with_stores'  => true,
        'parents_only' => true,
    ]));
    $childResource = (new CategoryController())->query(categoryApiRequest([
        'parent' => 'category_network',
    ]));

    expect($resource->resource)->toHaveCount(1)
        ->and($resource->resource->first()->uuid)->toBe('network_category_uuid')
        ->and($resource->resource->first()->stores)->toHaveCount(1)
        ->and($resource->resource->first()->stores->first()->uuid)->toBe('store_uuid')
        ->and($childResource->resource)->toHaveCount(1)
        ->and($childResource->resource->first()->uuid)->toBe('network_child_uuid');
});

test('network category query resolves a member store and its child categories', function () {
    createCategoryApiControllerSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('network_stores');
    $schema->dropIfExists('networks');
    $schema->dropIfExists('stores');
    $schema->dropIfExists('product_addon_categories');
    $schema->dropIfExists('product_variants');
    $schema->dropIfExists('files');
    $schema->dropIfExists('products');
    $schema->create('stores', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('networks', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('network_stores', function ($table) {
        $table->increments('id');
        $table->string('network_uuid');
        $table->string('store_uuid');
        $table->string('category_uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('products', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('category_uuid')->nullable();
        $table->string('store_uuid')->nullable();
        $table->boolean('is_available')->default(true);
        $table->string('status')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('product_addon_categories', function ($table) {
        $table->increments('id');
        $table->string('product_uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('product_variants', function ($table) {
        $table->increments('id');
        $table->string('product_uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('files', function ($table) {
        $table->increments('id');
        $table->string('subject_uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $connection->table('stores')->insert([
        'uuid'         => 'store_uuid',
        'public_id'    => 'store_public',
        'company_uuid' => 'company_uuid',
    ]);
    $connection->table('networks')->insert(['uuid' => 'network_uuid']);
    $connection->table('network_stores')->insert([
        'network_uuid' => 'network_uuid',
        'store_uuid'   => 'store_uuid',
    ]);
    $connection->table('categories')->insert([
        [
            'uuid'       => 'parent_uuid',
            'public_id'  => 'category_parent',
            'owner_uuid' => 'store_uuid',
            'parent_uuid'=> null,
            'name'       => 'Parent',
            'for'        => 'storefront_product',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'uuid'        => 'child_uuid',
            'public_id'   => 'category_child',
            'owner_uuid'  => 'store_uuid',
            'parent_uuid' => 'parent_uuid',
            'name'        => 'Child',
            'for'         => 'storefront_product',
            'created_at'  => now(),
            'updated_at'  => now(),
        ],
    ]);
    $connection->table('products')->insert([
        ['uuid' => 'product_uuid', 'public_id' => 'product_public', 'category_uuid' => 'parent_uuid', 'store_uuid' => 'store_uuid', 'is_available' => true, 'status' => 'published'],
        ['uuid' => 'draft_product_uuid', 'public_id' => 'product_draft', 'category_uuid' => 'parent_uuid', 'store_uuid' => 'store_uuid', 'is_available' => true, 'status' => 'draft'],
        ['uuid' => 'foreign_product_uuid', 'public_id' => 'product_foreign', 'category_uuid' => 'parent_uuid', 'store_uuid' => 'other_store', 'is_available' => true, 'status' => 'published'],
    ]);
    session([
        'company'            => 'company_uuid',
        'storefront_store'   => null,
        'storefront_network' => 'network_uuid',
    ]);

    $resource = (new CategoryController())->query(categoryApiRequest([
        'store'  => 'store_public',
        'parent' => 'category_parent',
    ]));
    $parentResource = (new CategoryController())->query(categoryApiRequest([
        'store'         => 'store_public',
        'parents_only'  => true,
        'with_products' => true,
    ]));

    expect($resource->resource)->toHaveCount(1)
        ->and($resource->resource->first()->uuid)->toBe('child_uuid')
        ->and($parentResource->resource)->toHaveCount(1)
        ->and($parentResource->resource->first()->uuid)->toBe('parent_uuid')
        ->and($parentResource->resource->first()->products)->toHaveCount(1)
        ->and($parentResource->resource->first()->products->first()->resource->uuid)->toBe('product_uuid');
});
