<?php

use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\Models\Category;
use Fleetbase\Models\File;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Store;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;

function createNetworkBehaviorSchema(): void
{
    $schema = Capsule::schema('mysql');
    foreach (['network_stores', 'networks', 'stores', 'categories', 'order_configs'] as $table) {
        $schema->dropIfExists($table);
    }
    $schema->create('networks', function (Blueprint $table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('order_config_uuid')->nullable();
        $table->string('key')->nullable();
        $table->string('name')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('stores', function (Blueprint $table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('name')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('network_stores', function (Blueprint $table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('network_uuid');
        $table->string('store_uuid');
        $table->string('category_uuid')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('categories', function (Blueprint $table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->string('owner_type')->nullable();
        $table->string('parent_uuid')->nullable();
        $table->string('icon_file_uuid')->nullable();
        $table->string('name');
        $table->text('description')->nullable();
        $table->text('translations')->nullable();
        $table->text('meta')->nullable();
        $table->string('icon')->nullable();
        $table->string('icon_color')->nullable();
        $table->string('slug')->nullable();
        $table->string('for')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('order_configs', function (Blueprint $table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('key')->nullable();
        $table->string('namespace')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
}

test('network creation assigns a non-empty network key', function () {
    createNetworkBehaviorSchema();
    Network::setEventDispatcher(new Dispatcher(app()));
    Network::clearBootedModels();

    $network = new Network();
    $network->forceFill(['uuid' => 'network_uuid', 'name' => 'Delivery Network']);
    $fireCreating = new ReflectionMethod($network, 'fireModelEvent');
    $fireCreating->invoke($network, 'creating', false);

    expect($network->key)->toStartWith('network_')
        ->and(strlen($network->key))->toBeGreaterThan(20);

    Network::unsetEventDispatcher();
    Network::clearBootedModels();
});

test('networks add stores idempotently and report active store counts', function () {
    createNetworkBehaviorSchema();
    $connection = Capsule::connection('mysql');
    $connection->table('networks')->insert([
        'uuid'      => 'network_uuid',
        'public_id' => 'network_public',
        'name'      => 'Delivery Network',
    ]);
    $connection->table('stores')->insert([
        ['uuid' => 'store_one_uuid', 'public_id' => 'store_one', 'name' => 'Store One'],
        ['uuid' => 'store_two_uuid', 'public_id' => 'store_two', 'name' => 'Store Two'],
    ]);
    $connection->table('categories')->insert([
        'uuid'  => 'category_uuid',
        'name'  => 'Food',
        'for'   => 'network_category',
    ]);

    $network  = Network::where('uuid', 'network_uuid')->firstOrFail();
    $storeOne = Store::where('uuid', 'store_one_uuid')->firstOrFail();
    $storeTwo = Store::where('uuid', 'store_two_uuid')->firstOrFail();
    $category = Category::where('uuid', 'category_uuid')->firstOrFail();

    $first = $network->addStore($storeOne, $category);
    $same  = $network->addStore($storeOne);
    $network->addStore($storeTwo);
    $connection->table('network_stores')->where('store_uuid', 'store_two_uuid')->update(['deleted_at' => now()]);

    expect($first->category_uuid)->toBe('category_uuid')
        ->and($same->network_uuid)->toBe($first->network_uuid)
        ->and($same->store_uuid)->toBe($first->store_uuid)
        ->and($connection->table('network_stores')->where('store_uuid', 'store_one_uuid')->count())->toBe(1)
        ->and($network->stores_count)->toBe(1);
});

test('network categories preserve parent icon and strict uniqueness contracts', function () {
    createNetworkBehaviorSchema();
    $network = new Network();
    $network->forceFill([
        'uuid'         => 'network_uuid',
        'company_uuid' => 'company_uuid',
    ]);
    $parent = new Category();
    $parent->forceFill(['uuid' => 'parent_uuid']);
    $icon = new File();
    $icon->forceFill(['uuid' => 'file_uuid']);

    $withFile = $network->createCategory(
        'Groceries',
        'Everyday goods',
        ['priority' => 1],
        ['mn'       => ['name' => 'Хүнс']],
        $parent,
        $icon,
        '#123456'
    );
    $withName    = $network->createCategory('Restaurants', icon: 'utensils');
    $withoutIcon = $network->createCategory('Pharmacy');
    $existing    = $network->createCategoryStrict('Groceries', 'Changed description');
    $created     = $network->createCategoryStrict('Flowers');

    expect($withFile->icon_file_uuid)->toBe('file_uuid')
        ->and($withFile->parent_uuid)->toBe('parent_uuid')
        ->and($withFile->meta)->toBe(['priority' => 1])
        ->and($withName->icon)->toBe('utensils')
        ->and($withoutIcon->icon_file_uuid)->toBeNull()
        ->and($existing->name)->toBe($withFile->name)
        ->and($existing->description)->toBe('Everyday goods')
        ->and($created->name)->toBe('Flowers')
        ->and(Capsule::connection('mysql')->table('categories')->count())->toBe(4);
});

test('network order config falls back to the company default and fails clearly without one', function () {
    createNetworkBehaviorSchema();
    Capsule::connection('mysql')->table('order_configs')->insert([
        'uuid'         => 'config_default',
        'company_uuid' => 'network_company_uuid',
        'key'          => 'storefront',
        'namespace'    => 'system:order-config:storefront',
    ]);
    session(['company' => 'network_company_uuid']);

    $network = new Network();
    $network->setRelation('orderConfig', null);
    $default = $network->getOrderConfig();

    expect($default)->toBeInstanceOf(OrderConfig::class)
        ->and($default->uuid)->toBe('config_default')
        ->and($network->getRelation('orderConfig'))->toBe($default)
        ->and($network->getOrderConfigId())->toBe('config_default');

    session(['company' => null]);
    $missing = new Network();
    $missing->setRelation('orderConfig', null);

    expect(fn () => $missing->getOrderConfig())
        ->toThrow(RuntimeException::class, 'No default OrderConfig is configured.');
});

test('network options accept valid JSON and invitation relationships are exposed', function () {
    $environmentRepository = new ReflectionProperty(Illuminate\Support\Env::class, 'repository');
    $environmentRepository->setValue(null, new class {
        public function get(string $key): mixed
        {
            return null;
        }
    });

    $network          = new Network();
    $network->options = json_encode([
        'required_checkout_min_amount' => '$25.50',
        'pickup'                       => true,
    ]);
    $validOptions     = $network->getAttributes()['options'];
    $network->options = new stdClass();

    expect($validOptions)->toBe([
        'required_checkout_min_amount' => 2550,
        'pickup'                       => true,
    ])->and($network->getAttributes()['options'])->toBe([])
        ->and($network->invitations())->toBeInstanceOf(Illuminate\Database\Eloquent\Relations\HasMany::class);
});
