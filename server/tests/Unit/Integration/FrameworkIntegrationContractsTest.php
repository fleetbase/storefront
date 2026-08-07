<?php

use Fleetbase\FleetOps\Http\Filter\ContactFilter;
use Fleetbase\FleetOps\Http\Filter\OrderFilter as FleetOpsOrderFilter;
use Fleetbase\FleetOps\Http\Filter\VendorFilter;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Models\Invite;
use Fleetbase\Models\User;
use Fleetbase\Storefront\Expansions\ContactFilterExpansion;
use Fleetbase\Storefront\Expansions\EntityExpansion;
use Fleetbase\Storefront\Expansions\OrderExpansion;
use Fleetbase\Storefront\Expansions\OrderFilterExpansion;
use Fleetbase\Storefront\Expansions\VendorFilterExpansion;
use Fleetbase\Storefront\Jobs\DownloadProductImageUrl;
use Fleetbase\Storefront\Listeners\HandleOrderCompleted;
use Fleetbase\Storefront\Listeners\HandleOrderDispatched;
use Fleetbase\Storefront\Listeners\HandleOrderDriverAssigned;
use Fleetbase\Storefront\Listeners\HandleOrderStarted;
use Fleetbase\Storefront\Mail\StorefrontNetworkInvite;
use Fleetbase\Storefront\Models\Catalog;
use Fleetbase\Storefront\Models\FoodTruck;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Product;
use Fleetbase\Storefront\Observers\CatalogObserver;
use Fleetbase\Storefront\Observers\FoodTruckObserver;
use Fleetbase\Storefront\Observers\NetworkObserver;
use Fleetbase\Storefront\Observers\OrderObserver;
use Fleetbase\Storefront\Observers\ProductObserver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class StorefrontListenerCustomerSpy
{
    public array $notifications = [];

    public function notify(object $notification): void
    {
        $this->notifications[] = $notification;
    }
}

class DownloadProductImageUrlStub extends DownloadProductImageUrl
{
    public Fleetbase\Models\File $image;

    protected function downloadProductImage(Product $product)
    {
        return $this->image;
    }
}

class StorefrontListenerOrder extends Order
{
    public static ?StorefrontListenerCustomerSpy $customerSpy = null;

    public function load($relations)
    {
        $this->setRelation('customer', static::$customerSpy);
        $driver = new Fleetbase\FleetOps\Models\Driver();
        $driver->forceFill(['name' => 'Ada Driver']);
        $this->setRelation('driverAssigned', $driver);

        return $this;
    }
}

function builderOnFilter(object $filter, Builder $builder): void
{
    $property = new ReflectionProperty(Fleetbase\Http\Filter\Filter::class, 'builder');
    $property->setValue($filter, $builder);
}

test('expansion targets point to their intended Fleetbase runtime classes', function () {
    expect(ContactFilterExpansion::target())->toBe(ContactFilter::class)
        ->and(EntityExpansion::target())->toBe(Entity::class)
        ->and(OrderExpansion::target())->toBe(Order::class)
        ->and(OrderFilterExpansion::target())->toBe(FleetOpsOrderFilter::class)
        ->and(VendorFilterExpansion::target())->toBe(VendorFilter::class);
});

test('entity expansion maps storefront products into logistics entities', function () {
    session(['company' => 'company_uuid']);
    $product = new Product();
    $product->forceFill([
        'uuid'               => 'product_uuid',
        'public_id'          => 'product_public',
        'primary_image_uuid' => 'file_uuid',
        'name'               => 'Cold Brew',
        'description'        => 'Ready to drink',
        'currency'           => 'USD',
        'sku'                => 'CB-1',
        'price'              => 500,
        'sale_price'         => 450,
    ]);
    $product->setRelation('primaryImage', (object) ['url' => 'https://cdn.example.test/cold-brew.png']);
    $product->setRelation('files', collect());

    $factory = EntityExpansion::fromStorefrontProduct();
    $entity  = $factory($product, ['source' => 'catalog']);

    expect($entity)->toBeInstanceOf(Entity::class)
        ->and($entity->company_uuid)->toBe('company_uuid')
        ->and($entity->internal_id)->toBe('product_public')
        ->and($entity->name)->toBe('Cold Brew')
        ->and($entity->price)->toBe(500)
        ->and($entity->meta)->toMatchArray([
            'product_id' => 'product_public',
            'image_url'  => 'https://cdn.example.test/cold-brew.png',
            'source'     => 'catalog',
        ]);
});

