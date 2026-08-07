<?php

use Fleetbase\Storefront\Http\Controllers\SearchController;
use Fleetbase\Support\Auth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

function invokeSearchController(string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod(SearchController::class, $method))
        ->invoke(new SearchController(), ...$arguments);
}

test('search endpoint returns an empty contract before authorization or database work', function ($input) {
    $request  = Request::create('/search', 'GET', $input);
    $response = (new SearchController())->search($request);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['results' => []]);
})->with([
    'no query'          => [[]],
    'blank query'       => [['query' => '   ']],
    'blank short query' => [['q' => "\t"]],
]);

test('search type parsing supports comma lists arrays and safe defaults', function ($input, $expected) {
    $request = Request::create('/search', 'GET', ['types' => $input]);

    expect(invokeSearchController('requestedTypes', $request))->toBe($expected);
})->with([
    'comma list' => [
        'products, orders,invalid',
        ['products', 'orders'],
    ],
    'array' => [
        ['stores', 'gateways'],
        ['stores', 'gateways'],
    ],
    'invalid scalar' => [
        42,
        ['products', 'catalogs', 'customers', 'orders', 'networks', 'stores', 'food-trucks', 'gateways', 'notification-channels'],
    ],
    'empty filtered list' => [
        ['invalid'],
        ['products', 'catalogs', 'customers', 'orders', 'networks', 'stores', 'food-trucks', 'gateways', 'notification-channels'],
    ],
]);

test('search type dispatcher has a safe empty default', function () {
    expect(invokeSearchController('searchType', 'unsupported', 'query', 5, null))
        ->toBeInstanceOf(Illuminate\Support\Collection::class)
        ->toBeEmpty();
});

test('search store resolver ignores requests without storefront scope', function () {
    $request = Request::create('/search', 'GET', ['query' => 'coffee']);

    expect(invokeSearchController('storefront', $request))->toBeNull();
});

test('search skips resource types the current user cannot access', function () {
    Auth::$user        = null;
    Auth::$permissions = [];

    $response = (new SearchController())->search(Request::create('/search', 'GET', [
        'query' => 'restricted',
        'types' => ['products'],
    ]));

    expect($response->getData(true))->toBe(['results' => []]);
});

test('search wildcard builder escapes user-controlled percent and underscore characters', function () {
    $model = new class extends Model {
        protected $table = 'products';
    };
    $builder = $model->newQuery();

    invokeSearchController('whereLike', $builder, ['name', 'sku'], '100%_pure');

    expect($builder->toSql())->toContain('"name" like ?')
        ->and($builder->toSql())->toContain('or "sku" like ?')
        ->and($builder->getBindings())->toBe([
            '%100\\%\\_pure%',
            '%100\\%\\_pure%',
        ]);
});

