<?php

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Storefront\Http\Filter\AddonCategoryFilter;
use Fleetbase\Storefront\Http\Filter\CustomerFilter;
use Fleetbase\Storefront\Http\Filter\FoodTruckFilter;
use Fleetbase\Storefront\Http\Filter\GatewayFilter;
use Fleetbase\Storefront\Http\Filter\NetworkFilter;
use Fleetbase\Storefront\Http\Filter\NotificationChannelFilter;
use Fleetbase\Storefront\Http\Filter\OrderFilter;
use Fleetbase\Storefront\Http\Filter\ProductFilter;
use Fleetbase\Storefront\Http\Filter\StoreFilter;
use Fleetbase\Storefront\Http\Filter\StoreLocationFilter;
use Fleetbase\Storefront\Models\AddonCategory;
use Fleetbase\Storefront\Models\Customer;
use Fleetbase\Storefront\Models\FoodTruck;
use Fleetbase\Storefront\Models\Gateway;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\NotificationChannel;
use Fleetbase\Storefront\Models\Product;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Models\StoreLocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

function storefrontFilterRequest(string $uri, array $query = []): Request
{
    $request = Request::create('/' . $uri, 'GET', $query);
    $request->setLaravelSession(request()->session());
    $request->session()->put('company', 'company_uuid');
    $request->setRouteResolver(fn () => new class($uri) {
        public array $action = [];

        public function __construct(private string $routeUri)
        {
        }

        public function uri(): string
        {
            return $this->routeUri;
        }
    });

    return $request;
}

function applyStorefrontFilter(string $filter, Builder $builder, string $uri, array $query = []): Builder
{
    return (new $filter(storefrontFilterRequest($uri, $query)))->apply($builder);
}

test('company-scoped filters isolate internal resources to the active tenant', function ($filter, $model) {
    $builder = applyStorefrontFilter($filter, (new $model())->newQuery(), 'int/v1/storefront/resources');

    expect($builder->toSql())->toContain('"company_uuid" = ?')
        ->and($builder->getBindings())->toContain('company_uuid');
})->with([
    'gateway'              => [GatewayFilter::class, Gateway::class],
    'network'              => [NetworkFilter::class, Network::class],
    'notification channel' => [NotificationChannelFilter::class, NotificationChannel::class],
    'product'              => [ProductFilter::class, Product::class],
    'store'                => [StoreFilter::class, Store::class],
]);

test('addon category filter enforces company and storefront addon purpose', function () {
    $builder = applyStorefrontFilter(
        AddonCategoryFilter::class,
        (new AddonCategory())->newQuery(),
        'int/v1/storefront/addon-categories'
    );

    expect($builder->toSql())->toContain('"company_uuid" = ?')
        ->and($builder->toSql())->toContain('"for" = ?')
        ->and($builder->getBindings())->toContain('company_uuid', 'storefront_product_addon');
});

test('network product and store search filters apply their searchable columns', function () {
    $network = applyStorefrontFilter(
        NetworkFilter::class,
        (new Network())->newQuery(),
        'v1/storefront/networks',
        ['query' => 'coffee']
    );
    $product = applyStorefrontFilter(
        ProductFilter::class,
        (new Product())->newQuery(),
        'v1/storefront/products',
        ['query' => 'coffee']
    );
    $store = applyStorefrontFilter(
        StoreFilter::class,
        (new Store())->newQuery(),
        'v1/storefront/stores',
        ['store_query' => 'coffee']
    );

    expect($network->toSql())->toContain('lower(name) like ?')
        ->and($product->toSql())->toContain('lower(name) like ?')
        ->and($store->toSql())->toContain('lower(name) like ?')
        ->and($network->getBindings())->toContain('%coffee%')
        ->and($product->getBindings())->toContain('%coffee%')
        ->and($store->getBindings())->toContain('%coffee%');
});

test('product category slug filter resolves known categories and ignores unknown slugs', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('categories');
    $schema->create('categories', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('slug');
        $table->string('for');
        $table->timestamp('deleted_at')->nullable();
    });
    $connection->table('categories')->insert([
        'uuid' => 'category_uuid',
        'slug' => 'coffee',
        'for'  => 'storefront_product',
    ]);

    $known = applyStorefrontFilter(
        ProductFilter::class,
        (new Product())->newQuery(),
        'v1/storefront/products',
        ['category_slug' => 'coffee']
    );
    $unknown = applyStorefrontFilter(
        ProductFilter::class,
        (new Product())->newQuery(),
        'v1/storefront/products',
        ['category_slug' => 'unknown']
    );

    expect($known->toSql())->toContain('"category_uuid" = ?')
        ->and($known->getBindings())->toContain('category_uuid')
        ->and($unknown->toSql())->not->toContain('"category_uuid" = ?');
});

