<?php

use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\Storefront\Models\Catalog;
use Fleetbase\Storefront\Models\CatalogCategory;
use Fleetbase\Storefront\Models\CatalogProduct;
use Fleetbase\Storefront\Models\CatalogSubject;
use Fleetbase\Storefront\Models\FoodTruck;
use Fleetbase\Storefront\Models\Network;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

test('catalog exposes its ownership availability and assignment relationships', function () {
    expect((new Catalog())->createdBy())->toBeInstanceOf(BelongsTo::class)
        ->and((new Catalog())->company())->toBeInstanceOf(BelongsTo::class)
        ->and((new Catalog())->store())->toBeInstanceOf(BelongsTo::class)
        ->and((new Catalog())->hours())->toBeInstanceOf(HasMany::class)
        ->and((new Catalog())->categories())->toBeInstanceOf(HasMany::class)
        ->and((new Catalog())->assignments())->toBeInstanceOf(HasMany::class)
        ->and((new Catalog())->subjects())->toBeInstanceOf(MorphToMany::class);
});

test('catalog synchronizes category updates removals and additions', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('categories');
    $schema->dropIfExists('catalog_category_products');
    $schema->dropIfExists('products');
    $schema->create('categories', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->string('owner_type')->nullable();
        $table->string('name');
        $table->string('for')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('catalog_category_products', function ($table) {
        $table->string('uuid')->nullable();
        $table->string('catalog_category_uuid')->nullable();
        $table->string('product_uuid')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('products', function ($table) {
        $table->string('uuid')->primary();
        $table->timestamp('deleted_at')->nullable();
    });

    $keepUuid   = '70000000-0000-4000-8000-000000000001';
    $removeUuid = '70000000-0000-4000-8000-000000000002';
    $connection->table('categories')->insert([
        [
            'uuid'         => $keepUuid,
            'company_uuid' => 'company_uuid',
            'owner_uuid'   => 'catalog_uuid',
            'owner_type'   => Catalog::class,
            'name'         => 'Old name',
            'created_at'   => now(),
            'updated_at'   => now(),
        ],
        [
            'uuid'         => $removeUuid,
            'company_uuid' => 'company_uuid',
            'owner_uuid'   => 'catalog_uuid',
            'owner_type'   => Catalog::class,
            'name'         => 'Remove me',
            'created_at'   => now(),
            'updated_at'   => now(),
        ],
    ]);

    $keep = CatalogCategory::where('uuid', $keepUuid)->firstOrFail();
    $keep->setRelation('products', collect());
    $remove = CatalogCategory::where('uuid', $removeUuid)->firstOrFail();

    $catalog = new Catalog([
        'uuid'         => 'catalog_uuid',
        'company_uuid' => 'company_uuid',
    ]);
    $catalog->setRelation('categories', collect([$keep, $remove]));

    expect($catalog->setCategories([
        ['uuid' => $keepUuid, 'name' => 'Updated name', 'products' => []],
        ['name' => 'New category', 'products' => []],
    ]))->toBe($catalog);

    expect($connection->table('categories')->where('uuid', $keepUuid)->value('name'))->toBe('Updated name')
        ->and($connection->table('categories')->where('uuid', $removeUuid)->value('deleted_at'))->not->toBeNull()
        ->and($connection->table('categories')->where('name', 'New category')->exists())->toBeTrue();
});

test('catalog category exposes catalog product and polymorphic ownership relationships', function () {
    expect((new CatalogCategory())->owner())->toBeInstanceOf(MorphTo::class)
        ->and((new CatalogCategory())->catalog())->toBeInstanceOf(BelongsTo::class)
        ->and((new CatalogCategory())->products())->toBeInstanceOf(BelongsToMany::class);
});

test('catalog category synchronizes valid unique product assignments', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('catalog_category_products');
    $schema->create('catalog_category_products', function ($table) {
        $table->string('uuid')->nullable();
        $table->string('catalog_category_uuid');
        $table->string('product_uuid');
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });

    $categoryUuid = '10000000-0000-4000-8000-000000000001';
    $keepUuid     = '20000000-0000-4000-8000-000000000001';
    $removeUuid   = '20000000-0000-4000-8000-000000000002';
    $addUuid      = '20000000-0000-4000-8000-000000000003';

    $connection->table('catalog_category_products')->insert([
        [
            'uuid'                  => '30000000-0000-4000-8000-000000000001',
            'catalog_category_uuid' => $categoryUuid,
            'product_uuid'          => $keepUuid,
            'created_at'            => now(),
            'updated_at'            => now(),
        ],
        [
            'uuid'                  => '30000000-0000-4000-8000-000000000002',
            'catalog_category_uuid' => $categoryUuid,
            'product_uuid'          => $removeUuid,
            'created_at'            => now(),
            'updated_at'            => now(),
        ],
    ]);

    $category = new CatalogCategory();
    $category->forceFill(['uuid' => $categoryUuid]);
    $category->setRelation('products', collect());

    expect($category->setProducts([
        $keepUuid,
        ['uuid' => $addUuid],
        (object) ['uuid' => $addUuid],
        'not-a-uuid',
        ['uuid' => null],
    ]))->toBe($category);

    $active = $connection->table('catalog_category_products')
        ->where('catalog_category_uuid', $categoryUuid)
        ->whereNull('deleted_at')
        ->orderBy('product_uuid')
        ->pluck('product_uuid')
        ->all();

    expect($active)->toBe([$keepUuid, $addUuid])
        ->and($connection->table('catalog_category_products')
            ->where('product_uuid', $removeUuid)
            ->whereNotNull('deleted_at')
            ->exists())->toBeTrue();
});

test('catalog pivot models expose their configured relationship contracts', function () {
    $product = new CatalogProduct();
    $subject = new CatalogSubject();

    expect($product->getTable())->toBe('catalog_category_products')
        ->and($product->getKeyName())->toBe('uuid')
        ->and($product->getIncrementing())->toBeFalse()
        ->and($subject->getTable())->toBe('catalog_subjects')
        ->and($subject->subject())->toBeInstanceOf(MorphTo::class)
        ->and($subject->catalog())->toBeInstanceOf(BelongsTo::class);
});

test('food truck exposes logistics catalog and ownership relationships', function () {
    expect((new FoodTruck())->store())->toBeInstanceOf(BelongsTo::class)
        ->and((new FoodTruck())->vehicle())->toBeInstanceOf(BelongsTo::class)
        ->and((new FoodTruck())->serviceArea())->toBeInstanceOf(BelongsTo::class)
        ->and((new FoodTruck())->zone())->toBeInstanceOf(BelongsTo::class)
        ->and((new FoodTruck())->catalogAssignments())->toBeInstanceOf(MorphMany::class)
        ->and((new FoodTruck())->catalogs())->toBeInstanceOf(MorphToMany::class);
});

test('food truck derives location and assigned driver from a loaded vehicle', function () {
    $driver  = new Fleetbase\FleetOps\Models\Driver(['name' => 'Morgan Driver']);
    $point   = new Fleetbase\LaravelMysqlSpatial\Types\Point(47.9184, 106.9177);
    $vehicle = new Vehicle();
    $vehicle->setRelation('driver', $driver);
    $vehicle->setAttribute('location', $point);

    $foodTruck = new FoodTruck();
    $foodTruck->setRelation('vehicle', $vehicle);

    expect($foodTruck->location)->toBe($point)
        ->and($foodTruck->getDriverAssigned())->toBe($driver);
});

test('food truck synchronizes catalog assignments and restores soft-deleted matches', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('catalog_subjects');
    $schema->create('catalog_subjects', function ($table) {
        $table->string('uuid')->nullable();
        $table->string('catalog_uuid');
        $table->string('subject_type');
        $table->string('subject_uuid');
        $table->string('company_uuid')->nullable();
        $table->string('created_by_uuid')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });

    $truckUuid  = '40000000-0000-4000-8000-000000000001';
    $removeUuid = '50000000-0000-4000-8000-000000000001';
    $keepUuid   = '50000000-0000-4000-8000-000000000002';
    $addUuid    = '50000000-0000-4000-8000-000000000003';

    $connection->table('catalog_subjects')->insert([
        [
            'uuid'         => '60000000-0000-4000-8000-000000000001',
            'catalog_uuid' => $removeUuid,
            'subject_type' => FoodTruck::class,
            'subject_uuid' => $truckUuid,
            'created_at'   => now(),
            'updated_at'   => now(),
        ],
        [
            'uuid'         => '60000000-0000-4000-8000-000000000002',
            'catalog_uuid' => $keepUuid,
            'subject_type' => FoodTruck::class,
            'subject_uuid' => $truckUuid,
            'created_at'   => now(),
            'updated_at'   => now(),
        ],
    ]);

    $remove = new Catalog(['uuid' => $removeUuid]);
    $keep   = new Catalog(['uuid' => $keepUuid]);
    $truck  = new FoodTruck([
        'uuid'         => $truckUuid,
        'company_uuid' => 'company_uuid',
    ]);
    $truck->setRelation('catalogs', collect([$remove, $keep]));
    session(['user' => 'user_uuid']);

    expect($truck->setCatalogs([$keep, ['uuid' => $addUuid], $addUuid, 'invalid']))->toBe($truck);

    $active = $connection->table('catalog_subjects')
        ->where('subject_uuid', $truckUuid)
        ->whereNull('deleted_at')
        ->orderBy('catalog_uuid')
        ->pluck('catalog_uuid')
        ->all();

    expect($active)->toBe([$keepUuid, $addUuid])
        ->and($connection->table('catalog_subjects')
            ->where('catalog_uuid', $removeUuid)
            ->whereNotNull('deleted_at')
            ->exists())->toBeTrue();
});

