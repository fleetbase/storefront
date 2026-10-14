<?php

use Fleetbase\Storefront\Http\Controllers\v1\FoodTruckController;
use Fleetbase\Storefront\Models\FoodTruck;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Support\ApiModelCache;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * A connection over the suite's SQLite database that runs the MySQL-only expressions of the
 * truck's day stats as their SQLite equivalents (whole minutes, like TIMESTAMPDIFF), so the
 * query runs against real rows. It keeps the statements it ran.
 */
function foodTruckCoverageConnection(): SQLiteConnection
{
    $sqlite = Model::getConnectionResolver()->connection('mysql');

    return new class($sqlite->getPdo(), ':memory:', '', ['driver' => 'sqlite', 'name' => 'mysql']) extends SQLiteConnection {
        public array $statements = [];

        public function select($query, $bindings = [], $useReadPdo = true)
        {
            $this->statements[] = [$query, $bindings];
            $query              = str_replace(
                ["JSON_UNQUOTE(JSON_EXTRACT(meta, '$.total'))", 'TIMESTAMPDIFF(MINUTE, created_at, COALESCE(dispatched_at, started_at))'],
                ["json_extract(meta, '$.total')", "(strftime('%s', COALESCE(dispatched_at, started_at)) - strftime('%s', created_at)) / 60"],
                $query
            );

            return parent::select($query, $bindings, $useReadPdo);
        }
    };
}

/**
 * Run the callback with every model on the given connection.
 */
function foodTruckCoverageOnConnection(SQLiteConnection $connection, Closure $callback): mixed
{
    $original = Model::getConnectionResolver();
    Model::setConnectionResolver(new class($original, $connection) implements ConnectionResolverInterface {
        public function __construct(private ConnectionResolverInterface $original, private SQLiteConnection $fake)
        {
        }

        public function connection($name = null)
        {
            return in_array($name, [null, 'mysql'], true) ? $this->fake : $this->original->connection($name);
        }

        public function getDefaultConnection()
        {
            return $this->original->getDefaultConnection();
        }

        public function setDefaultConnection($name)
        {
            $this->original->setDefaultConnection($name);
        }
    });

    try {
        return $callback();
    } finally {
        Model::setConnectionResolver($original);
    }
}

function foodTruckCoverageOrdersSchema(): void
{
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('orders');
    $schema->create('orders', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->text('meta')->nullable();
        $table->timestamp('dispatched_at')->nullable();
        $table->timestamp('started_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    $order = fn (string $uuid, string $truck, string $createdAt, array $extra = [], string $company = 'company_uuid', array $meta = []) => array_merge([
        'uuid'          => $uuid,
        'company_uuid'  => $company,
        'meta'          => json_encode(array_merge(['food_truck_id' => $truck], $meta)),
        'dispatched_at' => null,
        'started_at'    => null,
        'deleted_at'    => null,
        'created_at'    => $createdAt,
        'updated_at'    => $createdAt,
    ], $extra);

    $connection->table('orders')->insert([
        // Today in Ulaanbaatar (UTC+8) runs from 2026-10-14 16:00 to 2026-10-15 15:59:59 UTC
        $order('order_dispatched', 'food_truck_one', '2026-10-14 17:00:00', ['dispatched_at' => '2026-10-14 17:20:00'], meta: ['total' => 12.5]),
        $order('order_started', 'food_truck_one', '2026-10-15 15:00:00', ['started_at' => '2026-10-15 15:11:30'], meta: ['total' => 7.5]),
        $order('order_waiting', 'food_truck_one', '2026-10-14 18:00:00', meta: ['total' => '3']),
        $order('order_yesterday', 'food_truck_one', '2026-10-14 15:30:00', ['dispatched_at' => '2026-10-14 15:31:00'], meta: ['total' => 100]),
        $order('order_other_truck', 'food_truck_two', '2026-10-14 17:00:00', meta: ['total' => 100]),
        $order('order_other_company', 'food_truck_one', '2026-10-14 17:00:00', company: 'other_company', meta: ['total' => 100]),
        $order('order_deleted', 'food_truck_one', '2026-10-14 17:00:00', ['deleted_at' => '2026-10-14 17:05:00'], meta: ['total' => 100]),
        // A truck without an owner counts its day in UTC
        $order('order_ownerless', 'food_truck_ownerless', '2026-10-14 01:00:00', meta: ['total' => 4]),
    ]);
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-14 20:00:00', 'UTC'));
});

afterEach(function () {
    Carbon::setTestNow();
});