test('customer filter constrains customers through storefront order metadata', function () {
    $builder = applyStorefrontFilter(
        CustomerFilter::class,
        (new Customer())->newQuery(),
        'v1/storefront/customers',
        ['storefront' => 'store_public']
    );

    expect($builder->toSql())->toContain('exists')
        ->and($builder->toSql())->toContain('json_extract')
        ->and($builder->getBindings())->toContain('store_public');
});

test('food truck filters enforce tenant vehicle storefront and deleted-record contracts', function () {
    $internal = applyStorefrontFilter(
        FoodTruckFilter::class,
        (new FoodTruck())->newQuery(),
        'int/v1/storefront/food-trucks'
    );
    $public = applyStorefrontFilter(
        FoodTruckFilter::class,
        (new FoodTruck())->newQuery(),
        'v1/storefront/food-trucks',
        ['storefront' => 'store_public', 'with_deleted' => true]
    );

    expect($internal->toSql())->toContain('"company_uuid" = ?')
        ->and($public->toSql())->toContain('exists')
        ->and($public->toSql())->toContain('"public_id" = ?')
        ->and($public->getBindings())->toContain('company_uuid', 'store_public')
        ->and($public->toSql())->not->toContain('"food_trucks"."deleted_at" is null');
});

test('food truck service-area filter resolves public ids and UUIDs before constraining trucks', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('service_areas');
    $schema->create('service_areas', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id');
        $table->timestamp('deleted_at')->nullable();
    });
    $connection->table('service_areas')->insert([
        'uuid'      => 'service_area_uuid',
        'public_id' => 'service_area_public',
    ]);

    $builder = applyStorefrontFilter(
        FoodTruckFilter::class,
        (new FoodTruck())->newQuery(),
        'v1/storefront/food-trucks',
        ['service_area' => 'service_area_public']
    );

    expect($builder->toSql())->toContain('"service_area_uuid" in (?)')
        ->and($builder->getBindings())->toContain('service_area_uuid');
});

test('store filter supports uncategorized parent and explicit network categories', function () {
    $withoutCategory = applyStorefrontFilter(
        StoreFilter::class,
        (new Store())->newQuery(),
        'v1/storefront/stores',
        ['network' => 'network_uuid', 'without_category' => true]
    );
    $parent = applyStorefrontFilter(
        StoreFilter::class,
        (new Store())->newQuery(),
        'v1/storefront/stores',
        ['network' => 'network_uuid', 'category' => '_parent']
    );
    $category = applyStorefrontFilter(
        StoreFilter::class,
        (new Store())->newQuery(),
        'v1/storefront/stores',
        ['network' => 'network_uuid', 'category' => 'category_uuid']
    );

    expect($withoutCategory->toSql())->toContain('"category_uuid" is null')
        ->and($parent->toSql())->toContain('"category_uuid" is null')
        ->and($category->toSql())->toContain('"category_uuid" = ?')
        ->and($category->getBindings())->toContain('network_uuid', 'category_uuid');
});

test('store location filters scope internal records through stores and direct store ids', function () {
    $internal = applyStorefrontFilter(
        StoreLocationFilter::class,
        (new StoreLocation())->newQuery(),
        'int/v1/storefront/store-locations'
    );
    $public = applyStorefrontFilter(
        StoreLocationFilter::class,
        (new StoreLocation())->newQuery(),
        'v1/storefront/store-locations',
        ['store' => 'store_uuid']
    );

    expect($internal->toSql())->toContain('exists')
        ->and($internal->getBindings())->toContain('company_uuid')
        ->and($public->toSql())->toContain('"store_uuid" = ?')
        ->and($public->getBindings())->toContain('store_uuid');
});

test('storefront order filter applies storefront metadata tracking and payload requirements', function () {
    $builder = applyStorefrontFilter(
        OrderFilter::class,
        (new Order())->newQuery(),
        'int/v1/storefront/orders',
        ['storefront' => 'store_public']
    );

    expect($builder->toSql())->toContain('"company_uuid" = ?')
        ->and($builder->toSql())->toContain('json_extract')
        ->and($builder->toSql())->toContain('exists')
        ->and($builder->getBindings())->toContain('company_uuid', 'store_public')
        ->and(array_keys($builder->getEagerLoads()))->toContain(
            'payload.entities',
            'payload.waypoints',
            'payload.pickup',
            'payload.dropoff',
            'trackingNumber',
            'trackingStatuses',
            'driverAssigned'
        );
});