test('network exposes media commerce and ownership relationships', function () {
    expect((new Network())->createdBy())->toBeInstanceOf(BelongsTo::class)
        ->and((new Network())->company())->toBeInstanceOf(BelongsTo::class)
        ->and((new Network())->logo())->toBeInstanceOf(BelongsTo::class)
        ->and((new Network())->backdrop())->toBeInstanceOf(BelongsTo::class)
        ->and((new Network())->orderConfig())->toBeInstanceOf(BelongsTo::class)
        ->and((new Network())->files())->toBeInstanceOf(HasMany::class)
        ->and((new Network())->media())->toBeInstanceOf(HasMany::class)
        ->and((new Network())->stores())->toBeInstanceOf(BelongsToMany::class)
        ->and((new Network())->notificationChannels())->toBeInstanceOf(HasMany::class)
        ->and((new Network())->gateways())->toBeInstanceOf(HasMany::class)
        ->and((new Network())->categories())->toBeInstanceOf(HasMany::class);
});

test('network asset accessors use loaded files and stable fallbacks', function () {
    $network = new Network();

    expect($network->logo_url)->toBe('https://flb-assets.s3.ap-southeast-1.amazonaws.com/static/image-file-icon.png')
        ->and($network->backdrop_url)->toBe('https://flb-assets.s3.ap-southeast-1.amazonaws.com/static/default-storefront-backdrop.png');

    $network->setRelation('logo', (object) ['url' => 'https://cdn.example.test/logo.png']);
    $network->setRelation('backdrop', (object) ['url' => 'https://cdn.example.test/backdrop.png']);

    expect($network->logo_url)->toBe('https://cdn.example.test/logo.png')
        ->and($network->backdrop_url)->toBe('https://cdn.example.test/backdrop.png');
});

