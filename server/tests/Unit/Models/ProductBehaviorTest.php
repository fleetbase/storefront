<?php

use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\Storefront\Models\Product;
use Fleetbase\Storefront\Models\ProductAddonCategory;
use Fleetbase\Storefront\Models\ProductVariant;
use Fleetbase\Storefront\Models\ProductVariantOption;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Collection;
use Milon\Barcode\Facades\DNS2DFacade as DNS2D;

function createProductMutationSchema(): void
{
    $schema = Capsule::schema('mysql');
    foreach (['product_variant_options', 'product_variants', 'product_addon_categories'] as $table) {
        $schema->dropIfExists($table);
    }
    $schema->create('product_addon_categories', function (Blueprint $table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('product_uuid');
        $table->string('category_uuid')->nullable();
        $table->text('excluded_addons')->nullable();
        $table->integer('max_selectable')->nullable();
        $table->boolean('is_required')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('product_variants', function (Blueprint $table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('product_uuid')->nullable();
        $table->string('name')->nullable();
        $table->text('description')->nullable();
        $table->text('translations')->nullable();
        $table->text('meta')->nullable();
        $table->boolean('is_multiselect')->nullable();
        $table->boolean('is_required')->nullable();
        $table->integer('min')->nullable();
        $table->integer('max')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('product_variant_options', function (Blueprint $table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('product_variant_uuid')->nullable();
        $table->string('name')->nullable();
        $table->text('description')->nullable();
        $table->text('translations')->nullable();
        $table->text('meta')->nullable();
        $table->integer('additional_cost')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
}

function createProductNetworkSearchSchema(): void
{
    $schema = Capsule::schema('mysql');
    foreach (['network_stores', 'networks', 'products', 'stores'] as $table) {
        $schema->dropIfExists($table);
    }
    $schema->create('stores', function (Blueprint $table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('name')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('networks', function (Blueprint $table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
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
    $schema->create('products', function (Blueprint $table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('store_uuid')->nullable();
        $table->string('name')->nullable();
        $table->text('description')->nullable();
        $table->text('tags')->nullable();
        $table->boolean('is_available')->default(true);
        $table->string('status')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
}

test('product creation generates both barcode representations', function () {
    $schema = Capsule::schema('mysql');
    $schema->dropIfExists('products');
    $schema->create('products', function (Blueprint $table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
    });
    DNS2D::swap(new class {
        public function getBarcodePNG(string $value, string $type): string
        {
            return $type . ':' . $value;
        }
    });
    Product::setEventDispatcher(new Dispatcher(app()));
    Product::clearBootedModels();

    $product = new Product();
    $product->forceFill(['uuid' => 'product_uuid']);
    $fireCreating = new ReflectionMethod($product, 'fireModelEvent');
    $fireCreating->invoke($product, 'creating', false);

    expect($product->qr_code)->toBe('QRCODE:product_uuid')
        ->and($product->barcode)->toBe('PDF417:product_uuid');

    Product::unsetEventDispatcher();
    Product::clearBootedModels();
});

test('product normalizes money metadata and slug configuration', function () {
    $product = new Product();
    $product->forceFill([
        'meta' => [
            'preparationTime' => '15 minutes',
            'dietary_note'    => 'Vegan',
        ],
    ]);
    $product->price      = '1,250';
    $product->sale_price = '$900';

    expect($product->price)->toBe(1250)
        ->and($product->sale_price)->toBe(900)
        ->and($product->meta_array)->toBe([
            [
                'key'   => 'preparation_time',
                'label' => 'PreparationTime',
                'value' => '15 minutes',
            ],
            [
                'key'   => 'dietary_note',
                'label' => 'Dietary Note',
                'value' => 'Vegan',
            ],
        ])
        ->and($product->getSlugOptions()->generateSlugFrom)->toBe(['name'])
        ->and($product->getSlugOptions()->slugField)->toBe('slug');

    $product->forceFill(['meta' => []]);

    expect($product->meta_array)->toBe([]);
});

test('product primary image chooses primary secondary and fallback sources', function () {
    $product = new Product();
    $product->setRelation('primaryImage', null);
    $product->setRelation('files', new Collection());

    expect($product->primary_image_url)->toBe('https://flb-assets.s3.ap-southeast-1.amazonaws.com/static/image-file-icon.png');

    $secondary = new class(['url' => 'https://cdn.example.test/secondary.png']) extends Model {
        protected $guarded = [];
    };
    $product->setRelation('files', new Collection([$secondary]));

    expect($product->primary_image_url)->toBe('https://cdn.example.test/secondary.png');

    $primary = new class(['url' => 'https://cdn.example.test/primary.png']) extends Model {
        protected $guarded = [];
    };
    $product->setRelation('primaryImage', $primary);

    expect($product->primary_image_url)->toBe('https://cdn.example.test/primary.png');
});

test('product converts commerce attributes into a Fleet-Ops entity contract', function () {
    session(['company' => 'company_uuid']);
    $product = new Product();
    $product->forceFill([
        'public_id'         => 'product_public',
        'primary_image_uuid'=> 'file_uuid',
        'name'              => 'Coffee',
        'description'       => 'Fresh coffee',
        'currency'          => 'USD',
        'sku'               => 'COF-1',
        'price'             => 1200,
        'sale_price'        => 1000,
    ]);
    $product->setRelation('primaryImage', null);
    $product->setRelation('files', new Collection());

    $entity = $product->toEntity([
        'weight' => 2,
        'meta'   => ['fragile' => true],
    ]);

    expect($entity)->toBeInstanceOf(Entity::class)
        ->and($entity->company_uuid)->toBe('company_uuid')
        ->and($entity->internal_id)->toBe('product_public')
        ->and($entity->name)->toBe('Coffee')
        ->and($entity->weight)->toBe(2)
        ->and(data_get($entity->meta, 'product_id'))->toBe('product_public')
        ->and(data_get($entity->meta, 'fragile'))->toBeTrue();
});

test('product exposes commerce relationship contracts', function () {
    $product = new Product();

    expect($product->createdBy())->toBeInstanceOf(BelongsTo::class)
        ->and($product->category())->toBeInstanceOf(BelongsTo::class)
        ->and($product->addonCategories())->toBeInstanceOf(HasMany::class)
        ->and($product->variants())->toBeInstanceOf(HasMany::class)
        ->and($product->primaryImage())->toBeInstanceOf(BelongsTo::class)
        ->and($product->files())->toBeInstanceOf(HasMany::class)
        ->and($product->reviews())->toBeInstanceOf(HasMany::class)
        ->and($product->votes())->toBeInstanceOf(HasMany::class)
        ->and($product->hours())->toBeInstanceOf(HasMany::class)
        ->and($product->store())->toBeInstanceOf(BelongsTo::class)
        ->and($product->catalogCategories())->toBeInstanceOf(BelongsToMany::class);
});

test('product addon categories update existing assignments and create new ones', function () {
    createProductMutationSchema();
    $existingUuid = '3f7d6df9-a4ba-42d5-8cf4-0e568d4fdca9';
    Capsule::connection('mysql')->table('product_addon_categories')->insert([
        'uuid'            => $existingUuid,
        'product_uuid'    => 'product_uuid',
        'category_uuid'   => 'old_category',
        'excluded_addons' => '[]',
        'max_selectable'  => 1,
        'is_required'     => false,
    ]);
    $product = new Product();
    $product->forceFill(['uuid' => 'product_uuid']);

    expect($product->setAddonCategories([
        [
            'uuid'            => $existingUuid,
            'category_uuid'   => 'updated_category',
            'excluded_addons' => ['addon_blocked'],
            'max_selectable'  => 2,
            'is_required'     => true,
        ],
        [
            'category_uuid'   => 'new_category',
            'excluded_addons' => [],
            'max_selectable'  => 3,
            'is_required'     => false,
        ],
    ]))->toBe($product);

    $existing = ProductAddonCategory::where('uuid', $existingUuid)->firstOrFail();
    $created  = ProductAddonCategory::where('category_uuid', 'new_category')->firstOrFail();

    expect($existing->category_uuid)->toBe('updated_category')
        ->and($existing->excluded_addons)->toBe(['addon_blocked'])
        ->and($existing->max_selectable)->toBe(2)
        ->and($existing->is_required)->toBeTrue()
        ->and($created->product_uuid)->toBe('product_uuid')
        ->and($created->max_selectable)->toBe(3);
});

test('product variants normalize null option costs while updating and creating nested options', function () {
    createProductMutationSchema();
    session(['user' => 'user_uuid', 'company' => 'company_uuid']);
    $variantUuid       = '6f6d167d-11f3-4e2f-995d-8307b6134960';
    $optionUuid        = '4d3d0949-8383-4bbf-a720-047717d76125';
    $createdVariantUuid= 'cb3453fc-f25c-4c50-a79e-7d9fffbfd39f';
    Capsule::connection('mysql')->table('product_variants')->insert([
        'uuid'         => $variantUuid,
        'product_uuid' => 'product_uuid',
        'name'         => 'Size',
    ]);
    Capsule::connection('mysql')->table('product_variant_options')->insert([
        'uuid'                 => $optionUuid,
        'product_variant_uuid' => $variantUuid,
        'name'                 => 'Small',
        'additional_cost'      => 100,
    ]);

    ProductVariant::setEventDispatcher(new Dispatcher(app()));
    app()->instance('responsecache', new class {
        public function clear(): void
        {
        }
    });
    ProductVariant::creating(function (ProductVariant $variant) use ($createdVariantUuid) {
        $variant->uuid = $createdVariantUuid;
    });
    $product = new Product();
    $product->forceFill(['uuid' => 'product_uuid']);

    expect($product->setProductVariants([
        [
            'uuid'           => $variantUuid,
            'name'           => 'Package Size',
            'is_multiselect' => false,
            'is_required'    => true,
            'options'        => [
                [
                    'uuid'            => $optionUuid,
                    'name'            => 'Small Updated',
                    'additional_cost' => null,
                ],
                [
                    'name'            => 'Large',
                    'additional_cost' => 500,
                ],
            ],
        ],
        [
            'name'           => 'Temperature',
            'is_multiselect' => false,
            'is_required'    => false,
            'options'        => [
                ['name' => 'Cold', 'additional_cost' => 250],
            ],
        ],
    ]))->toBe($product);

    $updatedOption = ProductVariantOption::where('uuid', $optionUuid)->firstOrFail();
    $newOption     = ProductVariantOption::where('name', 'Large')->firstOrFail();
    $createdOption = ProductVariantOption::where('name', 'Cold')->firstOrFail();

    expect($updatedOption->additional_cost)->toBe(0)
        ->and($newOption->product_variant_uuid)->toBe($variantUuid)
        ->and($createdOption->product_variant_uuid)->toBe($createdVariantUuid);

    ProductVariant::unsetEventDispatcher();
    ProductVariant::clearBootedModels();
});

test('product network search enforces network store availability status and limit filters', function () {
    createProductNetworkSearchSchema();
    $connection = Capsule::connection('mysql');
    $connection->table('stores')->insert([
        ['uuid' => 'store_one_uuid', 'public_id' => 'store_one', 'name' => 'One'],
        ['uuid' => 'store_two_uuid', 'public_id' => 'store_two', 'name' => 'Two'],
    ]);
    $connection->table('networks')->insert([
        ['uuid' => 'network_one', 'public_id' => 'network_public_one'],
        ['uuid' => 'network_two', 'public_id' => 'network_public_two'],
    ]);
    $connection->table('network_stores')->insert([
        ['network_uuid' => 'network_one', 'store_uuid' => 'store_one_uuid'],
        ['network_uuid' => 'network_two', 'store_uuid' => 'store_two_uuid'],
    ]);
    $connection->table('products')->insert([
        ['uuid' => 'product_one', 'public_id' => 'product_one', 'store_uuid' => 'store_one_uuid', 'name' => 'Fresh Coffee', 'description' => 'Arabica', 'is_available' => 1, 'status' => 'published'],
        ['uuid' => 'product_two', 'public_id' => 'product_two', 'store_uuid' => 'store_one_uuid', 'name' => 'Fresh Tea', 'description' => 'Green', 'is_available' => 1, 'status' => 'published'],
        ['uuid' => 'product_hidden', 'public_id' => 'product_hidden', 'store_uuid' => 'store_one_uuid', 'name' => 'Fresh Hidden', 'description' => 'Draft', 'is_available' => 1, 'status' => 'draft'],
        ['uuid' => 'product_other', 'public_id' => 'product_other', 'store_uuid' => 'store_two_uuid', 'name' => 'Fresh Coffee', 'description' => 'Other network', 'is_available' => 1, 'status' => 'published'],
    ]);
    session(['storefront_network' => 'network_one']);

    $all     = Product::findFromNetwork('Fresh');
    $limited = Product::findFromNetwork('Fresh', 'store_one', 1);

    expect($all->pluck('public_id')->all())->toBe(['product_one', 'product_two'])
        ->and($limited)->toHaveCount(1)
        ->and($limited->first()->store_uuid)->toBe('store_one_uuid');
});
