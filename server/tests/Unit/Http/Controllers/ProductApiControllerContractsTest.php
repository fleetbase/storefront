<?php

use Fleetbase\Storefront\Http\Controllers\v1\ProductController;
use Fleetbase\Storefront\Http\Requests\CreateProductRequest;
use Fleetbase\Storefront\Http\Requests\UpdateProductRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store as SessionStore;

class ProductApiUpdateRequest extends UpdateProductRequest
{
    public function validated($key = null, $default = null): array
    {
        return $this->all();
    }
}

function createProductApiControllerSchema(): void
{
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();

    foreach (['products', 'product_addon_categories', 'product_variant_options', 'product_variants', 'product_addons', 'network_stores', 'networks', 'stores', 'files', 'categories'] as $table) {
        $schema->dropIfExists($table);
    }

    $schema->create('products', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('created_by_uuid')->nullable();
        $table->string('store_uuid')->nullable();
        $table->string('category_uuid')->nullable();
        $table->string('primary_image_uuid')->nullable();
        $table->string('name')->nullable();
        $table->text('description')->nullable();
        $table->text('tags')->nullable();
        $table->text('meta')->nullable();
        $table->string('sku')->nullable();
        $table->integer('price')->default(0);
        $table->string('currency')->nullable();
        $table->integer('sale_price')->default(0);
        $table->boolean('is_service')->default(false);
        $table->boolean('is_bookable')->default(false);
        $table->boolean('is_available')->default(true);
        $table->boolean('is_on_sale')->default(false);
        $table->boolean('is_recommended')->default(false);
        $table->boolean('can_pickup')->default(false);
        $table->text('youtube_urls')->nullable();
        $table->string('status')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('product_addon_categories', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('product_uuid')->nullable();
        $table->string('category_uuid')->nullable();
        $table->text('excluded_addons')->nullable();
        $table->integer('max_selectable')->nullable();
        $table->boolean('is_required')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('product_addons', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('created_by_uuid')->nullable();
        $table->string('category_uuid')->nullable();
        $table->string('name')->nullable();
        $table->text('description')->nullable();
        $table->text('translations')->nullable();
        $table->integer('price')->default(0);
        $table->integer('sale_price')->default(0);
        $table->boolean('is_on_sale')->default(false);
        $table->string('slug')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('product_variants', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('product_uuid')->nullable();
        $table->string('name')->nullable();
        $table->text('description')->nullable();
        $table->text('translations')->nullable();
        $table->text('meta')->nullable();
        $table->boolean('is_required')->default(false);
        $table->boolean('is_multiselect')->default(false);
        $table->integer('min')->default(0);
        $table->integer('max')->default(1);
        $table->string('slug')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('product_variant_options', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('product_variant_uuid')->nullable();
        $table->string('name')->nullable();
        $table->text('description')->nullable();
        $table->text('translations')->nullable();
        $table->text('meta')->nullable();
        $table->integer('additional_cost')->default(0);
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('files', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('stores', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('name')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('networks', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('name')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('network_stores', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('network_uuid')->nullable();
        $table->string('store_uuid')->nullable();
        $table->string('category_uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('categories', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->string('owner_type')->nullable();
        $table->string('name')->nullable();
        $table->string('slug')->nullable();
        $table->text('description')->nullable();
        $table->text('tags')->nullable();
        $table->string('for')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
}

function productApiRequest(string $uri = '/products', string $method = 'GET', array $input = []): Request
{
    $request = Request::create($uri, $method, $input);
    $request->setLaravelSession(new SessionStore(
        'product-api-controller-test',
        new ArraySessionHandler(120)
    ));
    app()->instance('request', $request);

    return $request;
}

test('product query returns only available products for the active storefront store', function () {
    createProductApiControllerSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('products')->insert([
        [
            'uuid'         => 'available_uuid',
            'public_id'    => 'product_available',
            'company_uuid' => 'company_uuid',
            'store_uuid'   => 'store_uuid',
            'name'         => 'Available',
            'is_available' => true,
            'status'       => 'published',
        ],
        [
            'uuid'         => 'unavailable_uuid',
            'public_id'    => 'product_unavailable',
            'company_uuid' => 'company_uuid',
            'store_uuid'   => 'store_uuid',
            'name'         => 'Unavailable',
            'is_available' => false,
            'status'       => 'published',
        ],
        [
            'uuid'         => 'other_store_uuid',
            'public_id'    => 'product_other',
            'company_uuid' => 'company_uuid',
            'store_uuid'   => 'other_store',
            'name'         => 'Other store',
            'is_available' => true,
            'status'       => 'published',
        ],
    ]);
    session([
        'company'            => 'company_uuid',
        'storefront_store'   => 'store_uuid',
        'storefront_network' => null,
    ]);

    $controller = new ProductController();
    $resource   = $controller->query(productApiRequest(
        '/products?store=store_uuid',
        'GET',
        ['store' => 'store_uuid']
    ));
    $ownedUnavailable = $controller->find('product_unavailable');

    expect($resource->resource)->toHaveCount(1)
        ->and($resource->resource->first()->uuid)->toBe('available_uuid')
        ->and($resource->resource->first()->relationLoaded('addonCategories'))->toBeTrue()
        ->and($resource->resource->first()->relationLoaded('variants'))->toBeTrue()
        ->and($resource->resource->first()->relationLoaded('files'))->toBeTrue()
        ->and($ownedUnavailable->resource->uuid)->toBe('unavailable_uuid');
});

test('product query returns available products assigned through the active network', function () {
    createProductApiControllerSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('stores')->insert([
        ['uuid' => 'network_store_uuid', 'public_id' => 'store_network', 'name' => 'Network store'],
        ['uuid' => 'outside_store_uuid', 'public_id' => 'store_outside', 'name' => 'Outside store'],
    ]);
    $connection->table('networks')->insert([
        'uuid'      => 'network_uuid',
        'public_id' => 'network_public',
        'name'      => 'Delivery network',
    ]);
    $connection->table('network_stores')->insert([
        'uuid'         => 'network_store_pivot_uuid',
        'network_uuid' => 'network_uuid',
        'store_uuid'   => 'network_store_uuid',
    ]);
    $connection->table('products')->insert([
        [
            'uuid'         => 'network_product_uuid',
            'public_id'    => 'product_network',
            'store_uuid'   => 'network_store_uuid',
            'name'         => 'Network product',
            'is_available' => true,
            'status'       => 'published',
        ],
        [
            'uuid'         => 'outside_product_uuid',
            'public_id'    => 'product_outside',
            'store_uuid'   => 'outside_store_uuid',
            'name'         => 'Outside product',
            'is_available' => true,
            'status'       => 'published',
        ],
    ]);
    session([
        'storefront_store'   => null,
        'storefront_network' => 'network_uuid',
    ]);

    $resource = (new ProductController())->query(productApiRequest(
        '/products?network=network_uuid',
        'GET',
        ['network' => 'network_uuid']
    ));

    expect($resource->resource)->toHaveCount(1)
        ->and($resource->resource->first()->uuid)->toBe('network_product_uuid');
});

test('product creation normalizes commerce fields and session ownership', function () {
    createProductApiControllerSchema();
    session([
        'company'             => 'company_uuid',
        'user'                => 'user_uuid',
        'storefront_store'    => 'store_uuid',
        'storefront_currency' => 'MNT',
    ]);
    $request = CreateProductRequest::create('/products', 'POST', [
        'name'          => 'Delivery box',
        'description'   => 'Reusable insulated box',
        'tags'          => 'shipping,insulated',
        'youtube_urls'  => 'https://example.test/demo',
        'price'         => '12,500',
        'sale_price'    => '10,000',
        'is_available'  => true,
        'status'        => 'published',
    ]);

    $resource = (new ProductController())->create($request);
    $product  = $resource->resource;

    expect($product->exists)->toBeTrue()
        ->and($product->company_uuid)->toBe('company_uuid')
        ->and($product->created_by_uuid)->toBe('user_uuid')
        ->and($product->store_uuid)->toBe('store_uuid')
        ->and($product->currency)->toBe('MNT')
        ->and($product->price)->toBe(12500)
        ->and($product->sale_price)->toBe(10000)
        ->and($product->tags)->toBe(['shipping', 'insulated'])
        ->and($product->youtube_urls)->toBe(['https://example.test/demo']);
});

test('product creation defaults status to published so the product is actually readable', function () {
    // The column is nullable with no default. An API-created product used to land as NULL
    // and was then invisible to every `where('status', 'published')` read path — including
    // CheckoutController's cart validation, which dropped it at capture time.
    createProductApiControllerSchema();
    session([
        'company'          => 'company_uuid',
        'user'             => 'user_uuid',
        'storefront_store' => 'store_uuid',
    ]);

    $defaulted = (new ProductController())->create(CreateProductRequest::create('/products', 'POST', [
        'name'  => 'No status supplied',
        'price' => '1000',
    ]))->resource;

    $explicit = (new ProductController())->create(CreateProductRequest::create('/products', 'POST', [
        'name'   => 'Draft on purpose',
        'price'  => '1000',
        'status' => 'draft',
    ]))->resource;

    expect($defaulted->status)->toBe('published')
        ->and($explicit->status)->toBe('draft');
});

test('product creation persists category addons variants and option contracts', function () {
    createProductApiControllerSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    session([
        'company'             => 'company_uuid',
        'user'                => 'user_uuid',
        'storefront_store'    => 'store_uuid',
        'storefront_currency' => 'USD',
    ]);
    $request = CreateProductRequest::create('/products', 'POST', [
        'name'               => 'Custom meal',
        'price'              => '25.00',
        'category'           => [
            'name'        => 'Meals',
            'description' => 'Prepared meals',
            'tags'        => ['prepared'],
        ],
        'addon_categories' => [
            [
                'name'           => 'Extras',
                'description'    => 'Optional extras',
                'tags'           => ['food'],
                'addons'         => [
                    'Napkins',
                    [
                        'name'       => 'Sauce',
                        'price'      => '2.50',
                        'sale_price' => '1.50',
                        'is_on_sale' => true,
                    ],
                ],
                'excluded_addons' => ['addon_hidden'],
                'max_selectable'  => 2,
                'is_required'     => true,
            ],
        ],
        'variants' => [
            [
                'name'           => 'Size',
                'description'    => 'Meal size',
                'meta'           => ['display' => 'buttons'],
                'is_required'    => true,
                'is_multiselect' => false,
                'min'            => 1,
                'max'            => 1,
                'options'        => [
                    'Regular',
                    [
                        'name'            => 'Large',
                        'description'     => 'Large portion',
                        'additional_cost' => '4.00',
                    ],
                ],
            ],
        ],
    ]);

    Model::setEventDispatcher(new Illuminate\Events\Dispatcher(app()));
    $product = (new ProductController())->create($request)->resource;
    Model::unsetEventDispatcher();
    $category = $connection->table('categories')
        ->where('for', 'storefront_product')
        ->where('name', 'Meals')
        ->first();
    $addonCategory = $connection->table('categories')
        ->where('for', 'storefront_product_addon')
        ->where('name', 'Extras')
        ->first();
    $variant = $connection->table('product_variants')->where('product_uuid', $product->uuid)->first();

    expect($product->category_uuid)->toBe($category->uuid)
        ->and($category->owner_uuid)->toBe('store_uuid')
        ->and($connection->table('product_addons')->where('category_uuid', $addonCategory->uuid)->pluck('name')->all())
        ->toEqualCanonicalizing(['Napkins', 'Sauce'])
        ->and($connection->table('product_addons')->where('name', 'Sauce')->value('price'))->toBe(250)
        ->and($connection->table('product_addon_categories')->where('product_uuid', $product->uuid)->value('category_uuid'))->toBe($addonCategory->uuid)
        ->and($connection->table('product_addon_categories')->where('product_uuid', $product->uuid)->value('is_required'))->toBe(1)
        ->and($variant->name)->toBe('Size')
        ->and($variant->is_required)->toBe(1)
        ->and($connection->table('product_variant_options')->where('product_variant_uuid', $variant->uuid)->pluck('name')->all())
        ->toEqualCanonicalizing(['Regular', 'Large'])
        ->and($connection->table('product_variant_options')->where('name', 'Large')->value('additional_cost'))->toBe(400);
});

test('product creation resolves an existing category and update creates a replacement category', function () {
    createProductApiControllerSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('categories')->insert([
        [
            'uuid'         => 'existing_category_uuid',
            'public_id'    => 'category_abcdefgh',
            'company_uuid' => 'company_uuid',
            'owner_uuid'   => 'store_uuid',
            'name'         => 'Existing category',
            'for'          => 'storefront_product',
        ],
        [
            'uuid'         => 'existing_addon_category_uuid',
            'public_id'    => 'addon_abcdefgh',
            'company_uuid' => 'company_uuid',
            'owner_uuid'   => null,
            'name'         => 'Existing extras',
            'for'          => 'storefront_product_addon',
        ],
    ]);
    session([
        'company'             => 'company_uuid',
        'user'                => 'user_uuid',
        'storefront_store'    => 'store_uuid',
        'storefront_currency' => 'USD',
        'storefront_key'      => 'store_key',
    ]);
    $created = (new ProductController())->create(CreateProductRequest::create('/products', 'POST', [
        'name'             => 'Categorized product',
        'price'            => 1000,
        'category'         => 'category_abcdefgh',
        'addon_categories' => ['addon_abcdefgh'],
    ]))->resource;
    $updateRequest = ProductApiUpdateRequest::create('/products/' . $created->public_id, 'PATCH', [
        'name'     => 'Categorized product',
        'price'    => 1000,
        'category' => [
            'name'        => 'Replacement category',
            'description' => 'Created while updating',
            'tags'        => ['replacement'],
        ],
    ]);
    $updateRequest->setLaravelSession(new SessionStore(
        'product-api-category-update-test',
        new ArraySessionHandler(120)
    ));
    $updateRequest->session()->put([
        'company'          => 'company_uuid',
        'storefront_store' => 'store_uuid',
        'storefront_key'   => 'store_key',
    ]);
    app()->instance('request', $updateRequest);

    $updated     = (new ProductController())->update($created->public_id, $updateRequest)->resource;
    $replacement = $connection->table('categories')->where('name', 'Replacement category')->first();

    expect($created->category_uuid)->toBe('existing_category_uuid')
        ->and($connection->table('product_addon_categories')->where('product_uuid', $created->uuid)->value('category_uuid'))->toBe('existing_addon_category_uuid')
        ->and($replacement->owner_uuid)->toBe('store_uuid')
        ->and($replacement->for)->toBe('storefront_product')
        ->and($updated->category_uuid)->toBe($replacement->uuid);
});

test('public product query and lookup stay inside the active network membership', function () {
    createProductApiControllerSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('networks')->insert([
        'uuid'      => 'network_uuid',
        'public_id' => 'network_abcdefgh',
        'name'      => 'Marketplace',
    ]);
    $connection->table('stores')->insert([
        [
            'uuid'      => 'member_store_uuid',
            'public_id' => 'store_member',
            'name'      => 'Member store',
        ],
        [
            'uuid'      => 'foreign_store_uuid',
            'public_id' => 'store_foreign',
            'name'      => 'Foreign store',
        ],
    ]);
    $connection->table('network_stores')->insert([
        'network_uuid' => 'network_uuid',
        'store_uuid'   => 'member_store_uuid',
    ]);
    $connection->table('products')->insert([
        [
            'uuid'         => 'member_product_uuid',
            'public_id'    => 'product_member',
            'store_uuid'   => 'member_store_uuid',
            'name'         => 'Member product',
            'is_available' => 1,
            'status'       => 'published',
            'meta'         => '{}',
        ],
        [
            'uuid'         => 'foreign_product_uuid',
            'public_id'    => 'product_foreign',
            'store_uuid'   => 'foreign_store_uuid',
            'name'         => 'Foreign product',
            'is_available' => 1,
            'status'       => 'published',
            'meta'         => '{}',
        ],
        [
            'uuid'         => 'draft_product_uuid',
            'public_id'    => 'product_draft',
            'store_uuid'   => 'member_store_uuid',
            'name'         => 'Draft product',
            'is_available' => 1,
            'status'       => 'draft',
            'meta'         => '{}',
        ],
    ]);
    session([
        'storefront_store'   => null,
        'storefront_network' => 'network_uuid',
    ]);
    $controller = new ProductController();

    $memberQuery = $controller->query(productApiRequest('/products', 'GET', [
        'store'      => 'store_member',
        'with_store' => true,
    ]));
    $foreignQuery = $controller->query(productApiRequest('/products', 'GET', ['store' => 'store_foreign']));
    $member       = $controller->find('product_member');
    $foreign      = $controller->find('product_foreign');
    $draft        = $controller->find('product_draft');

    expect($memberQuery->resource)->toHaveCount(1)
        ->and($memberQuery->resource->pluck('uuid')->all())->toContain('member_product_uuid')
        ->and($memberQuery->resource->first()->relationLoaded('store'))->toBeTrue()
        ->and($foreignQuery->resource)->toBeEmpty()
        ->and($member)->toBeInstanceOf(Fleetbase\Storefront\Http\Resources\Product::class)
        ->and($foreign->getStatusCode())->toBe(404)
        ->and($draft->getStatusCode())->toBe(404);
});

test('product query applies a known storefront category filter', function () {
    createProductApiControllerSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('categories')->insert([
        'uuid'         => 'category_uuid',
        'public_id'    => 'category_drinks',
        'company_uuid' => 'company_uuid',
        'owner_uuid'   => 'store_uuid',
        'for'          => 'storefront_product',
    ]);
    $connection->table('products')->insert([
        [
            'uuid'          => 'drink_uuid',
            'public_id'     => 'product_drink',
            'company_uuid'  => 'company_uuid',
            'store_uuid'    => 'store_uuid',
            'category_uuid' => 'category_uuid',
            'name'          => 'Drink',
            'is_available'  => true,
            'status'        => 'published',
        ],
        [
            'uuid'          => 'food_uuid',
            'public_id'     => 'product_food',
            'company_uuid'  => 'company_uuid',
            'store_uuid'    => 'store_uuid',
            'category_uuid' => 'other_category',
            'name'          => 'Food',
            'is_available'  => true,
            'status'        => 'published',
        ],
    ]);
    session([
        'company'            => 'company_uuid',
        'storefront_store'   => 'store_uuid',
        'storefront_network' => null,
    ]);

    $resource = (new ProductController())->query(productApiRequest(
        '/products',
        'GET',
        ['category' => 'category_drinks']
    ));

    expect($resource->resource)->toHaveCount(1)
        ->and($resource->resource->first()->uuid)->toBe('drink_uuid');
});

test('product update find and delete expose stable not-found responses', function () {
    createProductApiControllerSchema();
    session(['company' => null]);
    $controller = new ProductController();

    $update = $controller->update(
        'product_missing',
        UpdateProductRequest::create('/products/product_missing', 'PATCH')
    );
    $find   = $controller->find('product_missing');
    $delete = $controller->delete('product_missing');

    expect($update->getStatusCode())->toBe(404)
        ->and($update->getData(true))->toBe(['error' => 'Product not found.'])
        ->and($find->getData(true))->toBe(['error' => 'Product resource not found.'])
        ->and($delete->getData(true))->toBe(['error' => 'Product resource not found.']);
});

test('product update find and delete preserve resource and category contracts', function () {
    createProductApiControllerSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('categories')->insert([
        'uuid'         => 'category_updated_uuid',
        'public_id'    => 'category_updated',
        'company_uuid' => 'company_uuid',
        'owner_uuid'   => 'store_uuid',
        'name'         => 'Updated category',
        'for'          => 'storefront_product',
    ]);
    $connection->table('products')->insert([
        'uuid'          => 'product_uuid',
        'public_id'     => 'product_existing',
        'company_uuid'  => 'company_uuid',
        'store_uuid'    => 'store_uuid',
        'category_uuid' => null,
        'name'          => 'Original name',
        'price'         => 1000,
        'currency'      => 'USD',
        'is_available'  => true,
        'status'        => 'published',
    ]);
    session([
        'company'          => 'company_uuid',
        'user'             => 'user_uuid',
        'storefront_store' => 'store_uuid',
        'storefront_key'   => 'store_key',
    ]);
    $request = ProductApiUpdateRequest::create('/products/product_existing', 'PATCH', [
        'name'         => 'Updated name',
        'price'        => 3000,
        'sale_price'   => 2500,
        'tags'         => ['updated'],
        'youtube_urls' => ['https://example.test/updated'],
        'category'     => 'category_updated',
        'status'       => 'published',
    ]);
    $request->setLaravelSession(new SessionStore(
        'product-api-update-test',
        new ArraySessionHandler(120)
    ));
    $request->session()->put([
        'company'          => 'company_uuid',
        'storefront_store' => 'store_uuid',
        'storefront_key'   => 'store_key',
    ]);
    app()->instance('request', $request);
    $controller = new ProductController();

    $updated = $controller->update('product_existing', $request);
    $found   = $controller->find('product_existing');
    $deleted = $controller->delete('product_existing');

    expect($updated->resource->name)->toBe('Updated name')
        ->and($updated->resource->category_uuid)->toBe('category_updated_uuid')
        ->and($updated->resource->price)->toBe(3000)
        ->and($updated->resource->sale_price)->toBe(2500)
        ->and($updated->resource->tags)->toBe(['updated'])
        ->and($found->resource->uuid)->toBe('product_uuid')
        ->and($deleted->resource->uuid)->toBe('product_uuid')
        ->and($connection->table('products')->where('uuid', 'product_uuid')->value('deleted_at'))->not->toBeNull();
});

test('product update resolves public ids while synchronizing addon and variant relationships', function () {
    createProductApiControllerSchema();
    $connection        = Model::getConnectionResolver()->connection('mysql');
    $productUuid       = '11111111-1111-4111-8111-111111111111';
    $addonCategoryUuid = '22222222-2222-4222-8222-222222222222';
    $pivotUuid         = '33333333-3333-4333-8333-333333333333';
    $variantUuid       = '44444444-4444-4444-8444-444444444444';
    $optionUuid        = '55555555-5555-4555-8555-555555555555';
    $connection->table('products')->insert([
        'uuid'         => $productUuid,
        'public_id'    => 'product_relations',
        'company_uuid' => 'company_uuid',
        'store_uuid'   => 'store_uuid',
        'name'         => 'Configurable meal',
        'price'        => 1000,
        'currency'     => 'USD',
        'is_available' => true,
    ]);
    $connection->table('categories')->insert([
        'uuid'         => $addonCategoryUuid,
        'public_id'    => 'addon_abcdefgh',
        'company_uuid' => 'company_uuid',
        'name'         => 'Extras',
        'for'          => 'storefront_product_addon',
    ]);
    $connection->table('product_addon_categories')->insert([
        'uuid'           => $pivotUuid,
        'public_id'      => 'pac_abcdefgh',
        'product_uuid'   => $productUuid,
        'category_uuid'  => $addonCategoryUuid,
        'max_selectable' => 1,
    ]);
    $connection->table('product_variants')->insert([
        'uuid'         => $variantUuid,
        'public_id'    => 'variant_abcdefgh',
        'product_uuid' => $productUuid,
        'name'         => 'Size',
        'min'          => 1,
        'max'          => 1,
    ]);
    $connection->table('product_variant_options')->insert([
        'uuid'                 => $optionUuid,
        'public_id'            => 'variantoption_abcdefgh',
        'product_variant_uuid' => $variantUuid,
        'name'                 => 'Large',
        'additional_cost'      => 100,
    ]);
    session([
        'company'          => 'company_uuid',
        'user'             => 'user_uuid',
        'storefront_store' => 'store_uuid',
        'storefront_key'   => 'store_key',
    ]);
    $request = ProductApiUpdateRequest::create('/products/product_relations', 'PATCH', [
        'name'             => 'Configurable meal',
        'price'            => 1000,
        'addon_categories' => [
            [
                'id'              => 'pac_abcdefgh',
                'category'        => 'addon_abcdefgh',
                'excluded_addons' => ['addon_sold_out'],
                'max_selectable'  => 3,
                'is_required'     => true,
            ],
        ],
        'variants' => [
            [
                'id'             => 'variant_abcdefgh',
                'name'           => 'Portion size',
                'description'    => 'Updated size',
                'is_multiselect' => false,
                'is_required'    => true,
                'min'            => 1,
                'max'            => 2,
                'options'        => [
                    [
                        'id'              => 'variantoption_abcdefgh',
                        'name'            => 'Extra large',
                        'additional_cost' => '3.50',
                    ],
                    [
                        'name'            => 'Family',
                        'additional_cost' => '7.00',
                    ],
                ],
            ],
            [
                'name'           => 'Temperature',
                'is_multiselect' => false,
                'is_required'    => false,
                'options'        => [
                    ['name' => 'Hot', 'additional_cost' => 0],
                ],
            ],
        ],
    ]);
    $request->setLaravelSession(new SessionStore(
        'product-api-relations-test',
        new ArraySessionHandler(120)
    ));
    $request->session()->put([
        'company'          => 'company_uuid',
        'storefront_store' => 'store_uuid',
        'storefront_key'   => 'store_key',
    ]);
    app()->instance('request', $request);

    $resource = (new ProductController())->update('product_relations', $request);

    expect($resource->resource->uuid)->toBe($productUuid)
        ->and($connection->table('product_addon_categories')->where('uuid', $pivotUuid)->value('max_selectable'))->toBe(3)
        ->and($connection->table('product_addon_categories')->where('uuid', $pivotUuid)->value('is_required'))->toBe(1)
        ->and($connection->table('product_variants')->where('uuid', $variantUuid)->value('name'))->toBe('Portion size')
        ->and($connection->table('product_variant_options')->where('uuid', $optionUuid)->value('name'))->toBe('Extra large')
        ->and($connection->table('product_variant_options')->where('uuid', $optionUuid)->value('additional_cost'))->toBe(350)
        ->and($connection->table('product_variant_options')->where('product_variant_uuid', $variantUuid)->pluck('name')->all())
        ->toEqualCanonicalizing(['Extra large', 'Family'])
        ->and($connection->table('product_variants')->where('product_uuid', $productUuid)->pluck('name')->all())
        ->toEqualCanonicalizing(['Portion size', 'Temperature']);
});