test('network normalizes checkout minimum options and invalid inputs', function () {
    $network          = new Network();
    $network->options = ['required_checkout_min_amount' => '$1,234.50', 'pickup' => true];

    expect($network->getAttributes()['options'])->toBe([
        'required_checkout_min_amount' => 123450,
        'pickup'                       => true,
    ]);

    $network->options = 'not-json';

    expect($network->getAttributes()['options'])->toBe([]);
});

test('network order config uses direct foreign keys and loaded relations', function () {
    $network = new Network(['order_config_uuid' => 'config_direct']);

    expect($network->getOrderConfigId())->toBe('config_direct');

    $config = new OrderConfig();
    $config->forceFill(['uuid' => 'config_loaded']);

    $network = new Network();
    $network->setRelation('orderConfig', $config);

    expect($network->getOrderConfig())->toBe($config)
        ->and($network->getOrderConfigId())->toBe('config_loaded');
});

test('network slug configuration uses the name and slug columns', function () {
    $options = (new Network())->getSlugOptions();

    expect($options->generateSlugFrom)->toBe(['name'])
        ->and($options->slugField)->toBe('slug');
});

test('supporting storefront models expose their relationship contracts', function () {
    expect((new Fleetbase\Storefront\Models\AddonCategory())->addons())->toBeInstanceOf(HasMany::class)
        ->and((new Fleetbase\Storefront\Models\CatalogHour())->catalog())->toBeInstanceOf(BelongsTo::class)
        ->and((new Fleetbase\Storefront\Models\NetworkStore())->network())->toBeInstanceOf(BelongsTo::class)
        ->and((new Fleetbase\Storefront\Models\NetworkStore())->store())->toBeInstanceOf(BelongsTo::class)
        ->and((new Fleetbase\Storefront\Models\NetworkStore())->category())->toBeInstanceOf(BelongsTo::class)
        ->and((new Fleetbase\Storefront\Models\PaymentMethod())->createdBy())->toBeInstanceOf(BelongsTo::class)
        ->and((new Fleetbase\Storefront\Models\PaymentMethod())->company())->toBeInstanceOf(BelongsTo::class)
        ->and((new Fleetbase\Storefront\Models\PaymentMethod())->owner())->toBeInstanceOf(MorphTo::class)
        ->and((new Fleetbase\Storefront\Models\PaymentMethod())->store())->toBeInstanceOf(BelongsTo::class)
        ->and((new Fleetbase\Storefront\Models\PaymentMethod())->gateway())->toBeInstanceOf(BelongsTo::class)
        ->and((new Fleetbase\Storefront\Models\ProductAddon())->createdBy())->toBeInstanceOf(BelongsTo::class)
        ->and((new Fleetbase\Storefront\Models\ProductAddon())->category())->toBeInstanceOf(BelongsTo::class)
        ->and((new Fleetbase\Storefront\Models\ProductAddon())->store())->toBeInstanceOf(BelongsTo::class)
        ->and((new Fleetbase\Storefront\Models\ProductAddonCategory())->category())->toBeInstanceOf(BelongsTo::class)
        ->and((new Fleetbase\Storefront\Models\ProductAddonCategory())->product())->toBeInstanceOf(BelongsTo::class)
        ->and((new Fleetbase\Storefront\Models\ProductHour())->product())->toBeInstanceOf(BelongsTo::class)
        ->and((new Fleetbase\Storefront\Models\ProductStoreLocation())->product())->toBeInstanceOf(BelongsTo::class)
        ->and((new Fleetbase\Storefront\Models\ProductStoreLocation())->storeLocation())->toBeInstanceOf(BelongsTo::class)
        ->and((new Fleetbase\Storefront\Models\ProductVariant())->createdBy())->toBeInstanceOf(BelongsTo::class)
        ->and((new Fleetbase\Storefront\Models\ProductVariant())->category())->toBeInstanceOf(BelongsTo::class)
        ->and((new Fleetbase\Storefront\Models\ProductVariant())->product())->toBeInstanceOf(BelongsTo::class)
        ->and((new Fleetbase\Storefront\Models\ProductVariant())->options())->toBeInstanceOf(HasMany::class)
        ->and((new Fleetbase\Storefront\Models\ProductVariantOption())->productVariant())->toBeInstanceOf(BelongsTo::class)
        ->and((new Fleetbase\Storefront\Models\StoreHour())->storeLocation())->toBeInstanceOf(BelongsTo::class)
        ->and((new Fleetbase\Storefront\Models\StoreLocation())->createdBy())->toBeInstanceOf(BelongsTo::class)
        ->and((new Fleetbase\Storefront\Models\StoreLocation())->place())->toBeInstanceOf(BelongsTo::class)
        ->and((new Fleetbase\Storefront\Models\StoreLocation())->store())->toBeInstanceOf(BelongsTo::class)
        ->and((new Fleetbase\Storefront\Models\StoreLocation())->hours())->toBeInstanceOf(HasMany::class);
});

