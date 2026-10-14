<?php

use Fleetbase\Storefront\Models\Catalog;
use Fleetbase\Storefront\Models\CatalogSubject;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\NetworkStore;
use Fleetbase\Storefront\Models\Store;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Schema\Blueprint;

function storeCoverageSchema(): void
{
    $schema = Capsule::schema('mysql');
    foreach (['stores', 'networks', 'network_stores', 'catalogs', 'catalog_subjects'] as $table) {
        $schema->dropIfExists($table);
    }
    $schema->create('stores', function (Blueprint $table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('networks', function (Blueprint $table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('name')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('network_stores', function (Blueprint $table) {
        $table->increments('id');
        $table->string('network_uuid');
        $table->string('store_uuid');
        $table->string('status')->nullable();
        $table->string('category_uuid')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('catalogs', function (Blueprint $table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('catalog_subjects', function (Blueprint $table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('catalog_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
}

function storeCoverageSeedMemberships(): Store
{
    Store::flushResolvedNetworkIds();
    $connection = Capsule::connection('mysql');
    $connection->table('stores')->insert(['uuid' => 'store_uuid', 'public_id' => 'store_public', 'name' => 'Bloom']);
    $connection->table('networks')->insert([
        ['uuid' => 'network_active_uuid', 'public_id' => 'network_active', 'name' => 'Market'],
        ['uuid' => 'network_suspended_uuid', 'public_id' => 'network_suspended', 'name' => 'Mall'],
        ['uuid' => 'network_legacy_uuid', 'public_id' => 'network_legacy', 'name' => 'Legacy'],
        ['uuid' => 'network_removed_uuid', 'public_id' => 'network_removed', 'name' => 'Removed'],
        ['uuid' => 'network_other_uuid', 'public_id' => 'network_other', 'name' => 'Elsewhere'],
    ]);
    $connection->table('network_stores')->insert([
        ['network_uuid' => 'network_active_uuid', 'store_uuid' => 'store_uuid', 'status' => NetworkStore::STATUS_ACTIVE, 'deleted_at' => null],
        ['network_uuid' => 'network_suspended_uuid', 'store_uuid' => 'store_uuid', 'status' => 'suspended', 'deleted_at' => null],
        // Memberships written before the status column existed carry no status.
        ['network_uuid' => 'network_legacy_uuid', 'store_uuid' => 'store_uuid', 'status' => null, 'deleted_at' => null],
        ['network_uuid' => 'network_removed_uuid', 'store_uuid' => 'store_uuid', 'status' => NetworkStore::STATUS_ACTIVE, 'deleted_at' => now()],
    ]);

    return Store::where('uuid', 'store_uuid')->firstOrFail();
}

afterEach(function () {
    Store::flushResolvedNetworkIds();
});

test('store network membership status is read from the network_stores pivot', function () {
    storeCoverageSchema();
    $store   = storeCoverageSeedMemberships();
    $network = fn (string $uuid) => (new Network())->forceFill(['uuid' => $uuid]);

    expect($store->relationLoaded('networks'))->toBeFalse()
        ->and($store->getNetworkMembershipStatus($network('network_active_uuid')))->toBe('active')
        ->and($store->getNetworkMembershipStatus($network('network_suspended_uuid')))->toBe('suspended')
        // A membership without a status is an active one.
        ->and($store->getNetworkMembershipStatus($network('network_legacy_uuid')))->toBe(NetworkStore::STATUS_ACTIVE)
        // A removed (soft deleted) membership and a network the store never joined are no membership.
        ->and($store->getNetworkMembershipStatus($network('network_removed_uuid')))->toBeNull()
        ->and($store->getNetworkMembershipStatus($network('network_other_uuid')))->toBeNull();
});

test('store network membership status uses eager loaded networks without querying the pivot', function () {
    storeCoverageSchema();
    $store = storeCoverageSeedMemberships()->load('networks');

    // Emptying the pivot proves the answers come from the loaded relation.
    Capsule::connection('mysql')->table('network_stores')->delete();

    expect($store->relationLoaded('networks'))->toBeTrue()
        ->and($store->networks->pluck('uuid')->sort()->values()->all())->toBe(['network_active_uuid', 'network_legacy_uuid', 'network_suspended_uuid'])
        ->and($store->getNetworkMembershipStatus((new Network())->forceFill(['uuid' => 'network_suspended_uuid'])))->toBe('suspended')
        ->and($store->getNetworkMembershipStatus((new Network())->forceFill(['uuid' => 'network_legacy_uuid'])))->toBe('active')
        ->and($store->getNetworkMembershipStatus((new Network())->forceFill(['uuid' => 'network_other_uuid'])))->toBeNull();
});

test('store network membership status resolves networks by uuid or public id', function () {
    storeCoverageSchema();
    $store = storeCoverageSeedMemberships();

    expect($store->getNetworkMembershipStatusUsingId('network_suspended'))->toBe('suspended')
        ->and($store->getNetworkMembershipStatusUsingId('network_active_uuid'))->toBe('active')
        ->and($store->getNetworkMembershipStatusUsingId('network_other'))->toBeNull()
        // Unknown and missing ids short circuit without a membership lookup.
        ->and($store->getNetworkMembershipStatusUsingId('network_missing'))->toBeNull()
        ->and($store->getNetworkMembershipStatusUsingId(null))->toBeNull();
});

test('store catalog assignments and catalogs come from the catalog_subjects morph pivot', function () {
    storeCoverageSchema();
    $connection = Capsule::connection('mysql');
    $store      = (new Store())->forceFill(['uuid' => 'store_uuid']);
    $morphClass = $store->getMorphClass();
    $connection->table('catalogs')->insert([
        ['uuid' => 'catalog_breakfast', 'public_id' => 'catalog_breakfast_public', 'name' => 'Breakfast'],
        ['uuid' => 'catalog_dinner', 'public_id' => 'catalog_dinner_public', 'name' => 'Dinner'],
        ['uuid' => 'catalog_truck', 'public_id' => 'catalog_truck_public', 'name' => 'Truck menu'],
    ]);
    $connection->table('catalog_subjects')->insert([
        ['uuid' => 'assignment_breakfast', 'catalog_uuid' => 'catalog_breakfast', 'subject_type' => $morphClass, 'subject_uuid' => 'store_uuid'],
        ['uuid' => 'assignment_dinner', 'catalog_uuid' => 'catalog_dinner', 'subject_type' => $morphClass, 'subject_uuid' => 'store_uuid'],
        // Another store's assignment, and a different subject type with the same uuid.
        ['uuid' => 'assignment_other_store', 'catalog_uuid' => 'catalog_dinner', 'subject_type' => $morphClass, 'subject_uuid' => 'other_store_uuid'],
        ['uuid' => 'assignment_truck', 'catalog_uuid' => 'catalog_truck', 'subject_type' => 'Fleetbase\\Storefront\\Models\\FoodTruck', 'subject_uuid' => 'store_uuid'],
    ]);

    $assignments = $store->catalogAssignments();
    $catalogs    = $store->catalogs();

    expect($assignments)->toBeInstanceOf(MorphMany::class)
        ->and($assignments->getRelated())->toBeInstanceOf(CatalogSubject::class)
        ->and($assignments->get()->pluck('uuid')->sort()->values()->all())->toBe(['assignment_breakfast', 'assignment_dinner'])
        ->and($catalogs)->toBeInstanceOf(MorphToMany::class)
        ->and($catalogs->getRelated())->toBeInstanceOf(Catalog::class)
        ->and($catalogs->getTable())->toBe('catalog_subjects')
        ->and($catalogs->get()->pluck('name')->sort()->values()->all())->toBe(['Breakfast', 'Dinner']);
});