test('a store truck\'s day stats count its orders today in the store\'s timezone', function () {
    foodTruckCoverageOrdersSchema();
    $store = (new Store())->forceFill(['uuid' => 'store_uuid', 'timezone' => 'Asia/Ulaanbaatar', 'currency' => 'MNT']);
    $truck = (new FoodTruck())->forceFill(['uuid' => 'truck_uuid', 'public_id' => 'food_truck_one', 'company_uuid' => 'company_uuid', 'store_uuid' => 'store_uuid'])->setRelation('store', $store);

    $connection = foodTruckCoverageConnection();
    $stats      = foodTruckCoverageOnConnection($connection, fn () => $truck->todayStats());

    [$query, $bindings] = $connection->statements[0];
    expect($stats)->toBe(['orders' => 3, 'revenue' => 23.0, 'currency' => 'MNT', 'avg_prep_minutes' => 16])
        ->and($truck->owner())->toBe($store)
        ->and($query)->toContain('TIMESTAMPDIFF(MINUTE, created_at, COALESCE(dispatched_at, started_at))')
        ->and(collect($bindings)->map(fn ($binding) => $binding instanceof DateTimeInterface ? Carbon::instance($binding)->setTimezone('UTC')->toDateTimeString() : $binding)->all())
        ->toBe(['company_uuid', 'food_truck_one', '2026-10-14 16:00:00', '2026-10-15 15:59:59']);
});

test('a network truck\'s day stats use the network\'s clock and currency', function () {
    foodTruckCoverageOrdersSchema();
    $store   = (new Store())->forceFill(['uuid' => 'store_uuid', 'timezone' => 'UTC', 'currency' => 'USD']);
    $network = (new Network())->forceFill(['uuid' => 'network_uuid', 'timezone' => 'Asia/Ulaanbaatar', 'currency' => 'CNY']);
    $truck   = (new FoodTruck())->forceFill(['uuid' => 'truck_uuid', 'public_id' => 'food_truck_one', 'company_uuid' => 'company_uuid', 'store_uuid' => 'store_uuid', 'network_uuid' => 'network_uuid'])
        ->setRelation('store', $store)
        ->setRelation('network', $network);

    $stats = foodTruckCoverageOnConnection(foodTruckCoverageConnection(), fn () => $truck->todayStats());

    expect($truck->owner())->toBe($network)
        ->and($stats)->toBe(['orders' => 3, 'revenue' => 23.0, 'currency' => 'CNY', 'avg_prep_minutes' => 16]);
});

test('a truck without an owner or orders today reports an empty day in UTC', function () {
    foodTruckCoverageOrdersSchema();
    $ownerless = (new FoodTruck())->forceFill(['uuid' => 'truck_ownerless', 'public_id' => 'food_truck_ownerless', 'company_uuid' => 'company_uuid'])->setRelation('store', null);
    $idle      = (new FoodTruck())->forceFill(['uuid' => 'truck_idle', 'public_id' => 'food_truck_idle', 'company_uuid' => 'company_uuid'])->setRelation('store', null);

    $connection                   = foodTruckCoverageConnection();
    [$ownerlessStats, $idleStats] = foodTruckCoverageOnConnection($connection, fn () => [$ownerless->todayStats(), $idle->todayStats()]);

    expect($ownerless->owner())->toBeNull()
        ->and($ownerlessStats)->toBe(['orders' => 1, 'revenue' => 4.0, 'currency' => null, 'avg_prep_minutes' => null])
        ->and($idleStats)->toBe(['orders' => 0, 'revenue' => 0.0, 'currency' => null, 'avg_prep_minutes' => null]);
});