test('store locations expose cached addresses and resolved place coordinates', function () {
    $location = new Fleetbase\Storefront\Models\StoreLocation();
    $location->setRelation('place', (object) [
        'address' => '1 Market Street',
    ]);

    $locationWithCoordinates = new class extends Fleetbase\Storefront\Models\StoreLocation {
        public function place()
        {
            return new class {
                public function first(): object
                {
                    return (object) ['location' => 'POINT(106.9 47.9)'];
                }
            };
        }
    };

    expect($location->address)->toBe('1 Market Street')
        ->and($locationWithCoordinates->location)->toBe('POINT(106.9 47.9)');
});

test('review and vote models expose actor media and polymorphic subject relationships', function () {
    $review = new Fleetbase\Storefront\Models\Review();

    expect($review->createdBy())->toBeInstanceOf(BelongsTo::class)
        ->and((new Fleetbase\Storefront\Models\Review())->customer())->toBeInstanceOf(BelongsTo::class)
        ->and((new Fleetbase\Storefront\Models\Review())->votes())->toBeInstanceOf(HasMany::class)
        ->and((new Fleetbase\Storefront\Models\Review())->files())->toBeInstanceOf(HasMany::class)
        ->and((new Fleetbase\Storefront\Models\Review())->photos())->toBeInstanceOf(HasMany::class)
        ->and((new Fleetbase\Storefront\Models\Review())->videos())->toBeInstanceOf(HasMany::class)
        ->and((new Fleetbase\Storefront\Models\Review())->subject())->toBeInstanceOf(MorphTo::class)
        ->and((new Fleetbase\Storefront\Models\Vote())->createdBy())->toBeInstanceOf(BelongsTo::class)
        ->and((new Fleetbase\Storefront\Models\Vote())->customer())->toBeInstanceOf(BelongsTo::class)
        ->and((new Fleetbase\Storefront\Models\Vote())->subject())->toBeInstanceOf(MorphTo::class);
});