test('search returns authorized results across every supported storefront resource type', function () {
    $connection  = Model::getConnectionResolver()->connection('mysql');
    $schema      = $connection->getSchemaBuilder();
    $definitions = [
        'products'              => ['public_id', 'uuid', 'name', 'description', 'sku', 'status', 'company_uuid', 'store_uuid'],
        'catalogs'              => ['public_id', 'uuid', 'name', 'description', 'status', 'company_uuid', 'store_uuid'],
        'contacts'              => ['public_id', 'uuid', 'name', 'email', 'phone', 'internal_id', 'company_uuid', 'type'],
        'orders'                => ['public_id', 'uuid', 'internal_id', 'status', 'company_uuid', 'customer_uuid', 'customer_type', 'meta'],
        'networks'              => ['public_id', 'uuid', 'name', 'description', 'email', 'phone', 'website', 'company_uuid'],
        'stores'                => ['public_id', 'uuid', 'name', 'description', 'email', 'phone', 'website', 'company_uuid'],
        'food_trucks'           => ['public_id', 'uuid', 'status', 'company_uuid', 'store_uuid'],
        'gateways'              => ['public_id', 'uuid', 'name', 'description', 'code', 'type', 'company_uuid', 'owner_uuid'],
        'notification_channels' => ['uuid', 'name', 'scheme', 'app_key', 'company_uuid', 'owner_uuid'],
    ];

    foreach ($definitions as $tableName => $columns) {
        $schema->dropIfExists($tableName);
        $schema->create($tableName, function ($table) use ($columns) {
            $table->increments('id');
            foreach ($columns as $column) {
                $table->string($column)->nullable();
            }
            $table->timestamp('deleted_at')->nullable();
        });
    }
    $schema->dropIfExists('network_stores');
    $schema->create('network_stores', function ($table) {
        $table->increments('id');
        $table->string('network_uuid')->nullable();
        $table->string('store_uuid')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    $common = ['company_uuid' => 'company_uuid'];
    $connection->table('products')->insert($common + ['uuid' => 'product_uuid', 'public_id' => 'product_match', 'name' => 'Match product']);
    $connection->table('catalogs')->insert($common + ['uuid' => 'catalog_uuid', 'public_id' => 'catalog_match', 'name' => 'Match catalog']);
    $connection->table('contacts')->insert($common + ['uuid' => 'customer_uuid', 'public_id' => 'contact_match', 'name' => 'Match customer', 'type' => 'customer']);
    $connection->table('orders')->insert($common + [
        'uuid'          => 'order_uuid',
        'public_id'     => 'order_match',
        'status'        => 'created',
        'customer_uuid' => 'customer_uuid',
        'customer_type' => Fleetbase\Storefront\Models\Customer::class,
        'meta'          => json_encode(['storefront_id' => 'store_match']),
    ]);
    $connection->table('networks')->insert($common + ['uuid' => 'network_uuid', 'public_id' => 'network_match', 'name' => 'Match network']);
    $connection->table('stores')->insert($common + ['uuid' => 'store_uuid', 'public_id' => 'store_match', 'name' => 'Match store']);
    $connection->table('food_trucks')->insert($common + ['uuid' => 'truck_uuid', 'public_id' => 'food_truck_match', 'status' => 'active']);
    $connection->table('gateways')->insert($common + ['uuid' => 'gateway_uuid', 'public_id' => 'gateway_match', 'name' => 'Match gateway']);
    $connection->table('notification_channels')->insert($common + ['uuid' => 'channel_match', 'name' => 'Match channel', 'scheme' => 'fcm', 'app_key' => 'match-app']);
    $connection->table('network_stores')->insert([
        'network_uuid' => 'network_uuid',
        'store_uuid'   => 'store_uuid',
    ]);

    session(['company' => 'company_uuid']);
    Auth::$permissions = [
        'storefront see product',
        'storefront see catalog',
        'storefront see customer',
        'storefront see order',
        'storefront see network',
        'storefront see store',
        'storefront see food-truck',
        'storefront see gateway',
        'storefront see notification-channel',
    ];

    $response = (new SearchController())->search(Request::create('/search', 'GET', [
        'query' => 'match',
        'limit' => 24,
    ]));
    $results = $response->getData(true)['results'];

    expect($results)->toHaveCount(9)
        ->and(array_column($results, 'type'))->toBe([
            'Product',
            'Catalog',
            'Customer',
            'Order',
            'Network',
            'Store',
            'Food Truck',
            'Gateway',
            'Notification Channel',
        ]);

    Auth::$user = new class {
        public function isAdmin(): bool
        {
            return true;
        }
    };
    Auth::$permissions = [];
    $adminResponse     = (new SearchController())->search(Request::create('/search', 'GET', [
        'query' => 'match',
        'types' => ['products'],
    ]));
    $scopedResponse = (new SearchController())->search(Request::create('/search', 'GET', [
        'query'      => 'match',
        'types'      => ['customers', 'networks'],
        'storefront' => 'store_match',
    ]));

    expect($adminResponse->getData(true)['results'])->toHaveCount(1)
        ->and(array_column($scopedResponse->getData(true)['results'], 'type'))->toBe([
            'Customer',
            'Network',
        ]);

    Auth::$user        = null;
    Auth::$permissions = [];
});
