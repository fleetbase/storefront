<?php

use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\Models\Category;
use Fleetbase\Models\File;
use Fleetbase\Models\User;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Models\StoreLocation;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;

function createStoreBehaviorSchema(): void
{
    $schema = Capsule::schema('mysql');
    foreach (['network_stores', 'networks', 'store_locations', 'products', 'categories', 'order_configs', 'stores'] as $table) {
        $schema->dropIfExists($table);
    }
    $schema->create('stores', function (Blueprint $table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('order_config_uuid')->nullable();
        $table->string('key')->nullable();
        $table->string('name')->nullable();
        $table->string('currency')->nullable();
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
    $schema->create('products', function (Blueprint $table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('primary_image_uuid')->nullable();
        $table->string('created_by_uuid')->nullable();
        $table->string('store_uuid')->nullable();
        $table->string('category_uuid')->nullable();
        $table->string('name');
        $table->text('description')->nullable();
        $table->text('tags')->nullable();
        $table->string('sku')->nullable();
        $table->integer('price')->default(0);
        $table->integer('sale_price')->nullable();
        $table->string('currency')->nullable();
        $table->boolean('is_service')->default(false);
        $table->boolean('is_bookable')->default(false);
        $table->boolean('is_available')->default(true);
        $table->boolean('is_on_sale')->default(false);
        $table->boolean('is_recommended')->default(false);
        $table->boolean('can_pickup')->default(false);
        $table->string('status')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('store_locations', function (Blueprint $table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('store_uuid');
        $table->string('created_by_uuid')->nullable();
        $table->string('place_uuid');
        $table->string('name');
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

test('store creation assigns a non-empty store key', function () {
    createStoreBehaviorSchema();
    Store::setEventDispatcher(new Dispatcher(app()));
    Store::clearBootedModels();

    $store        = new Store();
    $fireCreating = new ReflectionMethod($store, 'fireModelEvent');
    $fireCreating->invoke($store, 'creating', false);

    expect($store->key)->toStartWith('store_')
        ->and(strlen($store->key))->toBeGreaterThan(20);

    Store::unsetEventDispatcher();
    Store::clearBootedModels();
});

test('store normalizes options and exposes stable media fallbacks and slug configuration', function () {
    $store = new Store();
    $store->setOptionsAttribute(json_encode([
        'required_checkout_min_amount' => '1,250',
        'allow_pickup'                 => true,
    ]));

    expect(json_decode($store->getAttributes()['options'], true))->toBe([
        'required_checkout_min_amount' => 1250,
        'allow_pickup'                 => true,
    ])->and($store->logo_url)->toBe('https://flb-assets.s3.ap-southeast-1.amazonaws.com/static/image-file-icon.png')
        ->and($store->backdrop_url)->toBe('https://flb-assets.s3.ap-southeast-1.amazonaws.com/static/default-storefront-backdrop.png')
        ->and($store->getSlugOptions()->generateSlugFrom)->toBe(['name'])
        ->and($store->getSlugOptions()->slugField)->toBe('slug');

    $store->setOptionsAttribute(new stdClass());

    expect(json_decode($store->getAttributes()['options'], true))->toBe([]);
});

test('store rating and checkout counters reflect recent persisted activity', function () {
    Carbon::setTestNow('2026-07-26 12:00:00');
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('reviews');
    $schema->dropIfExists('checkouts');
    $schema->create('reviews', function ($table) {
        $table->increments('id');
        $table->string('subject_uuid');
        $table->integer('rating');
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('checkouts', function ($table) {
        $table->increments('id');
        $table->string('store_uuid');
        $table->timestamp('created_at');
        $table->timestamp('deleted_at')->nullable();
    });
    $connection->table('reviews')->insert([
        ['subject_uuid' => 'store_uuid', 'rating' => 5],
        ['subject_uuid' => 'store_uuid', 'rating' => 3],
        ['subject_uuid' => 'other_store', 'rating' => 1],
    ]);
    $connection->table('checkouts')->insert([
        ['store_uuid' => 'store_uuid', 'created_at' => now()->subHour()],
        ['store_uuid' => 'store_uuid', 'created_at' => now()->subDays(5)],
        ['store_uuid' => 'store_uuid', 'created_at' => now()->subMonths(2)],
    ]);
    $store = new Store();
    $store->forceFill(['uuid' => 'store_uuid']);

    expect($store->rating)->toBe(4.0)
        ->and($store->this_month_checkouts_count)->toBe(2)
        ->and($store->{'24h_checkouts_count'})->toBe(1);

    Carbon::setTestNow();
});

test('store network category lookup short circuits missing identifiers', function () {
    expect((new Store())->getNetworkCategoryUsingId(null))->toBeNull();
});

test('store exposes its relationship contracts', function () {
    expect((new Store())->createdBy())->toBeInstanceOf(BelongsTo::class)
        ->and((new Store())->company())->toBeInstanceOf(BelongsTo::class)
        ->and((new Store())->logo())->toBeInstanceOf(BelongsTo::class)
        ->and((new Store())->backdrop())->toBeInstanceOf(BelongsTo::class)
        ->and((new Store())->orderConfig())->toBeInstanceOf(BelongsTo::class)
        ->and((new Store())->files())->toBeInstanceOf(HasMany::class)
        ->and((new Store())->media())->toBeInstanceOf(HasMany::class)
        ->and((new Store())->categories())->toBeInstanceOf(HasMany::class)
        ->and((new Store())->products())->toBeInstanceOf(HasMany::class)
        ->and((new Store())->checkouts())->toBeInstanceOf(HasMany::class)
        ->and((new Store())->hours())->toBeInstanceOf(HasMany::class)
        ->and((new Store())->reviews())->toBeInstanceOf(HasMany::class)
        ->and((new Store())->votes())->toBeInstanceOf(HasMany::class)
        ->and((new Store())->notificationChannels())->toBeInstanceOf(HasMany::class)
        ->and((new Store())->gateways())->toBeInstanceOf(HasMany::class)
        ->and((new Store())->locations())->toBeInstanceOf(HasMany::class)
        ->and((new Store())->networkStores())->toBeInstanceOf(HasMany::class)
        ->and((new Store())->networks())->toBeInstanceOf(BelongsToMany::class);
});

test('store resolves an assigned order configuration without a database lookup', function () {
    $config = new OrderConfig();
    $config->forceFill(['uuid' => 'config_uuid']);

    $store = new Store();
    $store->setRelation('orderConfig', $config);

    expect($store->getOrderConfig())->toBe($config)
        ->and($store->getOrderConfigId())->toBe('config_uuid');

    $store->forceFill(['order_config_uuid' => 'direct_config_uuid']);

    expect($store->getOrderConfigId())->toBe('direct_config_uuid');
});

test('store returns no category when it is not assigned to a network', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('network_stores');
    $schema->dropIfExists('networks');
    $schema->create('networks', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('network_stores', function ($table) {
        $table->increments('id');
        $table->string('network_uuid');
        $table->string('store_uuid');
        $table->string('category_uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });

    $network = new Network();
    $network->forceFill(['uuid' => 'network_uuid']);
    $store = new Store();
    $store->forceFill(['uuid' => 'store_uuid']);

    expect($store->getNetworkCategory($network))->toBeNull()
        ->and($store->getNetworkCategoryUsingId('missing_network'))->toBeNull();
});

test('store resolves its category assignment using network uuid and public id', function () {
    createStoreBehaviorSchema();
    $connection = Capsule::connection('mysql');
    $connection->table('stores')->insert(['uuid' => 'store_uuid', 'name' => 'Store']);
    $connection->table('networks')->insert([
        'uuid'      => 'network_uuid',
        'public_id' => 'network_public',
        'name'      => 'Network',
    ]);
    $connection->table('categories')->insert([
        'uuid' => 'category_uuid',
        'name' => 'Groceries',
    ]);
    $connection->table('network_stores')->insert([
        'network_uuid'  => 'network_uuid',
        'store_uuid'    => 'store_uuid',
        'category_uuid' => 'category_uuid',
    ]);

    $store   = Store::where('uuid', 'store_uuid')->firstOrFail();
    $network = Network::where('uuid', 'network_uuid')->firstOrFail();

    expect($store->getNetworkCategory($network)?->uuid)->toBe('category_uuid')
        ->and($store->getNetworkCategoryUsingId('network_uuid')?->uuid)->toBe('category_uuid')
        ->and($store->getNetworkCategoryUsingId('network_public')?->uuid)->toBe('category_uuid');
});

test('store categories preserve ownership parent icon and strict uniqueness contracts', function () {
    createStoreBehaviorSchema();
    $store = new Store();
    $store->forceFill([
        'uuid'         => 'store_uuid',
        'company_uuid' => 'company_uuid',
    ]);
    $parent = new Category();
    $parent->forceFill(['uuid' => 'parent_uuid']);
    $icon = new File();
    $icon->forceFill(['uuid' => 'file_uuid']);

    $withFile = $store->createCategory(
        'Groceries',
        'Everyday goods',
        ['priority' => 1],
        ['mn'       => ['name' => 'Хүнс']],
        $parent,
        $icon,
        '#123456'
    );
    $withName    = $store->createCategory('Restaurants', icon: 'utensils');
    $withoutIcon = $store->createCategory('Pharmacy');
    $existing    = $store->createCategoryStrict('Groceries', 'Changed description');
    $created     = $store->createCategoryStrict('Flowers');

    expect($withFile->owner_uuid)->toBe('store_uuid')
        ->and($withFile->icon_file_uuid)->toBe('file_uuid')
        ->and($withFile->parent_uuid)->toBe('parent_uuid')
        ->and($withFile->meta)->toBe(['priority' => 1])
        ->and($withName->icon)->toBe('utensils')
        ->and($withoutIcon->icon_file_uuid)->toBeNull()
        ->and($existing->description)->toBe('Everyday goods')
        ->and($created->name)->toBe('Flowers')
        ->and(Capsule::connection('mysql')->table('categories')->count())->toBe(4);
});

test('store creates configured products and applies safe defaults', function () {
    createStoreBehaviorSchema();
    $store = new Store();
    $store->forceFill([
        'uuid'         => 'store_uuid',
        'company_uuid' => 'company_uuid',
        'currency'     => 'MNT',
    ]);
    $category = new Category();
    $category->forceFill(['uuid' => 'category_uuid']);
    $image = new File();
    $image->forceFill(['uuid' => 'image_uuid']);
    $user = new User();
    $user->forceFill(['uuid' => 'user_uuid']);

    $configured = $store->createProduct(
        'Delivery',
        'Same-day delivery',
        ['express'],
        $category,
        $image,
        $user,
        'SKU-1',
        1200,
        'available',
        [
            'sale_price'     => 900,
            'is_service'     => true,
            'is_bookable'    => true,
            'is_available'   => false,
            'is_on_sale'     => true,
            'is_recommended' => true,
            'can_pickup'     => true,
        ]
    );
    $default = $store->createProduct('Box', 'Standard box');

    expect($configured->store_uuid)->toBe('store_uuid')
        ->and($configured->company_uuid)->toBe('company_uuid')
        ->and($configured->primary_image_uuid)->toBe('image_uuid')
        ->and($configured->created_by_uuid)->toBe('user_uuid')
        ->and($configured->category_uuid)->toBe('category_uuid')
        ->and($configured->currency)->toBe('MNT')
        ->and($configured->sale_price)->toBe(900)
        ->and($configured->is_service)->toBeTrue()
        ->and($configured->is_bookable)->toBeTrue()
        ->and($configured->is_available)->toBeFalse()
        ->and($configured->is_on_sale)->toBeTrue()
        ->and($configured->is_recommended)->toBeTrue()
        ->and($configured->can_pickup)->toBeTrue()
        ->and($default->sale_price)->toBe(0)
        ->and($default->is_available)->toBeTrue()
        ->and($default->is_service)->toBeFalse();
});

test('store creates named and default locations only for resolvable places', function () {
    createStoreBehaviorSchema();
    $store = new Store();
    $store->forceFill(['uuid' => 'store_uuid', 'name' => 'Central']);
    $place = new Place();
    $place->forceFill(['uuid' => 'place_uuid']);
    $user = new User();
    $user->forceFill(['uuid' => 'user_uuid']);

    $named   = $store->createLocation($place, 'Pickup', $user);
    $default = $store->createLocation($place, null, null);

    expect($named)->toBeInstanceOf(StoreLocation::class)
        ->and($named->name)->toBe('Pickup')
        ->and($named->created_by_uuid)->toBe('user_uuid')
        ->and($default?->name)->toBe('Central store location')
        ->and($store->createLocation(42, null, null))->toBeNull();
});

test('store order config falls back to the company default and fails clearly without one', function () {
    createStoreBehaviorSchema();
    Capsule::connection('mysql')->table('order_configs')->insert([
        'uuid'         => 'config_default',
        'company_uuid' => 'store_company_uuid',
        'key'          => 'storefront',
        'namespace'    => 'system:order-config:storefront',
    ]);
    session(['company' => 'store_company_uuid']);

    $store = new Store();
    $store->setRelation('orderConfig', null);
    $default = $store->getOrderConfig();

    expect($default)->toBeInstanceOf(OrderConfig::class)
        ->and($default->uuid)->toBe('config_default')
        ->and($store->getRelation('orderConfig'))->toBe($default)
        ->and($store->getOrderConfigId())->toBe('config_default');

    session(['company' => null]);
    $missing = new Store();
    $missing->setRelation('orderConfig', null);

    expect(fn () => $missing->getOrderConfig())
        ->toThrow(RuntimeException::class, 'No default OrderConfig is configured.');
});