test('product pricing option and slug mutators normalize API inputs', function () {
    $addon             = new Fleetbase\Storefront\Models\ProductAddon();
    $addon->price      = '$1,234.50';
    $addon->sale_price = 'USD 999.25';

    expect($addon->getAttributes()['price'])->toBe(123450)
        ->and($addon->getAttributes()['sale_price'])->toBe(99925);

    $option                  = new Fleetbase\Storefront\Models\ProductVariantOption();
    $option->additional_cost = '₮ 12,345';

    expect($option->getAttributes()['additional_cost'])->toBe(12345);

    $addonSlug    = $addon->getSlugOptions();
    $variantSlug  = (new Fleetbase\Storefront\Models\ProductVariant())->getSlugOptions();
    $locationSlug = (new Fleetbase\Storefront\Models\ProductStoreLocation())->getSlugOptions();

    foreach ([$addonSlug, $variantSlug, $locationSlug] as $slug) {
        expect($slug->generateSlugFrom)->toBe(['name'])
            ->and($slug->slugField)->toBe('slug');
    }
});

test('addon category option accessors accept arrays JSON and invalid values', function () {
    $category = new Fleetbase\Storefront\Models\ProductAddonCategory();
    $source   = new Fleetbase\Storefront\Models\AddonCategory();
    $source->forceFill(['name' => 'Sides']);
    $category->setRelation('category', $source);

    expect($category->getExcludedAddonsAttribute(['addon_a']))->toBe(['addon_a'])
        ->and($category->getExcludedAddonsAttribute('["addon_b"]'))->toBe(['addon_b'])
        ->and($category->getExcludedAddonsAttribute('invalid'))->toBe([])
        ->and($category->name)->toBe('Sides');
});