test('order expansion returns null when no storefront metadata is attached', function () {
    $order = new Order();
    $order->forceFill(['meta' => []]);

    expect(OrderExpansion::getStorefrontAttribute()->call($order))->toBeNull();
});

test('order expansion resolves store and network storefront metadata', function () {
    $connection = Illuminate\Database\Eloquent\Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('stores');
    $schema->dropIfExists('networks');
    $schema->create('stores', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('networks', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $connection->table('stores')->insert([
        'uuid'      => 'store_uuid',
        'public_id' => 'store_public',
    ]);
    $connection->table('networks')->insert([
        'uuid'      => 'network_uuid',
        'public_id' => 'network_public',
    ]);
    $storeOrder = new Order();
    $storeOrder->forceFill(['meta' => ['storefront_id' => 'store_public']]);
    $networkOrder = new Order();
    $networkOrder->forceFill(['meta' => ['storefront_network_id' => 'network_public']]);

    $store   = OrderExpansion::getStorefrontAttribute()->call($storeOrder);
    $network = OrderExpansion::getStorefrontAttribute()->call($networkOrder);

    expect($store)->toBeInstanceOf(Fleetbase\Storefront\Models\Store::class)
        ->and($store->uuid)->toBe('store_uuid')
        ->and($network)->toBeInstanceOf(Network::class)
        ->and($network->uuid)->toBe('network_uuid');
});

test('filter expansions apply storefront metadata and relationship scopes', function () {
    $request = storefrontFilterRequest('v1/storefront/resources');

    $orderFilter  = new FleetOpsOrderFilter($request);
    $orderBuilder = (new Order())->newQuery();
    builderOnFilter($orderFilter, $orderBuilder);
    OrderFilterExpansion::storefront()->call($orderFilter, 'store_public');

    $contactFilter  = new ContactFilter($request);
    $contactBuilder = (new Fleetbase\Storefront\Models\Customer())->newQuery();
    builderOnFilter($contactFilter, $contactBuilder);
    ContactFilterExpansion::storefront()->call($contactFilter, 'store_public');

    $vendorFilter  = new VendorFilter($request);
    $vendorBuilder = (new Fleetbase\FleetOps\Models\Vendor())->newQuery();
    builderOnFilter($vendorFilter, $vendorBuilder);
    VendorFilterExpansion::storefront()->call($vendorFilter, 'store_public');

    expect($orderBuilder->toSql())->toContain('json_extract')
        ->and($orderBuilder->getBindings())->toContain('store_public')
        ->and($contactBuilder->toSql())->toContain('exists')
        ->and($contactBuilder->getBindings())->toContain('store_public')
        ->and($vendorBuilder->toSql())->toContain('exists')
        ->and($vendorBuilder->getBindings())->toContain('store_public');
});

test('catalog and food truck observers synchronize request-backed empty assignments', function () {
    $request = Request::create('/observer', 'POST', [
        'catalog'   => ['categories' => []],
        'foodTruck' => ['catalogs' => []],
    ]);
    $request->setLaravelSession(request()->session());
    app()->instance('request', $request);
    Illuminate\Support\Facades\Facade::clearResolvedInstance('request');

    $catalog = new Catalog();
    $catalog->setRelation('categories', collect());
    (new CatalogObserver())->saved($catalog);

    $foodTruck = new FoodTruck();
    $foodTruck->setRelation('catalogs', collect());
    (new FoodTruckObserver())->saved($foodTruck);

    expect($catalog->categories)->toBeEmpty()
        ->and($foodTruck->catalogs)->toBeEmpty();
});

test('food truck observer contains malformed catalog assignments without terminating the request', function () {
    $request = Request::create('/observer', 'POST', [
        'foodTruck' => ['catalogs' => 'invalid-catalog-list'],
    ]);
    $request->setLaravelSession(request()->session());
    app()->instance('request', $request);
    Illuminate\Support\Facades\Facade::clearResolvedInstance('request');
    $foodTruck = new FoodTruck();
    $foodTruck->forceFill(['uuid' => 'food_truck_uuid']);

    expect((new FoodTruckObserver())->saved($foodTruck))->toBeNull();
});

test('catalog observer removes category product pivots when a catalog is deleted', function () {
    $connection = Illuminate\Database\Eloquent\Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('categories');
    $schema->dropIfExists('catalog_category_products');
    $schema->create('categories', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('owner_uuid');
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('catalog_category_products', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('catalog_category_uuid');
        $table->string('product_uuid');
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $connection->table('categories')->insert([
        'uuid'       => 'category_uuid',
        'owner_uuid' => 'catalog_uuid',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $connection->table('catalog_category_products')->insert([
        'catalog_category_uuid' => 'category_uuid',
        'product_uuid'          => 'product_uuid',
        'created_at'            => now(),
        'updated_at'            => now(),
    ]);

    $catalog = new Catalog(['uuid' => 'catalog_uuid']);
    (new CatalogObserver())->deleted($catalog);

    expect($connection->table('categories')->where('uuid', 'category_uuid')->value('deleted_at'))->not->toBeNull()
        ->and($connection->table('catalog_category_products')
            ->where('catalog_category_uuid', 'category_uuid')
            ->value('deleted_at'))->not->toBeNull();
});

test('product observer accepts empty nested assignments without side effects', function () {
    $request = Request::create('/observer', 'POST', [
        'product' => [
            'addon_categories' => [],
            'variants'         => [],
            'files'            => [],
        ],
    ]);
    $request->setLaravelSession(request()->session());
    app()->instance('request', $request);
    Illuminate\Support\Facades\Facade::clearResolvedInstance('request');

    $product = new Product();
    $product->forceFill(['uuid' => 'product_uuid']);

    (new ProductObserver())->saved($product);

    expect($product->uuid)->toBe('product_uuid');
});

test('product observer associates submitted files and ignores stale file identifiers', function () {
    $schema = Illuminate\Database\Capsule\Manager::schema('mysql');
    $schema->dropIfExists('files');
    $schema->create('files', function (Illuminate\Database\Schema\Blueprint $table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('type')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Illuminate\Database\Capsule\Manager::connection('mysql')->table('files')->insert([
        'uuid' => 'file_uuid',
    ]);

    $request = Request::create('/observer', 'POST', [
        'product' => [
            'addon_categories' => [],
            'variants'         => [],
            'files'            => [
                ['uuid' => 'file_uuid'],
                ['uuid' => 'stale_file_uuid'],
            ],
        ],
    ]);
    $request->setLaravelSession(request()->session());
    app()->instance('request', $request);
    Illuminate\Support\Facades\Facade::clearResolvedInstance('request');

    $product = new Product();
    $product->forceFill(['uuid' => 'product_uuid']);

    (new ProductObserver())->saved($product);

    $file = Illuminate\Database\Capsule\Manager::connection('mysql')->table('files')->where('uuid', 'file_uuid')->first();
    expect($file->subject_uuid)->toBe('product_uuid')
        ->and($file->subject_type)->toContain('Product');
});

test('product observer logs and rethrows assignment failures', function () {
    $request = Request::create('/observer', 'POST', [
        'product' => [
            'addon_categories' => [],
            'variants'         => [],
            'files'            => [],
        ],
    ]);
    $request->setLaravelSession(request()->session());
    app()->instance('request', $request);
    Illuminate\Support\Facades\Facade::clearResolvedInstance('request');

    $product = new class extends Product {
        public function setAddonCategories(array $addonCategories = []): Product
        {
            throw new RuntimeException('Unable to assign product categories');
        }
    };

    expect(fn () => (new ProductObserver())->saved($product))
        ->toThrow(RuntimeException::class, 'Unable to assign product categories');
});

test('network observer removes invalid alert groups and keeps normalized recipients', function () {
    $request = Request::create('/observer', 'POST', [
        'network' => [
            'alertable' => [
                'orders'   => ['user_a', 'user_b'],
                'invalid'  => 'not-an-array',
                'payments' => ['user_c'],
            ],
        ],
    ]);
    $request->setLaravelSession(request()->session());
    app()->instance('request', $request);
    Illuminate\Support\Facades\Facade::clearResolvedInstance('request');

    $network = new Network();
    (new NetworkObserver())->updating($network);

    expect($network->alertable)->toBe([
        'orders'   => ['user_a', 'user_b'],
        'payments' => ['user_c'],
    ]);
});

test('order observer preserves an explicitly selected order configuration', function () {
    $order = new Order();
    $order->forceFill(['order_config_uuid' => 'config_uuid']);

    (new OrderObserver())->creating($order);

    expect($order->order_config_uuid)->toBe('config_uuid');
});

test('order lifecycle listeners ignore non-storefront orders without notifying customers', function () {
    $connection = Illuminate\Database\Eloquent\Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('orders');
    $schema->create('orders', function ($table) {
        $table->string('uuid')->primary();
        $table->text('meta')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $connection->table('orders')->insert([
        'uuid' => 'order_uuid',
        'meta' => json_encode([]),
    ]);

    $cases = [
        [Fleetbase\FleetOps\Events\OrderCompleted::class, new HandleOrderCompleted()],
        [Fleetbase\FleetOps\Events\OrderDispatched::class, new HandleOrderDispatched()],
        [Fleetbase\FleetOps\Events\OrderDriverAssigned::class, new HandleOrderDriverAssigned()],
        [Fleetbase\FleetOps\Events\OrderStarted::class, new HandleOrderStarted()],
    ];

    foreach ($cases as [$eventClass, $listener]) {
        $event                      = (new ReflectionClass($eventClass))->newInstanceWithoutConstructor();
        $event->modelUuid           = 'order_uuid';
        $event->modelClassNamespace = Order::class;

        expect($listener->handle($event))->toBeNull();
    }
});

test('download image job serializes only the product id and source URL', function () {
    $product = new Product();
    $product->forceFill(['uuid' => 'product_uuid']);
    $job     = new DownloadProductImageUrl($product, 'https://images.example.test/product.png');

    expect($job->product)->toBe('product_uuid')
        ->and($job->url)->toBe('https://images.example.test/product.png');
});

test('download image job ignores stale products and invalid image URLs without external calls', function () {
    $connection = Illuminate\Database\Eloquent\Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('products');
    $schema->create('products', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('primary_image_uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $connection->table('products')->insert([
        'uuid'               => 'product_uuid',
        'primary_image_uuid' => 'existing_image_uuid',
    ]);
    $product = new Product();
    $product->forceFill(['uuid' => 'product_uuid']);
    $missing = new Product();
    $missing->forceFill(['uuid' => 'missing_product_uuid']);

    (new DownloadProductImageUrl($missing, 'not-a-url'))->handle();
    (new DownloadProductImageUrl($product, 'not-a-url'))->handle();

    expect($connection->table('products')->where('uuid', 'product_uuid')->value('primary_image_uuid'))
        ->toBe('existing_image_uuid');
});

test('download image job assigns a successfully stored file as the product primary image', function () {
    $connection = Illuminate\Database\Eloquent\Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('products');
    $schema->create('products', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('primary_image_uuid')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $connection->table('products')->insert([
        'uuid'               => 'product_uuid',
        'primary_image_uuid' => null,
    ]);
    $product = new Product();
    $product->forceFill(['uuid' => 'product_uuid']);
    $image = new Fleetbase\Models\File();
    $image->forceFill(['uuid' => 'file_uuid']);
    $job        = new DownloadProductImageUrlStub($product, 'https://images.example.test/product.png');
    $job->image = $image;

    $job->handle();

    expect($connection->table('products')->where('uuid', 'product_uuid')->value('primary_image_uuid'))
        ->toBe('file_uuid');
});

test('order lifecycle listeners notify storefront customers with transition-specific messages', function () {
    $connection = Illuminate\Database\Eloquent\Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('orders');
    $schema->dropIfExists('stores');
    $schema->create('orders', function ($table) {
        $table->string('uuid')->primary();
        $table->text('meta')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('stores', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('backdrop_uuid')->nullable();
        $table->string('logo_uuid')->nullable();
        $table->string('order_config_uuid')->nullable();
        $table->string('name')->nullable();
        $table->text('description')->nullable();
        $table->text('translations')->nullable();
        $table->string('website')->nullable();
        $table->string('facebook')->nullable();
        $table->string('instagram')->nullable();
        $table->string('twitter')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->text('tags')->nullable();
        $table->string('currency')->nullable();
        $table->string('timezone')->nullable();
        $table->string('pod_method')->nullable();
        $table->text('options')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $connection->table('stores')->insert([
        'uuid'      => 'store_uuid',
        'public_id' => 'store_public',
        'name'      => 'Test Store',
    ]);
    $connection->table('orders')->insert([
        'uuid' => 'storefront_order_uuid',
        'meta' => json_encode([
            'storefront_id' => 'store_public',
            'is_pickup'     => true,
        ]),
    ]);
    StorefrontListenerOrder::$customerSpy = new StorefrontListenerCustomerSpy();
    $cases                                = [
        [Fleetbase\FleetOps\Events\OrderCompleted::class, new HandleOrderCompleted(), Fleetbase\Storefront\Notifications\StorefrontOrderCompleted::class],
        [Fleetbase\FleetOps\Events\OrderDispatched::class, new HandleOrderDispatched(), Fleetbase\Storefront\Notifications\StorefrontOrderReadyForPickup::class],
        [Fleetbase\FleetOps\Events\OrderDriverAssigned::class, new HandleOrderDriverAssigned(), Fleetbase\Storefront\Notifications\StorefrontOrderDriverAssigned::class],
        [Fleetbase\FleetOps\Events\OrderStarted::class, new HandleOrderStarted(), Fleetbase\Storefront\Notifications\StorefrontOrderEnroute::class],
    ];

    foreach ($cases as [$eventClass, $listener, $notificationClass]) {
        $event                      = (new ReflectionClass($eventClass))->newInstanceWithoutConstructor();
        $event->modelUuid           = 'storefront_order_uuid';
        $event->modelClassNamespace = StorefrontListenerOrder::class;
        $before                     = count(StorefrontListenerOrder::$customerSpy->notifications);

        $listener->handle($event);

        expect(StorefrontListenerOrder::$customerSpy->notifications)->toHaveCount($before + 1)
            ->and(StorefrontListenerOrder::$customerSpy->notifications[$before])->toBeInstanceOf($notificationClass);
    }
});

test('driver assignment listener ignores lifecycle events that do not resolve to an order', function () {
    $connection = Illuminate\Database\Eloquent\Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('products');
    $schema->create('products', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $connection->table('products')->insert(['uuid' => 'product_uuid']);
    $event                      = (new ReflectionClass(Fleetbase\FleetOps\Events\OrderDriverAssigned::class))->newInstanceWithoutConstructor();
    $event->modelUuid           = 'product_uuid';
    $event->modelClassNamespace = Product::class;

    expect((new HandleOrderDriverAssigned())->handle($event))->toBeNull();
});

test('network invite mailable derives sender network recipients and join URL', function () {
    $network = new Network(['name' => 'Coffee Network']);
    $sender  = new User();
    $sender->forceFill(['name' => 'Ada Admin']);
    $invite = new Invite();
    $invite->forceFill([
        'uri'        => 'invite-code',
        'recipients' => ['merchant@example.test'],
    ]);
    $invite->setRelation('subject', $network);
    $invite->setRelation('createdBy', $sender);

    $mail = (new StorefrontNetworkInvite($invite))->build();

    expect($mail->invite)->toBe($invite)
        ->and($mail->network)->toBe($network)
        ->and($mail->sender)->toBe($sender)
        ->and($mail->url)->toContain('join/network/invite-code')
        ->and($mail->subject)->toBe('You have been invited to join Coffee Network!')
        ->and($mail->to[0]['address'])->toBe('merchant@example.test');
});
