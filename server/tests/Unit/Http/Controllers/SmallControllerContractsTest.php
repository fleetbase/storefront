<?php

use Fleetbase\Models\Company;
use Fleetbase\Models\Invite;
use Fleetbase\Models\User;
use Fleetbase\Storefront\Http\Controllers\ActionController;
use Fleetbase\Storefront\Http\Controllers\MetricsController;
use Fleetbase\Storefront\Http\Controllers\NetworkController;
use Fleetbase\Storefront\Http\Controllers\v1\CatalogController;
use Fleetbase\Storefront\Http\Controllers\v1\FoodTruckController;
use Fleetbase\Storefront\Http\Controllers\v1\StoreController;
use Fleetbase\Storefront\Http\Requests\AddStoreToNetworkCategory;
use Fleetbase\Storefront\Http\Requests\NetworkActionRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

test('metrics controller returns the requested company metrics', function () {
    $company = new Company();
    $company->forceFill(['uuid' => 'company_uuid']);
    $user = new class($company) {
        public function __construct(public Company $company)
        {
        }
    };
    $request = Request::create('/metrics', 'GET', [
        'start'    => '2026-07-01',
        'end'      => '2026-07-31',
        'discover' => ['unknown_metric'],
    ]);
    $request->setUserResolver(fn () => $user);

    $response = (new MetricsController())->all($request);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([]);
});

test('metrics controller converts metric discovery failures into API errors', function () {
    Model::getConnectionResolver()->connection('mysql')->getSchemaBuilder()->dropIfExists('products');
    $company = new Company();
    $company->forceFill(['uuid' => 'company_uuid']);
    $user = new class($company) {
        public function __construct(public Company $company)
        {
        }
    };
    $request = Request::create('/metrics', 'GET', [
        'discover' => ['totalProducts'],
    ]);
    $request->setUserResolver(fn () => $user);

    $response = (new MetricsController())->all($request);

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true))->toHaveKey('error');
});

test('action controller returns stable default metrics without an active store', function () {
    $response = (new ActionController())->getMetrics(Request::create('/metrics'));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'orders_count'    => 0,
            'customers_count' => 0,
            'stores_count'    => 0,
            'earnings_sum'    => 0,
        ]);
});

test('action controller validates promotional notification requests before querying customers', function () {
    $controller = new ActionController();

    $missingContent = $controller->sendPushNotification(Request::create('/notifications', 'POST'));
    $missingTargets = $controller->sendPushNotification(Request::create('/notifications', 'POST', [
        'title' => 'New menu',
        'body'  => 'Try our latest items.',
    ]));

    expect($missingContent->getStatusCode())->toBe(400)
        ->and($missingContent->getData(true))->toBe(['error' => 'Title and body are required'])
        ->and($missingTargets->getStatusCode())->toBe(400)
        ->and($missingTargets->getData(true))->toBe(['error' => 'At least one customer must be selected']);
});

test('action controller counts company stores and rejects an unknown notification store', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('stores');
    $schema->create('stores', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id');
        $table->string('company_uuid');
        $table->string('currency')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $connection->table('stores')->insert([
        ['public_id' => 'store_one', 'company_uuid' => 'company_uuid'],
        ['public_id' => 'store_two', 'company_uuid' => 'company_uuid'],
        ['public_id' => 'store_other', 'company_uuid' => 'other_company'],
    ]);
    session(['company' => 'company_uuid']);

    $controller     = new ActionController();
    $count          = $controller->getStoreCount(Request::create('/stores/count'));
    $missingMetrics = $controller->getMetrics(Request::create('/metrics', 'GET', [
        'store' => 'missing_store_uuid',
    ]));
    $notFound   = $controller->sendPushNotification(Request::create('/notifications', 'POST', [
        'title'      => 'New menu',
        'body'       => 'Try our latest items.',
        'select_all' => true,
        'store'      => 'missing_store',
    ]));

    expect($count->getData(true))->toBe(['storeCount' => 2])
        ->and($missingMetrics->getData(true))->toBe([
            'orders_count'    => 0,
            'customers_count' => 0,
            'stores_count'    => 0,
            'earnings_sum'    => 0,
        ])
        ->and($notFound->getStatusCode())->toBe(404)
        ->and($notFound->getData(true))->toBe(['error' => 'Store not found']);
});