test('creating specialized categories and notification channels applies model invariants', function () {
    $schema = Illuminate\Database\Capsule\Manager::schema('mysql');
    $schema->dropIfExists('categories');
    $schema->create('categories', function (Illuminate\Database\Schema\Blueprint $table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
    });
    $schema->dropIfExists('notification_channels');
    $schema->create('notification_channels', function (Illuminate\Database\Schema\Blueprint $table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('app_key')->nullable();
    });
    Model::setEventDispatcher(new Illuminate\Events\Dispatcher(app()));
    Model::clearBootedModels();

    try {
        $addon   = new Fleetbase\Storefront\Models\AddonCategory();
        $catalog = new CatalogCategory();
        $channel = new Fleetbase\Storefront\Models\NotificationChannel();
        foreach ([$addon, $catalog, $channel] as $model) {
            $fireCreating = new ReflectionMethod($model, 'fireModelEvent');
            $fireCreating->invoke($model, 'creating', false);
        }

        expect($addon->for)->toBe('storefront_product_addon')
            ->and($catalog->for)->toBe('storefront_catalog')
            ->and($channel->app_key)->toStartWith('noty_channel_');
    } finally {
        Model::unsetEventDispatcher();
        Model::clearBootedModels();
    }
});

test('notification channels normalize provider configuration and expose gateway type', function () {
    $channel = new Fleetbase\Storefront\Models\NotificationChannel();
    $channel->setRawAttributes([
        'scheme' => 'apn',
        'config' => json_encode([
            'sandbox'             => true,
            'private_key_content' => "  private key\n",
            'enabled'             => false,
        ]),
    ]);

    expect($channel->createdBy())->toBeInstanceOf(BelongsTo::class)
        ->and((new Fleetbase\Storefront\Models\NotificationChannel())->company())->toBeInstanceOf(BelongsTo::class)
        ->and((new Fleetbase\Storefront\Models\NotificationChannel())->certificate())->toBeInstanceOf(BelongsTo::class)
        ->and((new Fleetbase\Storefront\Models\NotificationChannel())->owner())->toBeInstanceOf(MorphTo::class)
        ->and($channel->is_apn_gateway)->toBeTrue()
        ->and($channel->is_fcm_gateway)->toBeFalse()
        ->and((array) $channel->config)->toBe([
            'private_key_content' => "  private key\n",
            'enabled'             => false,
            'sandbox'             => true,
        ]);

    $channel->config     = ['private_key_content' => "  trimmed key \n", 'sandbox' => false];
    $channel->scheme     = 'fcm';
    $channel->owner_type = 'storefront:store';

    expect(json_decode($channel->getAttributes()['config'], true))->toBe([
        'private_key_content' => 'trimmed key',
        'sandbox'             => false,
    ])->and($channel->getAttributes()['owner_type'])->toBe(
        Fleetbase\FleetOps\Support\Utils::getMutationType('storefront:store')
    )->and($channel->is_apn_gateway)->toBeFalse()
        ->and($channel->is_fcm_gateway)->toBeTrue();
});

