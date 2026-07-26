<?php

use Fleetbase\Storefront\Http\Controllers\ProductController;
use Fleetbase\Storefront\Http\Controllers\v1\CartController;
use Fleetbase\Storefront\Http\Controllers\v1\CategoryController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class CartControllerOperationStub extends Fleetbase\Storefront\Models\Cart
{
    public array $calls = [];

    public function add($product, $quantity = 1, $variants = [], $addons = [], $storeLocationId = null, $scheduledAt = null, $createdAt = null)
    {
        $this->calls['add'] = func_get_args();

        return (object) ['id' => 'line_item'];
    }

    public function updateItem($cartItem, $quantity = 1, $variants = [], $addons = [], $scheduledAt = null)
    {
        $this->calls['update'] = func_get_args();

        return (object) ['id' => $cartItem];
    }

    public function remove($cartItem)
    {
        $this->calls['remove'] = func_get_args();

        return $this;
    }

    public function empty()
    {
        $this->calls['empty'] = true;

        return $this;
    }

    public function delete()
    {
        $this->calls['delete'] = true;

        return true;
    }
}

class TestableCartController extends CartController
{
    public ?Fleetbase\Storefront\Models\Cart $cart = null;
    public array $retrievals                       = [];

    protected function retrieveCart(?string $uniqueId, bool $create = false): Fleetbase\Storefront\Models\Cart
    {
        $this->retrievals[] = [$uniqueId, $create];

        return $this->cart;
    }
}