test('action controller reports scoped order customer store and earnings metrics', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    foreach (['transactions', 'orders', 'contacts', 'stores'] as $table) {
        $schema->dropIfExists($table);
    }
    $schema->create('stores', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('currency')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('contacts', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('type')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('orders', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('customer_uuid')->nullable();
        $table->string('type')->nullable();
        $table->string('status')->nullable();
        $table->string('transaction_uuid')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('transactions', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->unsignedBigInteger('amount')->nullable();
        $table->string('currency')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $connection->table('stores')->insert([
        ['uuid' => 'store_uuid', 'public_id' => 'store_public', 'company_uuid' => 'company_uuid', 'currency' => 'USD'],
        ['uuid' => 'second_store_uuid', 'public_id' => 'store_second', 'company_uuid' => 'company_uuid', 'currency' => 'USD'],
    ]);
    $connection->table('contacts')->insert([
        'uuid'         => 'customer_uuid',
        'company_uuid' => 'company_uuid',
        'type'         => 'customer',
    ]);
    $connection->table('orders')->insert([
        [
            'uuid'             => 'order_paid',
            'company_uuid'     => 'company_uuid',
            'customer_uuid'    => 'customer_uuid',
            'transaction_uuid' => null,
            'type'             => 'storefront',
            'status'           => 'completed',
            'meta'             => json_encode(['storefront_id' => 'store_public', 'total' => 1250]),
            'created_at'       => '2026-07-15 12:00:00',
            'updated_at'       => '2026-07-15 12:00:00',
        ],
        [
            'uuid'             => 'order_canceled',
            'company_uuid'     => 'company_uuid',
            'customer_uuid'    => 'customer_uuid',
            'transaction_uuid' => null,
            'type'             => 'storefront',
            'status'           => 'canceled',
            'meta'             => json_encode(['storefront_id' => 'store_public', 'total' => 500]),
            'created_at'       => '2026-07-16 12:00:00',
            'updated_at'       => '2026-07-16 12:00:00',
        ],
        [
            'uuid'             => 'order_late_end_date',
            'company_uuid'     => 'company_uuid',
            'customer_uuid'    => 'customer_uuid',
            'type'             => 'storefront',
            'status'           => 'dispatched',
            'transaction_uuid' => 'transaction_late',
            'meta'             => json_encode(['storefront_id' => 'store_public']),
            'created_at'       => '2026-07-31 23:59:59',
            'updated_at'       => '2026-07-31 23:59:59',
        ],
    ]);
    $connection->table('transactions')->insert([
        'uuid'     => 'transaction_late',
        'amount'   => 750,
        'currency' => 'USD',
    ]);
    session(['company' => 'company_uuid']);

    $response = (new ActionController())->getMetrics(Request::create('/metrics', 'GET', [
        'store' => 'store_uuid',
        'start' => '2026-07-01',
        'end'   => '2026-07-31',
    ]));

    expect($response->getData(true))->toBe([
        'orders_count'    => 2,
        'customers_count' => 1,
        'stores_count'    => 2,
        'earnings_sum'    => 2000,
        'currency'        => 'USD',
    ]);
});

test('action controller sends selected and all-customer promotions while isolating delivery failures', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('contacts');
    $schema->dropIfExists('stores');
    $schema->create('stores', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('contacts', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('type')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $connection->table('stores')->insert([
        'uuid'         => 'store_uuid',
        'public_id'    => 'store_public',
        'company_uuid' => 'company_uuid',
        'name'         => 'Test Store',
    ]);
    $connection->table('contacts')->insert([
        ['uuid' => 'customer_selected', 'company_uuid' => 'company_uuid', 'type' => 'customer'],
        ['uuid' => 'other_company_customer', 'company_uuid' => 'other_company', 'type' => 'customer'],
    ]);
    session(['company' => 'company_uuid']);
    $controller = new ActionController();
    $selected   = $controller->sendPushNotification(Request::create('/notifications', 'POST', [
        'title'     => 'New menu',
        'body'      => 'Try our latest items.',
        'customers' => ['customer_selected'],
        'store'     => 'store_public',
    ]));

    app()->instance(
        Illuminate\Contracts\Notifications\Dispatcher::class,
        new class implements Illuminate\Contracts\Notifications\Dispatcher {
            public function send($notifiables, $notification)
            {
            }

            public function sendNow($notifiables, $notification, ?array $channels = null)
            {
            }
        }
    );
    $all = $controller->sendPushNotification(Request::create('/notifications', 'POST', [
        'title'      => 'New menu',
        'body'       => 'Try our latest items.',
        'select_all' => true,
        'store'      => 'store_public',
    ]));
    app()->offsetUnset(Illuminate\Contracts\Notifications\Dispatcher::class);

    expect($selected->getData(true))->toBe([
        'status'     => 'OK',
        'sent_count' => 0,
        'total'      => 1,
    ])->and($all->getData(true))->toBe([
        'status'     => 'OK',
        'sent_count' => 1,
        'total'      => 1,
    ]);
});

test('public catalog and food truck queries are empty without a storefront store context', function () {
    session(['storefront_store' => null]);

    $catalogs   = (new CatalogController())->query(Request::create('/catalogs'));
    $foodTrucks = (new FoodTruckController())->query(Request::create('/food-trucks'));

    expect($catalogs)->toBe([])
        ->and($foodTrucks->resource)->toBeEmpty();
});

test('public catalog query scopes persisted catalogs to the active store with pagination controls', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('catalogs');
    $schema->create('catalogs', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id')->nullable();
        $table->string('store_uuid')->nullable();
        $table->string('name');
        $table->timestamps();
        $table->softDeletes();
    });
    $connection->table('catalogs')->insert([
        ['uuid' => 'catalog_first', 'store_uuid' => 'store_uuid', 'name' => 'Breakfast'],
        ['uuid' => 'catalog_second', 'store_uuid' => 'store_uuid', 'name' => 'Lunch'],
        ['uuid' => 'catalog_other', 'store_uuid' => 'other_store', 'name' => 'Other'],
    ]);
    session(['storefront_store' => 'store_uuid']);

    $request = Request::create('/catalogs', 'GET', [
        'limit'  => 1,
        'offset' => 1,
    ]);
    $request->setLaravelSession(request()->session());
    $results = (new CatalogController())->query($request);

    expect($results)->toHaveCount(1)
        ->and($results->first()->uuid)->toBe('catalog_second');
});

test('public food truck lookup reports an unknown resource', function () {
    foreach (['storefront', 'mysql'] as $connectionName) {
        $schema = Model::getConnectionResolver()->connection($connectionName)->getSchemaBuilder();
        $schema->dropIfExists('food_trucks');
        $schema->create('food_trucks', function ($table) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('public_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
    }
    session(['company' => null]);

    $response = (new FoodTruckController())->find('missing_food_truck');

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true))->toBe(['error' => 'Food Truck resource not found.']);
});

test('public food truck query and lookup return records for the active store', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('food_trucks');
    $schema->create('food_trucks', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id')->nullable();
        $table->string('store_uuid');
        $table->string('vehicle_uuid')->nullable();
        $table->string('status')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $connection->table('food_trucks')->insert([
        ['uuid' => 'truck_first', 'public_id' => 'food_truck_first', 'store_uuid' => 'store_uuid', 'status' => 'online'],
        ['uuid' => 'truck_second', 'public_id' => 'food_truck_second', 'store_uuid' => 'store_uuid', 'status' => 'offline'],
        ['uuid' => 'truck_other', 'public_id' => 'food_truck_other', 'store_uuid' => 'other_store', 'status' => 'online'],
    ]);
    session(['storefront_store' => 'store_uuid']);
    $request = Request::create('/food-trucks', 'GET', ['limit' => 1, 'offset' => 1]);
    $request->setLaravelSession(request()->session());
    $controller = new FoodTruckController();

    $results = $controller->query($request);
    $found   = $controller->find('food_truck_first');

    expect($results->resource)->toHaveCount(1)
        ->and($results->resource->first()->uuid)->toBe('truck_second')
        ->and($found->resource->uuid)->toBe('truck_first');
});

test('store controller reports missing storefront context and lookup identifiers', function () {
    session(['storefront_key' => null]);
    $controller = new StoreController();

    $about  = $controller->about();
    $lookup = $controller->lookup(null);

    expect($about->getStatusCode())->toBe(400)
        ->and($about->getData(true))->toBe(['error' => 'Unable to find store!'])
        ->and($lookup->getStatusCode())->toBe(400)
        ->and($lookup->getData(true))->toBe(['error' => 'No ID provided for lookup.']);
});

test('store controller returns store and network resources for active contexts and company lookups', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('stores');
    $schema->dropIfExists('networks');
    foreach (['stores', 'networks'] as $tableName) {
        $schema->create($tableName, function ($table) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('public_id')->nullable();
            $table->string('company_uuid')->nullable();
            $table->string('key')->nullable();
            $table->string('name')->nullable();
            $table->string('currency')->nullable();
            $table->text('options')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
    $connection->table('stores')->insert([
        'uuid'         => 'store_uuid',
        'public_id'    => 'store_abcdefgh',
        'company_uuid' => 'company_uuid',
        'key'          => 'store_key',
        'name'         => 'Test store',
        'currency'     => 'USD',
        'options'      => '{}',
    ]);
    $connection->table('networks')->insert([
        'uuid'         => 'network_uuid',
        'public_id'    => 'network_abcdefgh',
        'company_uuid' => 'company_uuid',
        'key'          => 'network_key',
        'name'         => 'Test network',
        'currency'     => 'USD',
        'options'      => '{}',
    ]);
    session([
        'company'          => 'company_uuid',
        'storefront_key'   => 'store_key',
        'storefront_store' => 'store_uuid',
    ]);
    $controller = new StoreController();

    $storeAbout    = $controller->about();
    $storeLookup   = $controller->lookup('store_abcdefgh');
    $networkLookup = $controller->lookup('network_abcdefgh');
    session([
        'storefront_key'     => 'network_key',
        'storefront_store'   => null,
        'storefront_network' => 'network_uuid',
    ]);
    $networkAbout = $controller->about();

    expect($storeAbout)->toBeInstanceOf(Fleetbase\Storefront\Http\Resources\Store::class)
        ->and($storeAbout->resource->uuid)->toBe('store_uuid')
        ->and($storeLookup)->toBeInstanceOf(Fleetbase\Storefront\Http\Resources\Store::class)
        ->and($storeLookup->resource->public_id)->toBe('store_abcdefgh')
        ->and($networkLookup)->toBeInstanceOf(Fleetbase\Storefront\Http\Resources\Network::class)
        ->and($networkLookup->resource->public_id)->toBe('network_abcdefgh')
        ->and($networkAbout)->toBeInstanceOf(Fleetbase\Storefront\Http\Resources\Network::class)
        ->and($networkAbout->resource->uuid)->toBe('network_uuid');
});

test('store controller returns a stable error when no store or network matches lookup', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('stores');
    $schema->dropIfExists('networks');
    foreach (['stores', 'networks'] as $tableName) {
        $schema->create($tableName, function ($table) {
            $table->string('uuid')->primary();
            $table->string('public_id');
            $table->string('company_uuid');
            $table->timestamp('deleted_at')->nullable();
        });
    }
    session(['company' => 'company_uuid']);

    $response = (new StoreController())->lookup('missing_public_id');

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true))->toBe([
            'error' => 'Unable to find store or network for ID provided.',
        ]);
});

