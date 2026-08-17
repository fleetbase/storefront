<?php

use Fleetbase\Storefront\Http\Controllers\v1\NetworkController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

function createNetworkApiControllerSchema(): void
{
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();

    foreach (['checkouts', 'reviews', 'files', 'categories', 'places', 'stores', 'store_locations', 'network_stores', 'networks'] as $table) {
        $schema->dropIfExists($table);
    }

    $schema->create('stores', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->text('tags')->nullable();
        $table->string('name')->nullable();
        $table->text('description')->nullable();
        $table->string('logo_uuid')->nullable();
        $table->string('backdrop_uuid')->nullable();
        $table->boolean('online')->default(true);
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('store_locations', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('store_uuid')->nullable();
        $table->string('place_uuid')->nullable();
        $table->text('tags')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('network_stores', function ($table) {
        $table->increments('id');
        $table->string('network_uuid')->nullable();
        $table->string('store_uuid')->nullable();
        $table->string('category_uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('networks', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('places', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->text('location')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('categories', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->string('for')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('files', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('reviews', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->integer('rating')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('checkouts', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('store_uuid')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
}

test('network stores endpoint rejects storefront store contexts', function () {
    session(['storefront_store' => 'store_uuid']);

    $response = (new NetworkController())->stores(Request::create('/network/stores'));

    expect($response->getData(true))->toBe(['error' => 'Stores cannot have stores!']);
});

test('network stores endpoint returns an empty collection for a network without stores', function () {
    createNetworkApiControllerSchema();
    session([
        'company'            => 'company_uuid',
        'storefront_store'   => null,
        'storefront_network' => 'network_uuid',
    ]);
    $request = Request::create('/network/stores', 'GET', [
        'ids'     => 'store_one,store_two',
        'exclude' => 'store_three',
        'limit'   => 10,
        'offset'  => 2,
    ]);

    $resource = (new NetworkController())->stores($request);

    expect($resource->resource)->toBeEmpty();
});

test('network stores endpoint applies membership category tag id and exclusion filters', function () {
    createNetworkApiControllerSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('categories')->insert([
        'uuid'       => 'category_uuid',
        'public_id'  => 'category_abcdefgh',
        'owner_uuid' => 'network_uuid',
        'for'        => 'storefront_network',
    ]);
    $connection->table('networks')->insert(['uuid' => 'network_uuid']);
    $connection->table('stores')->insert([
        [
            'uuid'         => 'store_match_uuid',
            'public_id'    => 'store_abcdefgh',
            'company_uuid' => 'company_uuid',
            'name'         => 'Local Grocery',
            'tags'         => json_encode(['food', 'local']),
            'created_at'   => '2026-01-01 00:00:00',
        ],
        [
            'uuid'         => 'store_excluded_uuid',
            'public_id'    => 'store_excluded',
            'company_uuid' => 'company_uuid',
            'name'         => 'Excluded Grocery',
            'tags'         => json_encode(['food', 'local']),
            'created_at'   => '2026-01-02 00:00:00',
        ],
        [
            'uuid'         => 'store_other_company_uuid',
            'public_id'    => 'store_other',
            'company_uuid' => 'other_company',
            'name'         => 'Other Grocery',
            'tags'         => json_encode(['food', 'local']),
            'created_at'   => '2026-01-03 00:00:00',
        ],
        [
            'uuid'         => 'store_uncategorized_one',
            'public_id'    => 'store_uncategorized_one',
            'company_uuid' => 'company_uuid',
            'name'         => 'Uncategorized One',
            'tags'         => json_encode([]),
            'created_at'   => '2026-01-04 00:00:00',
        ],
        [
            'uuid'         => 'store_uncategorized_two',
            'public_id'    => 'store_uncategorized_two',
            'company_uuid' => 'company_uuid',
            'name'         => 'Uncategorized Two',
            'tags'         => json_encode([]),
            'created_at'   => '2026-01-05 00:00:00',
        ],
    ]);
    $connection->table('store_locations')->insert([
        ['uuid' => 'location_match', 'store_uuid' => 'store_match_uuid'],
        ['uuid' => 'location_excluded', 'store_uuid' => 'store_excluded_uuid'],
        ['uuid' => 'location_other', 'store_uuid' => 'store_other_company_uuid'],
        ['uuid' => 'location_uncategorized_one', 'store_uuid' => 'store_uncategorized_one'],
        ['uuid' => 'location_uncategorized_two', 'store_uuid' => 'store_uncategorized_two'],
    ]);
    $connection->table('network_stores')->insert([
        [
            'network_uuid'  => 'network_uuid',
            'store_uuid'    => 'store_match_uuid',
            'category_uuid' => 'category_uuid',
        ],
        [
            'network_uuid'  => 'network_uuid',
            'store_uuid'    => 'store_uncategorized_one',
            'category_uuid' => null,
        ],
        [
            'network_uuid'  => 'network_uuid',
            'store_uuid'    => 'store_uncategorized_two',
            'category_uuid' => null,
        ],
        [
            'network_uuid'  => 'network_uuid',
            'store_uuid'    => 'store_excluded_uuid',
            'category_uuid' => 'category_uuid',
        ],
        [
            'network_uuid'  => 'network_uuid',
            'store_uuid'    => 'store_other_company_uuid',
            'category_uuid' => 'category_uuid',
        ],
    ]);
    session([
        'company'            => 'company_uuid',
        'storefront_store'   => null,
        'storefront_network' => 'network_uuid',
    ]);
    $request = Request::create('/network/stores', 'GET', [
        'category' => 'category_abcdefgh',
        'tagged'   => 'food,local',
        'online'   => true,
        'ids'      => 'store_abcdefgh,store_excluded',
        'exclude'  => 'store_excluded',
        'limit'    => 10,
        'offset'   => 0,
    ]);

    $resource      = (new NetworkController())->stores($request);
    $uncategorized = (new NetworkController())->stores(Request::create('/network/stores', 'GET', [
        'without_category' => true,
        'ids'              => ['store_uncategorized_one', 'store_uncategorized_two'],
        'limit'            => 1,
        'offset'           => 1,
    ]));

    expect($resource->resource)->toHaveCount(1)
        ->and($resource->resource->first()->uuid)->toBe('store_match_uuid')
        ->and($uncategorized->resource)->toHaveCount(1)
        ->and($uncategorized->resource->first()->uuid)->toBe('store_uncategorized_two');
});

test('network stores endpoint sorts popular stores by checkout count', function () {
    createNetworkApiControllerSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('networks')->insert(['uuid' => 'network_uuid']);
    $connection->table('stores')->insert([
        [
            'uuid'         => 'store_popular_uuid',
            'public_id'    => 'store_popular',
            'company_uuid' => 'company_uuid',
            'name'         => 'Popular store',
        ],
        [
            'uuid'         => 'store_quiet_uuid',
            'public_id'    => 'store_quiet',
            'company_uuid' => 'company_uuid',
            'name'         => 'Quiet store',
        ],
    ]);
    $connection->table('store_locations')->insert([
        ['uuid' => 'location_popular', 'store_uuid' => 'store_popular_uuid'],
        ['uuid' => 'location_quiet', 'store_uuid' => 'store_quiet_uuid'],
    ]);
    $connection->table('network_stores')->insert([
        ['network_uuid' => 'network_uuid', 'store_uuid' => 'store_popular_uuid'],
        ['network_uuid' => 'network_uuid', 'store_uuid' => 'store_quiet_uuid'],
    ]);
    $connection->table('checkouts')->insert([
        ['uuid' => 'checkout_one', 'store_uuid' => 'store_popular_uuid', 'created_at' => now()->subDays(3), 'updated_at' => now()->subDays(3)],
        ['uuid' => 'checkout_two', 'store_uuid' => 'store_popular_uuid', 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)],
        ['uuid' => 'checkout_three', 'store_uuid' => 'store_quiet_uuid', 'created_at' => now(), 'updated_at' => now()],
    ]);
    session([
        'company'            => 'company_uuid',
        'storefront_store'   => null,
        'storefront_network' => 'network_uuid',
    ]);

    $resource = (new NetworkController())->stores(Request::create('/network/stores', 'GET', [
        'sort' => 'popular',
    ]));
    $trending = (new NetworkController())->stores(Request::create('/network/stores', 'GET', [
        'sort' => 'trending',
    ]));

    expect($resource->resource->pluck('uuid')->all())->toBe([
        'store_popular_uuid',
        'store_quiet_uuid',
    ])->and($resource->resource->first()->checkouts_count)->toBe(2)
        ->and($trending->resource->first()->uuid)->toBe('store_quiet_uuid')
        ->and($trending->resource->first()->recent_checkouts_count)->toBe(1);
});

test('network stores endpoint searches member stores and includes cross-company invitees', function () {
    createNetworkApiControllerSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('networks')->insert(['uuid' => 'network_uuid']);
    $connection->table('stores')->insert([
        [
            'uuid'         => 'member_uuid',
            'public_id'    => 'store_member',
            'company_uuid' => 'invited_company',
            'name'         => 'Moonlight Bakery',
            'description'  => 'Fresh sourdough',
        ],
        [
            'uuid'         => 'nonmember_uuid',
            'public_id'    => 'store_nonmember',
            'company_uuid' => 'company_uuid',
            'name'         => 'Moonlight Market',
            'description'  => null,
        ],
    ]);
    $connection->table('store_locations')->insert([
        ['uuid' => 'member_location', 'store_uuid' => 'member_uuid'],
        ['uuid' => 'nonmember_location', 'store_uuid' => 'nonmember_uuid'],
    ]);
    $connection->table('network_stores')->insert([
        'network_uuid' => 'network_uuid',
        'store_uuid'   => 'member_uuid',
    ]);
    session([
        'company'            => 'company_uuid',
        'storefront_store'   => null,
        'storefront_network' => 'network_uuid',
    ]);

    $resource = (new NetworkController())->stores(Request::create('/network/stores', 'GET', [
        'query' => 'sourdough',
    ]));

    expect($resource->resource)->toHaveCount(1)
        ->and($resource->resource->first()->uuid)->toBe('member_uuid');
});

test('network stores endpoint returns no stores for an unknown or foreign category', function () {
    createNetworkApiControllerSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('networks')->insert(['uuid' => 'network_uuid']);
    $connection->table('categories')->insert([
        'uuid'       => 'foreign_category_uuid',
        'public_id'  => 'category_foreign',
        'owner_uuid' => 'other_network_uuid',
        'for'        => 'storefront_network',
    ]);
    $connection->table('stores')->insert([
        'uuid'         => 'member_uuid',
        'public_id'    => 'store_member',
        'company_uuid' => 'company_uuid',
        'name'         => 'Member store',
    ]);
    $connection->table('store_locations')->insert(['uuid' => 'member_location', 'store_uuid' => 'member_uuid']);
    $connection->table('network_stores')->insert([
        'network_uuid'  => 'network_uuid',
        'store_uuid'    => 'member_uuid',
        'category_uuid' => 'foreign_category_uuid',
    ]);
    session(['storefront_network' => 'network_uuid', 'storefront_store' => null]);
    $controller = new NetworkController();

    $unknown = $controller->stores(Request::create('/network/stores', 'GET', ['category' => 'category_missing']));
    $foreign = $controller->stores(Request::create('/network/stores', 'GET', ['category' => 'category_foreign']));

    expect($unknown->resource)->toBeEmpty()
        ->and($foreign->resource)->toBeEmpty();
});

test('network stores endpoint honors rating and age sort contracts', function () {
    createNetworkApiControllerSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('networks')->insert(['uuid' => 'network_uuid']);
    $connection->table('stores')->insert([
        [
            'uuid'         => 'store_high_uuid',
            'public_id'    => 'store_high',
            'company_uuid' => 'company_uuid',
            'name'         => 'High rated newer store',
            'created_at'   => '2026-02-01 00:00:00',
        ],
        [
            'uuid'         => 'store_low_uuid',
            'public_id'    => 'store_low',
            'company_uuid' => 'company_uuid',
            'name'         => 'Low rated older store',
            'created_at'   => '2026-01-01 00:00:00',
        ],
    ]);
    $connection->table('store_locations')->insert([
        ['uuid' => 'location_high', 'store_uuid' => 'store_high_uuid'],
        ['uuid' => 'location_low', 'store_uuid' => 'store_low_uuid'],
    ]);
    $connection->table('network_stores')->insert([
        ['network_uuid' => 'network_uuid', 'store_uuid' => 'store_high_uuid'],
        ['network_uuid' => 'network_uuid', 'store_uuid' => 'store_low_uuid'],
    ]);
    $connection->table('reviews')->insert([
        ['uuid' => 'review_high', 'subject_uuid' => 'store_high_uuid', 'rating' => 5],
        ['uuid' => 'review_low', 'subject_uuid' => 'store_low_uuid', 'rating' => 1],
    ]);
    session([
        'company'            => 'company_uuid',
        'storefront_store'   => null,
        'storefront_network' => 'network_uuid',
    ]);
    $controller = new NetworkController();

    $highest = $controller->stores(Request::create('/network/stores?sort=highest_rated', 'GET', ['sort' => 'highest_rated']));
    $lowest  = $controller->stores(Request::create('/network/stores?sort=lowest_rated', 'GET', ['sort' => 'lowest_rated']));
    $newest  = $controller->stores(Request::create('/network/stores?sort=newest', 'GET', ['sort' => 'newest']));
    $oldest  = $controller->stores(Request::create('/network/stores?sort=oldest', 'GET', ['sort' => 'oldest']));

    expect($highest->resource->first()->uuid)->toBe('store_high_uuid')
        ->and($lowest->resource->first()->uuid)->toBe('store_low_uuid')
        ->and($newest->resource->first()->uuid)->toBe('store_high_uuid')
        ->and($oldest->resource->first()->uuid)->toBe('store_low_uuid');
});

test('network stores endpoint sorts stores by their nearest persisted location', function () {
    createNetworkApiControllerSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('networks')->insert(['uuid' => 'network_uuid']);
    $connection->table('stores')->insert([
        [
            'uuid'         => 'store_near_uuid',
            'public_id'    => 'store_near',
            'company_uuid' => 'company_uuid',
            'name'         => 'Near store',
        ],
        [
            'uuid'         => 'store_far_uuid',
            'public_id'    => 'store_far',
            'company_uuid' => 'company_uuid',
            'name'         => 'Far store',
        ],
    ]);
    $connection->table('network_stores')->insert([
        ['network_uuid' => 'network_uuid', 'store_uuid' => 'store_near_uuid'],
        ['network_uuid' => 'network_uuid', 'store_uuid' => 'store_far_uuid'],
    ]);
    $connection->table('places')->insert([
        ['uuid' => 'place_near_uuid', 'location' => pack('V', 0) . pack('CVee', 1, 1, 106.9177, 47.9185)],
        ['uuid' => 'place_near_second_uuid', 'location' => pack('V', 0) . pack('CVee', 1, 1, 106.9300, 47.9300)],
        ['uuid' => 'place_far_uuid', 'location' => pack('V', 0) . pack('CVee', 1, 1, 107.2000, 48.1000)],
        ['uuid' => 'place_far_second_uuid', 'location' => pack('V', 0) . pack('CVee', 1, 1, 107.3000, 48.2000)],
    ]);
    $connection->table('store_locations')->insert([
        [
            'uuid'       => 'location_near_uuid',
            'store_uuid' => 'store_near_uuid',
            'place_uuid' => 'place_near_uuid',
        ],
        [
            'uuid'       => 'location_far_uuid',
            'store_uuid' => 'store_far_uuid',
            'place_uuid' => 'place_far_uuid',
        ],
        [
            'uuid'       => 'location_near_second_uuid',
            'store_uuid' => 'store_near_uuid',
            'place_uuid' => 'place_near_second_uuid',
        ],
        [
            'uuid'       => 'location_far_second_uuid',
            'store_uuid' => 'store_far_uuid',
            'place_uuid' => 'place_far_second_uuid',
        ],
    ]);
    session([
        'company'            => 'company_uuid',
        'storefront_store'   => null,
        'storefront_network' => 'network_uuid',
    ]);

    $resource = (new NetworkController())->stores(Request::create('/network/stores', 'GET', [
        'sort'     => 'nearest',
        'location' => ['latitude' => 47.9184, 'longitude' => 106.9176],
    ]));
    $limited = (new NetworkController())->stores(Request::create('/network/stores', 'GET', [
        'sort'     => 'nearest',
        'location' => ['latitude' => 47.9184, 'longitude' => 106.9176],
        'limit'    => 1,
        'offset'   => 1,
    ]));
    $withinDistance = (new NetworkController())->stores(Request::create('/network/stores', 'GET', [
        'sort'             => 'newest',
        'location'         => ['latitude' => 47.9184, 'longitude' => 106.9176],
        'maximum_distance' => 1000,
    ]));

    expect($resource->resource->pluck('uuid')->all())->toBe([
        'store_near_uuid',
        'store_far_uuid',
    ])->and($resource->resource->first()->locations->first()->distance)->toBeLessThan(
        $resource->resource->last()->locations->first()->distance
    )->and($limited->resource->pluck('uuid')->all())->toBe(['store_far_uuid'])
        ->and($withinDistance->resource->pluck('uuid')->all())->toBe(['store_near_uuid']);
});

test('network tags endpoint returns unique tags across assigned stores', function () {
    createNetworkApiControllerSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('stores')->insert([
        ['uuid' => 'store_one', 'tags' => json_encode(['food', 'local'])],
        ['uuid' => 'store_two', 'tags' => json_encode(['local', 'delivery'])],
    ]);
    $connection->table('store_locations')->insert([
        ['uuid' => 'location_one', 'store_uuid' => 'store_one'],
        ['uuid' => 'location_two', 'store_uuid' => 'store_two'],
    ]);
    $connection->table('networks')->insert(['uuid' => 'network_uuid']);
    $connection->table('network_stores')->insert([
        ['network_uuid' => 'network_uuid', 'store_uuid' => 'store_one'],
        ['network_uuid' => 'network_uuid', 'store_uuid' => 'store_two'],
    ]);
    session(['storefront_network' => 'network_uuid']);

    $response = (new NetworkController())->tags(Request::create('/network/tags'));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['food', 'local', 'delivery']);
});

test('network store locations apply store membership search and identifier filters', function () {
    createNetworkApiControllerSchema();
    config(['database.connections.mysql.database' => 'main']);
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('networks')->insert(['uuid' => 'network_uuid']);
    $connection->table('stores')->insert([
        [
            'uuid'         => 'store_match_uuid',
            'public_id'    => 'store_abcdefgh',
            'company_uuid' => 'company_uuid',
            'name'         => 'Local Grocery',
            'tags'         => json_encode(['food', 'local']),
        ],
        [
            'uuid'         => 'store_other_uuid',
            'public_id'    => 'store_other',
            'company_uuid' => 'company_uuid',
            'name'         => 'Other Shop',
            'tags'         => json_encode(['retail']),
        ],
    ]);
    $connection->table('network_stores')->insert([
        ['network_uuid' => 'network_uuid', 'store_uuid' => 'store_match_uuid'],
        ['network_uuid' => 'network_uuid', 'store_uuid' => 'store_other_uuid'],
    ]);
    $connection->table('places')->insert([
        ['uuid' => 'place_match_uuid', 'location' => null],
        ['uuid' => 'place_other_uuid', 'location' => null],
    ]);
    $connection->table('store_locations')->insert([
        [
            'uuid'       => 'location_match_uuid',
            'public_id'  => 'location_abcdefgh',
            'store_uuid' => 'store_match_uuid',
            'place_uuid' => 'place_match_uuid',
            'tags'       => json_encode(['pickup']),
        ],
        [
            'uuid'       => 'location_other_uuid',
            'public_id'  => 'location_other',
            'store_uuid' => 'store_other_uuid',
            'place_uuid' => 'place_other_uuid',
            'tags'       => json_encode([]),
        ],
    ]);
    session(['storefront_network' => 'network_uuid']);
    $request = Request::create('/network/store-locations', 'GET', [
        'ids'        => 'location_abcdefgh,location_other',
        'exclude'    => 'location_other',
        'tagged'     => 'food,local',
        'query'      => 'Local',
        'with_store' => true,
        'limit'      => 5,
        'offset'     => 0,
    ]);

    $resource       = (new NetworkController())->storeLocations($request);
    $offsetResource = (new NetworkController())->storeLocations(Request::create(
        '/network/store-locations',
        'GET',
        [
            'ids'    => ['location_abcdefgh', 'location_other'],
            'limit'  => 1,
            'offset' => 1,
        ]
    ));

    expect($resource->resource)->toHaveCount(1)
        ->and($resource->resource->first()->uuid)->toBe('location_match_uuid')
        ->and($resource->resource->first()->relationLoaded('store'))->toBeTrue()
        ->and($offsetResource->resource)->toHaveCount(1)
        ->and($offsetResource->resource->first()->uuid)->toBe('location_other_uuid');
});

test('store locations and tags preserve store context while rejecting missing contexts', function () {
    createNetworkApiControllerSchema();
    config(['database.connections.mysql.database' => 'main']);
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('stores')->insert([
        ['uuid' => 'store_uuid', 'tags' => json_encode(['local', 'pickup'])],
        ['uuid' => 'other_store_uuid', 'tags' => json_encode(['foreign'])],
    ]);
    $connection->table('places')->insert([
        ['uuid' => 'store_place_uuid'],
        ['uuid' => 'other_place_uuid'],
    ]);
    $connection->table('store_locations')->insert([
        ['uuid' => 'store_location_uuid', 'public_id' => 'location_store', 'store_uuid' => 'store_uuid', 'place_uuid' => 'store_place_uuid'],
        ['uuid' => 'other_location_uuid', 'public_id' => 'location_other', 'store_uuid' => 'other_store_uuid', 'place_uuid' => 'other_place_uuid'],
    ]);
    $controller = new NetworkController();
    session(['storefront_store' => 'store_uuid', 'storefront_network' => null]);

    $storeLocations = $controller->storeLocations(Request::create('/network/store-locations'));
    $storeTags      = $controller->tags(Request::create('/network/tags'));

    session(['storefront_store' => null, 'storefront_network' => null]);
    $missingLocations = $controller->storeLocations(Request::create('/network/store-locations'));
    $missingTags      = $controller->tags(Request::create('/network/tags'));

    expect($storeLocations->resource->pluck('uuid')->all())->toBe(['store_location_uuid'])
        ->and($storeTags->getData(true))->toBe(['local', 'pickup'])
        ->and($missingLocations->getStatusCode())->toBe(400)
        ->and($missingTags->getStatusCode())->toBe(400);
});