function createPublicCartControllerSchema(): void
{
    $schema = Model::getConnectionResolver()->connection('mysql')->getSchemaBuilder();
    $schema->dropIfExists('carts');
    $schema->create('carts', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('checkout_uuid')->nullable();
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
}

test('cart retrieval creates a fresh cart when no identifier is supplied', function () {
    createPublicCartControllerSchema();
    session([
        'company'             => 'company_uuid',
        'storefront_currency' => 'USD',
        'customer_id'         => 'customer_public',
    ]);

    $resource = (new CartController())->retrieve(null, Request::create('/cart'));
    $cart     = $resource->resource;

    expect($cart->exists)->toBeTrue()
        ->and($cart->company_uuid)->toBe('company_uuid')
        ->and($cart->currency)->toBe('USD')
        ->and($cart->customer_id)->toBe('customer_public')
        ->and($cart->items)->toBe([])
        ->and($cart->events)->toBe([]);
});

test('cart retrieval reuses a caller identifier and excludes checked out carts', function () {
    createPublicCartControllerSchema();
    session(['company' => 'company_uuid']);
    $controller = new CartController();

    $first  = $controller->retrieve('browser-session-1', Request::create('/cart'))->resource;
    $second = $controller->retrieve('browser-session-1', Request::create('/cart'))->resource;
    $first->forceFill(['checkout_uuid' => 'checkout_uuid'])->save();
    $replacement = $controller->retrieve('browser-session-1', Request::create('/cart'))->resource;
    $cartRows    = Model::getConnectionResolver()->connection('mysql')->table('carts')
        ->where('unique_identifier', 'browser-session-1')
        ->get();

    expect($second->unique_identifier)->toBe('browser-session-1')
        ->and($replacement->unique_identifier)->toBe('browser-session-1');
    expect($cartRows)->toHaveCount(2)
        ->and($cartRows->whereNotNull('checkout_uuid'))->toHaveCount(1)
        ->and($cartRows->whereNull('checkout_uuid'))->toHaveCount(1);
});

test('cart controller reports invalid product and line item operations', function () {
    createPublicCartControllerSchema();
    $schema = Model::getConnectionResolver()->connection('mysql')->getSchemaBuilder();
    $schema->dropIfExists('products');
    $schema->create('products', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $controller = new CartController();

    $add = $controller->add(
        'browser-session-2',
        'missing_product',
        Request::create('/cart/items', 'POST', [
            'quantity'       => 2,
            'variants'       => [],
            'addons'         => [],
            'store_location' => 'location_public',
            'scheduled_at'   => '2026-07-27 12:00:00',
        ])
    );
    $update = $controller->update(
        'browser-session-2',
        'missing_item',
        Request::create('/cart/items', 'PATCH', [
            'quantity' => 3,
        ])
    );
    $remove = $controller->remove(
        'browser-session-2',
        'missing_item',
        Request::create('/cart/items', 'DELETE')
    );

    expect($add->getStatusCode())->toBe(400)
        ->and($add->getData(true))->toHaveKey('error')
        ->and($update->getStatusCode())->toBe(400)
        ->and($update->getData(true))->toHaveKey('error')
        ->and($remove->getStatusCode())->toBe(400)
        ->and($remove->getData(true))->toHaveKey('error');
});

test('cart controller delegates successful item and lifecycle operations with request options', function () {
    $cart = new CartControllerOperationStub();
    $cart->forceFill([
        'uuid'              => 'cart_uuid',
        'public_id'         => 'cart_abcdefgh',
        'unique_identifier' => 'browser-session',
        'currency'          => 'USD',
        'items'             => [],
        'events'            => [],
    ]);
    $controller       = new TestableCartController();
    $controller->cart = $cart;

    $retrieved = $controller->retrieve('browser-session', Request::create('/cart'));
    $added     = $controller->add(
        'browser-session',
        'product_abcdefgh',
        Request::create('/cart/items', 'POST', [
            'quantity'       => 2,
            'variants'       => [['name' => 'Large']],
            'addons'         => [['name' => 'Insurance']],
            'store_location' => 'store_location_abcdefgh',
            'scheduled_at'   => '2026-07-28 09:00:00',
        ])
    );
    $updated = $controller->update(
        'browser-session',
        'line_item_abcdefgh',
        Request::create('/cart/items', 'PATCH', [
            'quantity'     => 3,
            'variants'     => [['name' => 'Small']],
            'addons'       => [],
            'scheduled_at' => '2026-07-29 10:00:00',
        ])
    );
    $removed = $controller->remove(
        'browser-session',
        'line_item_abcdefgh',
        Request::create('/cart/items', 'DELETE')
    );
    $emptied = $controller->empty('browser-session');
    $deleted = $controller->delete('browser-session');

    expect($retrieved->resource)->toBe($cart)
        ->and($added->resource)->toBe($cart)
        ->and($updated->resource)->toBe($cart)
        ->and($removed->resource)->toBe($cart)
        ->and($emptied->resource)->toBe($cart)
        ->and($deleted->getData(true))->toBe([])
        ->and($controller->retrievals)->toBe([
            ['browser-session', true],
            ['browser-session', false],
            ['browser-session', false],
            ['browser-session', false],
            ['browser-session', false],
            ['browser-session', false],
        ])
        ->and($cart->calls['add'])->toBe([
            'product_abcdefgh',
            2,
            [['name' => 'Large']],
            [['name' => 'Insurance']],
            'store_location_abcdefgh',
            '2026-07-28 09:00:00',
        ])
        ->and($cart->calls['update'])->toBe([
            'line_item_abcdefgh',
            3,
            [['name' => 'Small']],
            [],
            '2026-07-29 10:00:00',
        ])
        ->and($cart->calls['remove'])->toBe(['line_item_abcdefgh'])
        ->and($cart->calls['empty'])->toBeTrue()
        ->and($cart->calls['delete'])->toBeTrue();
});

test('internal product entity creation returns an empty resource collection for no products', function () {
    $schema = Model::getConnectionResolver()->connection('mysql')->getSchemaBuilder();
    $schema->dropIfExists('products');
    $schema->create('products', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });

    $resource = (new ProductController())->createEntities(Request::create('/products/entities', 'POST', [
        'products' => [],
    ]));

    expect($resource->resource)->toBeEmpty();
});

test('internal product entity creation persists logistics entities for selected products', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    foreach (['entities', 'files', 'products'] as $table) {
        $schema->dropIfExists($table);
    }
    $schema->create('products', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('primary_image_uuid')->nullable();
        $table->string('name')->nullable();
        $table->text('description')->nullable();
        $table->string('currency')->nullable();
        $table->string('sku')->nullable();
        $table->integer('price')->default(0);
        $table->integer('sale_price')->default(0);
        $table->text('meta')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('files', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('url')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('entities', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('photo_uuid')->nullable();
        $table->string('internal_id')->nullable();
        $table->string('name')->nullable();
        $table->text('description')->nullable();
        $table->string('currency')->nullable();
        $table->string('sku')->nullable();
        $table->integer('price')->nullable();
        $table->integer('sale_price')->nullable();
        $table->string('type')->nullable();
        $table->text('meta')->nullable();
        $table->string('slug')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $connection->table('products')->insert([
        [
            'uuid'        => 'product_one_uuid',
            'public_id'   => 'product_abcdefgh',
            'name'        => 'Box',
            'description' => 'Reusable box',
            'currency'    => 'USD',
            'sku'         => 'BOX-1',
            'price'       => 1000,
            'sale_price'  => 800,
            'meta'        => '{}',
        ],
        [
            'uuid'        => 'product_other_uuid',
            'public_id'   => 'product_other',
            'name'        => 'Not selected',
            'description' => null,
            'currency'    => null,
            'sku'         => null,
            'price'       => 0,
            'sale_price'  => 0,
            'meta'        => '{}',
        ],
    ]);
    session(['company' => 'company_uuid']);
    Fleetbase\FleetOps\Models\Entity::expand(
        'fromStorefrontProduct',
        Fleetbase\Storefront\Expansions\EntityExpansion::fromStorefrontProduct()
    );

    $resource = (new ProductController())->createEntities(Request::create('/products/entities', 'POST', [
        'products' => ['product_one_uuid'],
    ]));
    $entity = $connection->table('entities')->first();

    expect($resource->resource)->toHaveCount(1)
        ->and($entity->company_uuid)->toBe('company_uuid')
        ->and($entity->internal_id)->toBe('product_abcdefgh')
        ->and($entity->name)->toBe('Box')
        ->and($entity->type)->toBe('storefront-product')
        ->and(json_decode($entity->meta, true)['product_id'])->toBe('product_abcdefgh');
});

test('product import with no uploaded files returns no products', function () {
    $schema = Model::getConnectionResolver()->connection('mysql')->getSchemaBuilder();
    $schema->dropIfExists('files');
    $schema->create('files', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('path')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $request = Request::create('/products/import', 'POST', ['files' => []]);
    $request->setLaravelSession(new Illuminate\Session\Store(
        'product-import-test',
        new Illuminate\Session\ArraySessionHandler(120)
    ));

    $response = (new ProductController())->processImports($request);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([]);
});

test('product import rejects unsupported files and contains spreadsheet reader failures', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('files');
    $schema->create('files', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('path')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $connection->table('files')->insert([
        ['uuid' => 'file_pdf', 'path' => 'imports/products.pdf'],
        ['uuid' => 'file_csv', 'path' => 'imports/products.csv'],
    ]);
    $session = new Illuminate\Session\Store(
        'product-import-validation',
        new Illuminate\Session\ArraySessionHandler(120)
    );
    $controller     = new ProductController();
    $invalidRequest = Request::create('/products/import', 'POST', ['files' => ['file_pdf']]);
    $invalidRequest->setLaravelSession($session);
    $readerFailureRequest = Request::create('/products/import', 'POST', ['files' => ['file_csv']]);
    $readerFailureRequest->setLaravelSession($session);

    $invalid       = $controller->processImports($invalidRequest);
    $readerFailure = $controller->processImports($readerFailureRequest);

    expect($invalid->getData(true))->toBe([
        'error' => 'Invalid file uploaded, must be one of the following: csv, tsv, xls, xlsx',
    ])->and($readerFailure->getData(true))->toBe([
        'error' => 'Invalid file, unable to proccess.',
    ]);
});

test('product import persists normalized spreadsheet rows and skips empty entries', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    foreach (['products', 'files', 'categories', 'stores'] as $table) {
        $schema->dropIfExists($table);
    }
    $schema->create('files', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('path')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('stores', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id')->nullable();
        $table->string('currency')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('categories', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('products', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('created_by_uuid')->nullable();
        $table->string('store_uuid')->nullable();
        $table->string('category_uuid')->nullable();
        $table->string('name')->nullable();
        $table->text('description')->nullable();
        $table->string('sku')->nullable();
        $table->text('tags')->nullable();
        $table->text('youtube_urls')->nullable();
        $table->integer('price')->default(0);
        $table->integer('sale_price')->default(0);
        $table->string('currency')->nullable();
        $table->boolean('is_service')->default(false);
        $table->boolean('is_bookable')->default(false);
        $table->boolean('is_on_sale')->default(false);
        $table->boolean('is_available')->default(true);
        $table->boolean('is_recommended')->default(false);
        $table->boolean('can_pickup')->default(false);
        $table->string('status')->nullable();
        $table->string('slug')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $connection->table('files')->insert([
        'uuid' => 'file_csv',
        'path' => 'imports/products.csv',
    ]);
    $connection->table('stores')->insert([
        'uuid'      => 'store_uuid',
        'public_id' => 'store_abcdefgh',
        'currency'  => 'USD',
    ]);
    $connection->table('categories')->insert([
        'uuid'      => 'category_uuid',
        'public_id' => 'category_abcdefgh',
    ]);
    Maatwebsite\Excel\Facades\Excel::swap(new class {
        public function toArray($import, string $path, string $disk): array
        {
            return [[
                [],
                [
                    'product_name'  => 'Insulated box',
                    'details'       => 'Reusable shipping box',
                    'tags'          => 'shipping,reusable',
                    'stock_number'  => 'BOX-1',
                    'cost'          => '12.50',
                    'sale_cost'     => '10.00',
                    'is_service'    => false,
                    'bookable'      => true,
                    'on_sale'       => true,
                    'available'     => true,
                    'recommended'   => true,
                    'can_pickup'    => true,
                    'youtube'       => 'https://example.test/video',
                    'primary_image' => 'https://example.test/box.png',
                ],
            ]];
        }
    });
    $request = Request::create('/products/import', 'POST', [
        'files'    => ['file_csv'],
        'store'    => 'store_uuid',
        'category' => 'category_uuid',
        'disk'     => 'local',
    ]);
    $request->setLaravelSession(new Illuminate\Session\Store(
        'product-import-success',
        new Illuminate\Session\ArraySessionHandler(120)
    ));
    $request->session()->put([
        'company' => 'company_uuid',
        'user'    => 'user_uuid',
    ]);

    $response = (new ProductController())->processImports($request);
    $product  = Fleetbase\Storefront\Models\Product::query()->first();

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toHaveCount(1)
        ->and($product->name)->toBe('Insulated box')
        ->and($product->description)->toBe('Reusable shipping box')
        ->and($product->company_uuid)->toBe('company_uuid')
        ->and($product->created_by_uuid)->toBe('user_uuid')
        ->and($product->store_uuid)->toBe('store_uuid')
        ->and($product->category_uuid)->toBe('category_uuid')
        ->and($product->currency)->toBe('USD')
        ->and($product->tags)->toBe(['shipping', 'reusable'])
        ->and($product->youtube_urls)->toBe(['https://example.test/video'])
        ->and($product->status)->toBe('published');
});

test('public category query returns an empty collection without storefront context', function () {
    session([
        'storefront_store'   => null,
        'storefront_network' => null,
    ]);

    $resource = (new CategoryController())->query(Request::create('/categories'));

    expect($resource->resource)->toBeEmpty();
});