test('store controller rejects location access for networks without an explicit store', function () {
    session(['storefront_network' => 'network_uuid']);
    $request = Request::create('/v1/storefront/locations');

    $locations = (new StoreController())->locations($request);
    $location  = (new StoreController())->location('location_public', $request);

    expect($locations->getStatusCode())->toBe(400)
        ->and($locations->getData(true))->toBe(['error' => 'Networks cannot have locations!'])
        ->and($location->getStatusCode())->toBe(400)
        ->and($location->getData(true))->toBe(['error' => 'Networks cannot have locations!']);
});

test('network storefront lookup and location access are limited to member stores including cross-company invitees', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    foreach (['store_hours', 'places', 'store_locations', 'network_stores', 'networks', 'stores'] as $table) {
        $schema->dropIfExists($table);
    }
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
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('network_stores', function ($table) {
        $table->increments('id');
        $table->string('network_uuid')->nullable();
        $table->string('store_uuid')->nullable();
        $table->string('category_uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('places', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('store_locations', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('store_uuid')->nullable();
        $table->string('place_uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('store_hours', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('store_location_uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $connection->table('networks')->insert([
        'uuid'         => 'network_uuid',
        'public_id'    => 'network_public',
        'company_uuid' => 'network_company',
    ]);
    $connection->table('stores')->insert([
        [
            'uuid'         => 'member_store_uuid',
            'public_id'    => 'store_member',
            'company_uuid' => 'invited_company',
        ],
        [
            'uuid'         => 'foreign_store_uuid',
            'public_id'    => 'store_foreign',
            'company_uuid' => 'network_company',
        ],
    ]);
    $connection->table('network_stores')->insert([
        'network_uuid' => 'network_uuid',
        'store_uuid'   => 'member_store_uuid',
    ]);
    $connection->table('places')->insert([
        ['uuid' => 'member_place_uuid', 'public_id' => 'place_member'],
        ['uuid' => 'foreign_place_uuid', 'public_id' => 'place_foreign'],
    ]);
    $connection->table('store_locations')->insert([
        [
            'uuid'       => 'member_location_uuid',
            'public_id'  => 'location_member',
            'store_uuid' => 'member_store_uuid',
            'place_uuid' => 'member_place_uuid',
        ],
        [
            'uuid'       => 'foreign_location_uuid',
            'public_id'  => 'location_foreign',
            'store_uuid' => 'foreign_store_uuid',
            'place_uuid' => 'foreign_place_uuid',
        ],
    ]);
    session([
        'company'            => 'network_company',
        'storefront_store'   => null,
        'storefront_network' => 'network_uuid',
    ]);
    $controller = new StoreController();

    $memberLookup     = $controller->lookup('store_member');
    $foreignLookup    = $controller->lookup('store_foreign');
    $memberLocations  = $controller->locations(Request::create('/locations', 'GET', ['store' => 'store_member']));
    $foreignLocations = $controller->locations(Request::create('/locations', 'GET', ['store' => 'store_foreign']));
    $foreignLocation  = $controller->location('location_foreign', Request::create('/locations/location_foreign', 'GET', ['store' => 'store_foreign']));

    expect($memberLookup)->toBeInstanceOf(Fleetbase\Storefront\Http\Resources\Store::class)
        ->and($foreignLookup->getStatusCode())->toBe(400)
        ->and($memberLocations->resource)->toHaveCount(1)
        ->and($foreignLocations->resource)->toBeEmpty()
        ->and($foreignLocation->getStatusCode())->toBe(404);
});

test('store controller answers 404 for an unknown store or location instead of throwing', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    foreach (['store_hours', 'store_locations', 'places', 'stores'] as $table) {
        $schema->dropIfExists($table);
    }
    $schema->create('stores', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id');
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('store_locations', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id');
        $table->string('store_uuid');
        $table->timestamp('deleted_at')->nullable();
    });
    $connection->table('stores')->insert(['uuid' => 'store_uuid', 'public_id' => 'store_public']);

    session(['storefront_network' => null, 'storefront_store' => 'store_public']);
    $request = Request::create('/v1/storefront/locations');

    // Both lookups were unguarded, so an id that resolved nothing reached the resource
    // and threw inside it — the endpoint answered 500 with an HTML stack trace for any
    // stale or mistyped location id.
    $missingLocation = (new StoreController())->location('location_does_not_exist', $request);

    session(['storefront_store' => 'store_does_not_exist']);
    $missingStore = (new StoreController())->location('location_public', $request);

    expect($missingLocation->getStatusCode())->toBe(404)
        ->and($missingLocation->getData(true))->toBe(['error' => 'Store location not found!'])
        ->and($missingStore->getStatusCode())->toBe(404)
        ->and($missingStore->getData(true))->toBe(['error' => 'Unable to find store!']);
});

test('store controller resolves active and explicitly selected store locations with their relations', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    foreach (['store_hours', 'store_locations', 'places', 'stores'] as $table) {
        $schema->dropIfExists($table);
    }
    $schema->create('stores', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('places', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('address')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('store_locations', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('store_uuid')->nullable();
        $table->string('place_uuid')->nullable();
        $table->string('name')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('store_hours', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('store_location_uuid')->nullable();
        $table->integer('day_of_week')->nullable();
        $table->string('start')->nullable();
        $table->string('end')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $connection->table('stores')->insert([
        'uuid'      => 'store_uuid',
        'public_id' => 'store_abcdefgh',
    ]);
    $connection->table('places')->insert([
        'uuid'      => 'place_uuid',
        'public_id' => 'place_abcdefgh',
        'address'   => '1 Fleet Street',
    ]);
    $connection->table('store_locations')->insert([
        'uuid'       => 'location_uuid',
        'public_id'  => 'store_location_abcdefgh',
        'store_uuid' => 'store_uuid',
        'place_uuid' => 'place_uuid',
        'name'       => 'Main location',
    ]);
    $connection->table('store_hours')->insert([
        'uuid'                => 'hours_uuid',
        'store_location_uuid' => 'location_uuid',
        'day_of_week'         => 1,
        'start'               => '09:00',
        'end'                 => '17:00',
    ]);
    session([
        'storefront_store'   => 'store_uuid',
        'storefront_network' => null,
    ]);
    $controller = new StoreController();

    $activeLocations   = $controller->locations(Request::create('/locations'));
    $selectedLocations = $controller->locations(Request::create('/locations', 'GET', [
        'store' => 'store_abcdefgh',
    ]));
    $location = $controller->location(
        'store_location_abcdefgh',
        Request::create('/locations/store_location_abcdefgh')
    );

    expect($activeLocations->resource)->toHaveCount(1)
        ->and($activeLocations->resource->first()->uuid)->toBe('location_uuid')
        ->and($activeLocations->resource->first()->place->uuid)->toBe('place_uuid')
        ->and($activeLocations->resource->first()->hours)->toHaveCount(1)
        ->and($selectedLocations->resource)->toHaveCount(1)
        ->and($selectedLocations->resource->first()->uuid)->toBe('location_uuid')
        ->and($location->resource->uuid)->toBe('location_uuid');
});

test('store controller searches direct and category products across store and network contexts', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    foreach (['network_stores', 'networks', 'categories', 'products', 'stores'] as $table) {
        $schema->dropIfExists($table);
    }
    $schema->create('stores', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('networks', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('network_stores', function ($table) {
        $table->increments('id');
        $table->string('network_uuid')->nullable();
        $table->string('store_uuid')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('categories', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->text('description')->nullable();
        $table->text('tags')->nullable();
        $table->string('for')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('products', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('store_uuid')->nullable();
        $table->string('category_uuid')->nullable();
        $table->string('name')->nullable();
        $table->text('description')->nullable();
        $table->text('tags')->nullable();
        $table->string('sku')->nullable();
        $table->boolean('is_available')->default(true);
        $table->string('status')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $connection->table('stores')->insert([
        'uuid'      => 'store_uuid',
        'public_id' => 'store_abcdefgh',
    ]);
    $connection->table('networks')->insert(['uuid' => 'network_uuid']);
    $connection->table('network_stores')->insert([
        'network_uuid' => 'network_uuid',
        'store_uuid'   => 'store_uuid',
    ]);
    $connection->table('categories')->insert([
        'uuid'         => 'category_uuid',
        'public_id'    => 'category_abcdefgh',
        'company_uuid' => 'company_uuid',
        'name'         => 'Coffee',
        'description'  => 'Coffee products',
        'for'          => 'storefront_product',
    ]);
    $connection->table('products')->insert([
        [
            'uuid'          => 'direct_product_uuid',
            'public_id'     => 'product_abcdefgh',
            'store_uuid'    => 'store_uuid',
            'category_uuid' => null,
            'name'          => 'Coffee beans',
            'description'   => 'Fresh roast',
            'sku'           => 'COFFEE-1',
            'is_available'  => true,
            'status'        => 'published',
        ],
        [
            'uuid'          => 'category_product_uuid',
            'public_id'     => 'product_ijklmnop',
            'store_uuid'    => 'store_uuid',
            'category_uuid' => 'category_uuid',
            'name'          => 'Tea',
            'description'   => 'Category-associated product',
            'sku'           => 'TEA-1',
            'is_available'  => true,
            'status'        => 'published',
        ],
    ]);
    session([
        'company'            => 'company_uuid',
        'storefront_key'     => 'store_key',
        'storefront_store'   => 'store_uuid',
        'storefront_network' => null,
    ]);
    $controller = new StoreController();

    $storeResults = $controller->search(Request::create('/search', 'GET', [
        'query' => 'Coffee',
        'limit' => 10,
    ]));
    session([
        'storefront_key'     => 'network_key',
        'storefront_store'   => null,
        'storefront_network' => 'network_uuid',
    ]);
    $networkResults = $controller->search(Request::create('/search', 'GET', [
        'query'      => 'Coffee',
        'store'      => 'store_abcdefgh',
        'limit'      => 10,
        'with_store' => true,
    ]));

    expect($storeResults->resource->pluck('uuid')->all())->toBe([
        'direct_product_uuid',
        'category_product_uuid',
    ])->and($networkResults->resource->pluck('uuid')->all())->toBe([
        'direct_product_uuid',
    ])->and($networkResults->resource->first()->relationLoaded('store'))->toBeTrue();
});

test('network controller resolves public IDs and invitation codes to their network', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('invites');
    $schema->dropIfExists('network_stores');
    $schema->dropIfExists('stores');
    $schema->dropIfExists('networks');
    $schema->create('networks', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('name')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('invites', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('uri')->nullable();
        $table->string('reason')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('stores', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('network_stores', function ($table) {
        $table->increments('id');
        $table->string('network_uuid')->nullable();
        $table->string('store_uuid')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $connection->table('networks')->insert([
        'uuid'      => 'network_uuid',
        'public_id' => 'network_abcdefgh',
        'name'      => 'Delivery network',
    ]);
    $connection->table('invites')->insert([
        'uuid'         => 'invite_uuid',
        'public_id'    => 'invite_abcdefgh',
        'uri'          => 'join-code',
        'reason'       => 'join_storefront_network',
        'subject_uuid' => 'network_uuid',
        'subject_type' => Fleetbase\Storefront\Models\Network::class,
    ]);
    $environmentRepository = new class {
        public function get(string $key): ?string
        {
            return $key === 'DB_CONNECTION' ? 'mysql' : null;
        }
    };
    $repository = new ReflectionProperty(Illuminate\Support\Env::class, 'repository');
    $repository->setValue(null, $environmentRepository);
    $controller = new NetworkController();

    $byPublicId     = $controller->findNetwork(' network_abcdefgh ');
    $byInvite       = $controller->findNetwork('join-code');
    $missing        = $controller->findNetwork('unknown-code');
    $publicNetwork  = $byPublicId->getOriginalContent();
    $invitedNetwork = $byInvite->getOriginalContent();

    expect($publicNetwork)->toBeInstanceOf(Fleetbase\Storefront\Models\Network::class)
        ->and($publicNetwork->uuid)->toBe('network_uuid')
        ->and($publicNetwork->public_id)->toBe('network_abcdefgh')
        ->and($publicNetwork->name)->toBe('Delivery network')
        ->and($invitedNetwork)->toBeInstanceOf(Fleetbase\Storefront\Models\Network::class)
        ->and($invitedNetwork->uuid)->toBe('network_uuid')
        ->and($missing->getData(true))->toBe([]);
});

test('network controller persists and sends an email invitation with its request actor', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('invites');
    $schema->dropIfExists('networks');
    $schema->create('networks', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('name')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('invites', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('created_by_uuid')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('protocol')->nullable();
        $table->text('recipients')->nullable();
        $table->string('reason')->nullable();
        $table->string('uri')->nullable();
        $table->string('code')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $connection->table('networks')->insert([
        'id'        => 1,
        'uuid'      => 'network_uuid',
        'public_id' => 'network_abcdefgh',
        'name'      => 'Delivery network',
    ]);
    session(['company' => 'company_uuid', 'user' => 'user_uuid']);
    $sender = new User();
    $sender->forceFill([
        'uuid'      => 'user_uuid',
        'public_id' => 'user_abcdefgh',
        'name'      => 'Network owner',
        'email'     => 'owner@example.test',
    ]);
    $mail = new class {
        public array $sent = [];

        public function send($mailable): void
        {
            $this->sent[] = $mailable;
        }
    };
    Mail::swap($mail);
    $environmentRepository = new class {
        public function get(string $key): ?string
        {
            return $key === 'DB_CONNECTION' ? 'mysql' : null;
        }
    };
    $repository = new ReflectionProperty(Illuminate\Support\Env::class, 'repository');
    $repository->setValue(null, $environmentRepository);
    $request = NetworkActionRequest::create('/network/invites', 'POST', [
        'recipients' => ['store@example.test'],
    ]);
    $request->setUserResolver(fn () => $sender);

    $response = (new NetworkController())->sendInvites('network_uuid', $request);
    $invite   = Invite::query()->firstOrFail();

    expect($response->getData(true))->toBe(['status' => 'ok'])
        ->and($invite->company_uuid)->toBe('company_uuid')
        ->and($invite->created_by_uuid)->toBe('user_uuid')
        ->and($invite->subject_uuid)->toBe('network_uuid')
        ->and($invite->protocol)->toBe('email')
        ->and($invite->recipients)->toBe(['store@example.test'])
        ->and($mail->sent)->toHaveCount(1)
        ->and($mail->sent[0])->toBeInstanceOf(Fleetbase\Storefront\Mail\StorefrontNetworkInvite::class)
        ->and($mail->sent[0]->network->uuid)->toBe('network_uuid')
        ->and($mail->sent[0]->sender)->toBe($sender);
});

test('network controller adds removes and categorizes store assignments', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('networks');
    $schema->dropIfExists('network_stores');
    $schema->dropIfExists('categories');
    $schema->create('networks', function ($table) {
        $table->string('uuid')->primary();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('network_stores', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('network_uuid');
        $table->string('store_uuid');
        $table->string('category_uuid')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('categories', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('owner_uuid');
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $connection->table('networks')->insert(['uuid' => 'network_uuid']);
    $connection->table('categories')->insert([
        'uuid'       => 'category_uuid',
        'owner_uuid' => 'network_uuid',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $controller = new NetworkController();
    $addRequest = NetworkActionRequest::create('/network', 'POST', [
        'stores' => ['store_a', 'store_b'],
    ]);

    expect($controller->addStores('network_uuid', $addRequest)->getData(true))->toBe(['status' => 'ok'])
        ->and($connection->table('network_stores')->whereNull('deleted_at')->count())->toBe(2);

    $categoryRequest = AddStoreToNetworkCategory::create('/network', 'POST', [
        'store'    => 'store_a',
        'category' => 'category_uuid',
    ]);
    expect($controller->addStoreToCategory('network_uuid', $categoryRequest)->getData(true))->toBe(['status' => 'ok'])
        ->and($connection->table('network_stores')->where('store_uuid', 'store_a')->value('category_uuid'))->toBe('category_uuid');

    $removeCategoryRequest = NetworkActionRequest::create('/network', 'POST', ['store' => 'store_a']);
    expect($controller->removeStoreCategory('network_uuid', $removeCategoryRequest)->getData(true))->toBe(['status' => 'ok'])
        ->and($connection->table('network_stores')->where('store_uuid', 'store_a')->value('category_uuid'))->toBeNull();

    $deleteCategoryRequest = NetworkActionRequest::create('/network', 'POST', ['category' => 'category_uuid']);
    expect($controller->deleteCategory('network_uuid', $deleteCategoryRequest)->getData(true))->toBe(['status' => 'ok'])
        ->and($connection->table('categories')->where('uuid', 'category_uuid')->value('deleted_at'))->not->toBeNull();

    $removeRequest = NetworkActionRequest::create('/network', 'POST', ['stores' => ['store_a']]);
    expect($controller->removeStores('network_uuid', $removeRequest)->getData(true))->toBe(['status' => 'ok'])
        ->and($connection->table('network_stores')->where('store_uuid', 'store_a')->value('deleted_at'))->not->toBeNull();
});

test('network controller add stores removes requested stale assignments in the same operation', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('networks');
    $schema->dropIfExists('network_stores');
    $schema->create('networks', function ($table) {
        $table->string('uuid')->primary();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('network_stores', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('network_uuid');
        $table->string('store_uuid');
        $table->string('category_uuid')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $connection->table('networks')->insert(['uuid' => 'network_uuid']);
    $connection->table('network_stores')->insert([
        'network_uuid' => 'network_uuid',
        'store_uuid'   => 'store_old',
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    $request = NetworkActionRequest::create('/network', 'POST', [
        'stores' => ['store_new'],
        'remove' => ['store_old'],
    ]);
    $response = (new NetworkController())->addStores('network_uuid', $request);

    expect($response->getData(true))->toBe(['status' => 'ok'])
        ->and($connection->table('network_stores')->where('store_uuid', 'store_new')->whereNull('deleted_at')->exists())->toBeTrue()
        ->and($connection->table('network_stores')->where('store_uuid', 'store_old')->whereNotNull('deleted_at')->exists())->toBeTrue();
});
