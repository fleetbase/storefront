<?php

use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\Storefront\Http\Controllers\CatalogController;
use Fleetbase\Storefront\Http\Resources\Catalog as CatalogResource;
use Fleetbase\Storefront\Models\Catalog;
use Fleetbase\Storefront\Models\CatalogHour;
use Fleetbase\Storefront\Models\CatalogSubject;
use Fleetbase\Storefront\Models\FoodTruck;
use Fleetbase\Storefront\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

const CATALOG_COVERAGE_STORE_A = '10000000-0000-4000-8000-00000000000a';
const CATALOG_COVERAGE_STORE_B = '10000000-0000-4000-8000-00000000000b';
const CATALOG_COVERAGE_TRUCK_X = '20000000-0000-4000-8000-00000000000c';
const CATALOG_COVERAGE_TRUCK_Y = '20000000-0000-4000-8000-00000000000d';

/**
 * Route the global request as a console (`int/`) or public API request, as the resource reads it.
 */
function catalogCoverageRoute(string $uri): Request
{
    $request = request();
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

function catalogCoverageSchema(): void
{
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();

    foreach (['catalogs', 'categories', 'catalog_hours', 'catalog_subjects', 'stores', 'food_trucks'] as $table) {
        $schema->dropIfExists($table);
    }

    $schema->create('catalogs', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('status')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('categories', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('owner_uuid')->nullable();
        $table->integer('order')->default(0);
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('catalog_hours', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('catalog_uuid');
        $table->string('day_of_week')->nullable();
        $table->string('start')->nullable();
        $table->string('end')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('catalog_subjects', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('created_by_uuid')->nullable();
        $table->string('catalog_uuid');
        $table->string('subject_uuid');
        $table->string('subject_type');
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('stores', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id')->nullable();
        $table->string('name')->nullable();
        $table->boolean('online')->default(false);
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('food_trucks', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id')->nullable();
        $table->string('vehicle_uuid')->nullable();
        $table->string('status')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    $connection->table('catalogs')->insert([
        ['uuid' => 'catalog_uuid', 'public_id' => 'catalog_lunch', 'company_uuid' => 'company_uuid', 'name' => 'Lunch', 'status' => 'published'],
        ['uuid' => 'other_catalog_uuid', 'public_id' => 'catalog_other', 'company_uuid' => 'other_company', 'name' => 'Other', 'status' => 'published'],
    ]);
    $connection->table('catalog_hours')->insert(['uuid' => 'hour_uuid', 'catalog_uuid' => 'catalog_uuid', 'day_of_week' => 'monday', 'start' => '11:00', 'end' => '14:00']);
    $connection->table('stores')->insert([
        ['uuid' => CATALOG_COVERAGE_STORE_A, 'public_id' => 'store_a', 'name' => 'Corner Store', 'online' => true],
        ['uuid' => CATALOG_COVERAGE_STORE_B, 'public_id' => 'store_b', 'name' => 'Second Store', 'online' => false],
    ]);
    $connection->table('food_trucks')->insert([
        ['uuid' => CATALOG_COVERAGE_TRUCK_X, 'public_id' => 'food_truck_x', 'status' => 'active'],
        ['uuid' => CATALOG_COVERAGE_TRUCK_Y, 'public_id' => 'food_truck_y', 'status' => 'inactive'],
    ]);
    $connection->table('catalog_subjects')->insert([
        ['company_uuid' => 'company_uuid', 'catalog_uuid' => 'catalog_uuid', 'subject_uuid' => CATALOG_COVERAGE_STORE_A, 'subject_type' => Store::class, 'created_by_uuid' => 'first_user'],
        ['company_uuid' => 'company_uuid', 'catalog_uuid' => 'catalog_uuid', 'subject_uuid' => CATALOG_COVERAGE_TRUCK_X, 'subject_type' => FoodTruck::class, 'created_by_uuid' => 'first_user'],
    ]);
}

afterEach(function () {
    request()->setRouteResolver(fn () => null);
});

test('assigning subjects to a catalog of another company or an unknown catalog is refused', function () {
    catalogCoverageSchema();
    session(['company' => 'company_uuid', 'user' => 'user_uuid']);
    $controller = new CatalogController();

    $other   = $controller->assignSubjects('catalog_other', Request::create('/', 'POST', ['stores' => [CATALOG_COVERAGE_STORE_A]]));
    $unknown = $controller->assignSubjects('catalog_missing', Request::create('/', 'POST'));

    expect($other->getStatusCode())->toBe(404)
        ->and($other->getData(true))->toBe(['error' => 'Catalog not found.'])
        ->and($unknown->getStatusCode())->toBe(404)
        ->and(Model::getConnectionResolver()->connection('mysql')->table('catalog_subjects')->where('catalog_uuid', 'other_catalog_uuid')->count())->toBe(0);
});

test('assigning subjects replaces the catalog\'s stores and trucks and answers with the catalog the console reads', function () {
    catalogCoverageSchema();
    session(['company' => 'company_uuid', 'user' => 'user_uuid']);
    catalogCoverageRoute('int/v1/storefront/catalogs/catalog_lunch/subjects');

    $response = (new CatalogController())->assignSubjects('catalog_lunch', Request::create('/', 'POST', [
        // Ids, objects with a uuid or an id, duplicates and anything that is not a uuid
        'stores'      => [CATALOG_COVERAGE_STORE_A, ['uuid' => CATALOG_COVERAGE_STORE_B], CATALOG_COVERAGE_STORE_A, 'store_a', 42],
        'food_trucks' => [['id' => CATALOG_COVERAGE_TRUCK_Y]],
    ]));

    $rows = Model::getConnectionResolver()->connection('mysql')->table('catalog_subjects')->orderBy('id')->get();
    $live = $rows->whereNull('deleted_at');

    expect($response->getStatusCode())->toBe(200)
        ->and($live->pluck('subject_uuid')->all())->toBe([CATALOG_COVERAGE_STORE_A, CATALOG_COVERAGE_STORE_B, CATALOG_COVERAGE_TRUCK_Y])
        ->and($rows->firstWhere('subject_uuid', CATALOG_COVERAGE_TRUCK_X)->deleted_at)->not->toBeNull()
        // The kept assignment is left as it was; new ones are the catalog company's, by the session user
        ->and($rows->firstWhere('subject_uuid', CATALOG_COVERAGE_STORE_A)->created_by_uuid)->toBe('first_user')
        ->and($rows->firstWhere('subject_uuid', CATALOG_COVERAGE_STORE_B)->created_by_uuid)->toBe('user_uuid')
        ->and($rows->firstWhere('subject_uuid', CATALOG_COVERAGE_STORE_B)->company_uuid)->toBe('company_uuid')
        ->and($rows->firstWhere('subject_uuid', CATALOG_COVERAGE_TRUCK_Y)->subject_type)->toBe(FoodTruck::class);

    $catalog = $response->getData(true)['catalog'];
    expect($catalog['uuid'])->toBe('catalog_uuid')
        ->and($catalog['hours'])->toBe([['id' => 'hour_uuid', 'uuid' => 'hour_uuid', 'catalog_uuid' => 'catalog_uuid', 'day_of_week' => 'monday', 'start' => '11:00', 'end' => '14:00']])
        ->and(collect($catalog['subjects'])->map(fn ($subject) => [$subject['type'], $subject['name']])->all())->toBe([
            ['store', 'Corner Store'],
            ['store', 'Second Store'],
            ['food-truck', 'food_truck_y'],
        ]);
});

test('a catalog record is read with its categories and products, hours and the stores and trucks serving it', function () {
    $builder = (new Catalog())->newQuery();

    (new CatalogController())->onQueryRecord($builder);

    expect(array_keys($builder->getEagerLoads()))->toBe(['categories', 'categories.products', 'hours', 'assignments', 'assignments.subject']);
});

test('assigning no subjects clears the catalog', function () {
    catalogCoverageSchema();
    session(['company' => 'company_uuid', 'user' => 'user_uuid']);
    catalogCoverageRoute('int/v1/storefront/catalogs/catalog_lunch/subjects');

    $response = (new CatalogController())->assignSubjects('catalog_uuid', Request::create('/', 'POST'));

    expect($response->getData(true)['catalog']['subjects'])->toBe([])
        ->and(Model::getConnectionResolver()->connection('mysql')->table('catalog_subjects')->whereNull('deleted_at')->count())->toBe(0);
});

test('the catalog resource lists its stores and trucks for the console, naming a truck by its vehicle', function () {
    $store = (new Store())->forceFill(['uuid' => 'store_uuid', 'public_id' => 'store_public', 'name' => 'Corner Store', 'online' => true, 'status' => 'active']);

    $namedVehicle = (new Vehicle())->forceFill(['name' => 'Taco Truck', 'plate_number' => 'UB-1234', 'online' => true]);
    $namedTruck   = (new FoodTruck())->forceFill(['uuid' => 'truck_named', 'public_id' => 'food_truck_named', 'status' => 'active'])->setRelation('vehicle', $namedVehicle);

    $parkedTruck = (new FoodTruck())->forceFill(['uuid' => 'truck_parked', 'public_id' => 'food_truck_parked'])->setRelation('vehicle', null);
    $bareTruck   = (new FoodTruck())->forceFill(['uuid' => 'truck_bare'])->setRelation('vehicle', null);

    $assignment = fn ($subject) => (new CatalogSubject())->setRelation('subject', $subject);
    $catalog    = (new Catalog())->forceFill(['uuid' => 'catalog_uuid', 'public_id' => 'catalog_lunch', 'name' => 'Lunch'])
        ->setRelation('categories', collect())
        ->setRelation('hours', collect([(new CatalogHour())->forceFill(['uuid' => 'hour_uuid', 'catalog_uuid' => 'catalog_uuid', 'day_of_week' => 'friday', 'start' => '10:00', 'end' => '22:00'])]))
        ->setRelation('assignments', collect([$assignment($store), $assignment(null), $assignment($namedTruck), $assignment($parkedTruck), $assignment($bareTruck)]));

    $data = (new CatalogResource($catalog))->resolve(catalogCoverageRoute('int/v1/storefront/catalogs'));

    expect($data['subjects'])->toBe([
        [
            'id'        => 'store_uuid',
            'uuid'      => 'store_uuid',
            'public_id' => 'store_public',
            'type'      => 'store',
            'name'      => 'Corner Store',
            'plate'     => null,
            'logo_url'  => 'https://flb-assets.s3.ap-southeast-1.amazonaws.com/static/image-file-icon.png',
            'online'    => true,
            'status'    => 'active',
        ],
        [
            'id'        => 'truck_named',
            'uuid'      => 'truck_named',
            'public_id' => 'food_truck_named',
            'type'      => 'food-truck',
            'name'      => 'Taco Truck',
            'plate'     => 'UB-1234',
            'logo_url'  => 'https://s3.ap-southeast-1.amazonaws.com/flb-assets/static/vehicle-placeholder.png',
            'online'    => true,
            'status'    => 'active',
        ],
        [
            'id'        => 'truck_parked',
            'uuid'      => 'truck_parked',
            'public_id' => 'food_truck_parked',
            'type'      => 'food-truck',
            'name'      => 'food_truck_parked',
            'plate'     => null,
            'logo_url'  => null,
            'online'    => false,
            'status'    => null,
        ],
        [
            'id'        => 'truck_bare',
            'uuid'      => 'truck_bare',
            'public_id' => null,
            'type'      => 'food-truck',
            'name'      => 'Truck',
            'plate'     => null,
            'logo_url'  => null,
            'online'    => false,
            'status'    => null,
        ],
    ])
        ->and($data['hours'][0]['day_of_week'])->toBe('friday');

    // The public API never sees who serves the catalog
    $public = (new CatalogResource($catalog))->resolve(catalogCoverageRoute('v1/storefront/catalogs'));
    expect($public)->not->toHaveKeys(['subjects', 'hours', 'uuid'])
        ->and($public['id'])->toBe('catalog_lunch');
});