test('addon categories create and update their addon rows deterministically', function () {
    $schema = Illuminate\Database\Capsule\Manager::schema('mysql');
    $schema->dropIfExists('product_addons');
    $schema->create('product_addons', function (Illuminate\Database\Schema\Blueprint $table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('created_by_uuid')->nullable();
        $table->string('category_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('description')->nullable();
        $table->text('translations')->nullable();
        $table->integer('price')->default(0);
        $table->integer('sale_price')->default(0);
        $table->boolean('is_on_sale')->nullable();
        $table->string('slug')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    session(['user' => 'user_test']);
    $category = new Fleetbase\Storefront\Models\AddonCategory();
    $category->forceFill(['uuid' => 'category_test']);
    $category->setAddons([[
        'name'         => 'Gift wrap',
        'description'  => 'Wrapped carefully',
        'translations' => ['mn' => 'Бэлгийн боодол'],
        'price'        => '$12.50',
        'sale_price'   => '$10.00',
        'is_on_sale'   => true,
    ]]);

    $created   = Fleetbase\Storefront\Models\ProductAddon::query()->firstOrFail();
    $addonUuid = (string) Illuminate\Support\Str::uuid();
    Illuminate\Database\Capsule\Manager::connection('mysql')
        ->table('product_addons')
        ->where('id', $created->id)
        ->update(['uuid' => $addonUuid]);

    expect($created->category_uuid)->toBe('category_test')
        ->and($created->created_by_uuid)->toBe('user_test')
        ->and($created->price)->toBe(1250)
        ->and($created->sale_price)->toBe(1000);

    $category->setAddons([[
        'uuid'        => $addonUuid,
        'name'        => 'Premium gift wrap',
        'price'       => 2000,
        'sale_price'  => 1500,
        'is_on_sale'  => false,
    ]]);

    $updated = Fleetbase\Storefront\Models\ProductAddon::query()
        ->where('uuid', $addonUuid)
        ->firstOrFail();

    expect($updated->name)->toBe('Premium gift wrap')
        ->and($updated->price)->toBe(2000)
        ->and(Fleetbase\Storefront\Models\ProductAddon::query()->count())->toBe(1);
});

test('gateway model normalizes provider contracts configuration and fallbacks', function () {
    $gateway = new Fleetbase\Storefront\Models\Gateway();
    $gateway->setRawAttributes([
        'type'   => 'Stripe',
        'config' => json_encode([
            'sandbox'    => true,
            'secret_key' => 'secret',
            'enabled'    => false,
        ]),
    ]);

    expect($gateway->createdBy())->toBeInstanceOf(BelongsTo::class)
        ->and((new Fleetbase\Storefront\Models\Gateway())->company())->toBeInstanceOf(BelongsTo::class)
        ->and((new Fleetbase\Storefront\Models\Gateway())->owner())->toBeInstanceOf(MorphTo::class)
        ->and((new Fleetbase\Storefront\Models\Gateway())->logoFile())->toBeInstanceOf(BelongsTo::class)
        ->and($gateway->logo_url)->toBe('https://flb-assets.s3.ap-southeast-1.amazonaws.com/static/image-file-icon.png')
        ->and($gateway->is_stripe_gateway)->toBeTrue()
        ->and($gateway->is_qpay_gateway)->toBeFalse()
        ->and($gateway->isGateway('STRIPE'))->toBeTrue()
        ->and((array) $gateway->config)->toBe([
            'secret_key' => 'secret',
            'enabled'    => false,
            'sandbox'    => true,
        ]);

    $gateway->owner_type = Fleetbase\Storefront\Models\Store::class;

    expect($gateway->getAttributes()['owner_type'])->not->toBeEmpty();

    $cash = Fleetbase\Storefront\Models\Gateway::cash(['sandbox' => true]);

    expect($cash->public_id)->toBe('gateway_cash')
        ->and($cash->code)->toBe('cash')
        ->and($cash->sandbox)->toBeTrue();
});