function foodTruckCoverageStorefrontSchema(): void
{
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    foreach (['food_trucks', 'stores', 'networks', 'network_stores', 'catalogs', 'catalog_subjects'] as $table) {
        $schema->dropIfExists($table);
    }
    $schema->create('food_trucks', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id')->nullable();
        $table->string('store_uuid')->nullable();
        $table->string('network_uuid')->nullable();
        $table->string('vehicle_uuid')->nullable();
        $table->string('status')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('stores', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id')->nullable();
        $table->string('name')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('networks', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id')->nullable();
        $table->string('name')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('network_stores', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('network_uuid');
        $table->string('store_uuid');
        $table->string('status')->default('active');
        $table->string('category_uuid')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('catalogs', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('name')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('catalog_subjects', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('catalog_uuid');
        $table->string('subject_uuid');
        $table->string('subject_type');
        $table->timestamps();
        $table->softDeletes();
    });

    $connection->table('networks')->insert(['uuid' => 'network_uuid', 'public_id' => 'network_public', 'name' => 'Night Market']);
    $connection->table('stores')->insert([
        ['uuid' => 'member_store_uuid', 'public_id' => 'store_member', 'name' => 'Member Store'],
        ['uuid' => 'suspended_store_uuid', 'public_id' => 'store_suspended', 'name' => 'Suspended Store'],
        ['uuid' => 'outside_store_uuid', 'public_id' => 'store_outside', 'name' => 'Outside Store'],
    ]);
    $connection->table('network_stores')->insert([
        ['network_uuid' => 'network_uuid', 'store_uuid' => 'member_store_uuid', 'status' => 'active'],
        ['network_uuid' => 'network_uuid', 'store_uuid' => 'suspended_store_uuid', 'status' => 'suspended'],
    ]);
    $connection->table('food_trucks')->insert([
        ['uuid' => 'truck_member', 'public_id' => 'food_truck_member', 'store_uuid' => 'member_store_uuid', 'network_uuid' => null],
        ['uuid' => 'truck_suspended', 'public_id' => 'food_truck_suspended', 'store_uuid' => 'suspended_store_uuid', 'network_uuid' => null],
        ['uuid' => 'truck_network', 'public_id' => 'food_truck_network', 'store_uuid' => null, 'network_uuid' => 'network_uuid'],
        ['uuid' => 'truck_outside', 'public_id' => 'food_truck_outside', 'store_uuid' => 'outside_store_uuid', 'network_uuid' => null],
    ]);

    // The rows were written without model events, so drop any cached truck lists the way a write would.
    ApiModelCache::invalidateModelCache(new FoodTruck());
}

function foodTruckCoverageQueryRequest(): Request
{
    $request = Request::create('/food-trucks');
    $request->setLaravelSession(request()->session());

    return $request;
}

test('a network app lists its active members\' trucks and the trucks the network runs itself', function () {
    foodTruckCoverageStorefrontSchema();
    session(['storefront_store' => null, 'storefront_network' => 'network_uuid']);

    $results = (new FoodTruckController())->query(foodTruckCoverageQueryRequest());

    expect($results->resource->pluck('uuid')->sort()->values()->all())->toBe(['truck_member', 'truck_network'])
        ->and($results->resource->firstWhere('uuid', 'truck_network')->network->name)->toBe('Night Market')
        ->and($results->resource->firstWhere('uuid', 'truck_member')->store->name)->toBe('Member Store')
        ->and(Network::where('uuid', 'network_uuid')->first()->activeStores()->pluck('stores.uuid')->all())->toBe(['member_store_uuid']);
});

test('a network app finds its own and its members\' trucks and not a suspended member\'s or an outsider\'s', function () {
    foodTruckCoverageStorefrontSchema();
    session(['storefront_store' => null, 'storefront_network' => 'network_uuid']);
    $controller = new FoodTruckController();

    $own       = $controller->find('food_truck_network');
    $member    = $controller->find('food_truck_member');
    $suspended = $controller->find('food_truck_suspended');
    $outside   = $controller->find('food_truck_outside');

    expect($own->resource->uuid)->toBe('truck_network')
        ->and($member->resource->uuid)->toBe('truck_member')
        ->and($suspended->getStatusCode())->toBe(404)
        ->and($suspended->getData(true))->toBe(['error' => 'Food Truck resource not found.'])
        ->and($outside->getStatusCode())->toBe(404);
});

test('a store app does not find another store\'s truck or a network\'s truck', function () {
    foodTruckCoverageStorefrontSchema();
    session(['storefront_store' => 'member_store_uuid', 'storefront_network' => null]);
    $controller = new FoodTruckController();

    expect($controller->find('food_truck_member')->resource->uuid)->toBe('truck_member')
        ->and($controller->find('food_truck_outside')->getStatusCode())->toBe(404)
        ->and($controller->find('food_truck_network')->getStatusCode())->toBe(404);
});

test('an app for a network that no longer exists lists only trucks still owned by that network id', function () {
    foodTruckCoverageStorefrontSchema();
    Model::getConnectionResolver()->connection('mysql')->table('networks')->delete();
    ApiModelCache::invalidateModelCache(new Network());
    session(['storefront_store' => null, 'storefront_network' => 'network_uuid']);

    $results = (new FoodTruckController())->query(foodTruckCoverageQueryRequest());

    expect($results->resource->pluck('uuid')->all())->toBe(['truck_network']);
});
