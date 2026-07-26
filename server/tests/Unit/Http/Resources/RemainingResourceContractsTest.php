<?php

use Fleetbase\Storefront\Http\Resources\Cart as CartResource;
use Fleetbase\Storefront\Http\Resources\CatalogProduct as CatalogProductResource;
use Fleetbase\Storefront\Http\Resources\Customer as CustomerResource;
use Fleetbase\Storefront\Http\Resources\FoodTruck as FoodTruckResource;
use Fleetbase\Storefront\Http\Resources\Index\Order as IndexOrderResource;
use Fleetbase\Storefront\Http\Resources\Order as OrderResource;
use Fleetbase\Storefront\Http\Resources\ReviewCustomer as ReviewCustomerResource;
use Fleetbase\Storefront\Http\Resources\Store as StoreResource;
use Fleetbase\Storefront\Http\Resources\StoreHour as StoreHourResource;
use Fleetbase\Storefront\Http\Resources\StoreLocation as StoreLocationResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class StorefrontMetaArrayable implements Illuminate\Contracts\Support\Arrayable
{
    public function __construct(private array $values)
    {
    }

    public function toArray()
    {
        return $this->values;
    }
}

test('cart resource exposes public totals and hides database ownership fields', function () {
    $cart = storefrontResourceModel([
        'id'                 => 7,
        'uuid'               => 'cart_uuid',
        'public_id'          => 'cart_public',
        'company_uuid'       => 'company_uuid',
        'user_uuid'          => 'user_uuid',
        'checkout_uuid'      => 'checkout_uuid',
        'customer_id'        => 'customer_public',
        'currency'           => 'USD',
        'subtotal'           => 4200,
        'total_items'        => 3,
        'total_unique_items' => 2,
        'items'              => [],
        'events'             => [['event' => 'created']],
        'discount_code'      => 'SAVE10',
        'expires_at'         => '2026-07-27 12:00:00',
        'created_at'         => '2026-07-26 12:00:00',
        'updated_at'         => '2026-07-26 12:30:00',
    ]);

    $public = (new CartResource($cart))->resolve(setSimpleStorefrontResourceRoute('v1/storefront/cart'));

    expect($public)->toMatchArray([
        'id'                 => 'cart_public',
        'customer_id'        => 'customer_public',
        'currency'           => 'USD',
        'subtotal'           => 4200,
        'total_items'        => 3,
        'total_unique_items' => 2,
        'items'              => [],
        'discount_code'      => 'SAVE10',
    ])->and($public)->not->toHaveKeys(['uuid', 'company_uuid', 'user_uuid', 'checkout_uuid']);

    $internal = (new CartResource($cart))->resolve(setSimpleStorefrontResourceRoute('int/v1/storefront/cart'));

    expect($internal)->toMatchArray([
        'id'            => 7,
        'uuid'          => 'cart_uuid',
        'public_id'     => 'cart_public',
        'company_uuid'  => 'company_uuid',
        'user_uuid'     => 'user_uuid',
        'checkout_uuid' => 'checkout_uuid',
    ]);
});

test('cart resource enriches known products while preserving unknown line items', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('products');
    $schema->dropIfExists('files');
    $schema->create('products', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id');
        $table->string('primary_image_uuid')->nullable();
        $table->string('name');
        $table->text('description')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('files', function ($table) {
        $table->string('uuid')->primary();
        $table->string('subject_uuid')->nullable();
        $table->string('url')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $connection->table('products')->insert([
        'uuid'               => 'product_uuid',
        'public_id'          => 'product_public',
        'primary_image_uuid' => null,
        'name'               => 'Cold Brew',
        'description'        => 'Ready to drink',
    ]);

    $cart = storefrontResourceModel([
        'public_id' => 'cart_public',
        'items'     => [
            ['product_id' => 'product_public', 'quantity' => 2],
            ['product_id' => 'product_missing', 'quantity' => 1],
        ],
    ]);
    $items = (new CartResource($cart))->getCartItems();

    expect($items[0])->toMatchArray([
        'product_id'        => 'product_public',
        'quantity'          => 2,
        'name'              => 'Cold Brew',
        'description'       => 'Ready to drink',
        'product_image_url' => 'https://flb-assets.s3.ap-southeast-1.amazonaws.com/static/image-file-icon.png',
    ])->and($items[1])->toBe([
        'product_id' => 'product_missing',
        'quantity'   => 1,
    ]);
});

test('customer resource exposes public identity address and loaded address collection', function () {
    $place = storefrontResourceModel([
        'public_id' => 'place_public',
        'address'   => '1 Market Street',
    ]);
    $customer = storefrontResourceModel([
        'id'           => 8,
        'uuid'         => 'contact_uuid',
        'public_id'    => 'contact_public',
        'user_uuid'    => 'user_uuid',
        'company_uuid' => 'company_uuid',
        'place_uuid'   => 'place_uuid',
        'photo_uuid'   => 'photo_uuid',
        'internal_id'  => 'C-100',
        'name'         => 'Ada Buyer',
        'title'        => 'Ms',
        'photo_url'    => 'https://cdn.example.test/ada.png',
        'email'        => 'ada@example.test',
        'phone'        => '+15550100',
        'token'        => 'customer-token',
        'meta'         => ['segment' => 'vip'],
        'slug'         => 'ada-buyer',
        'created_at'   => '2026-07-26',
        'updated_at'   => '2026-07-27',
    ], [
        'place'  => $place,
        'places' => collect([$place]),
    ]);

    $request = setSimpleStorefrontResourceRoute('v1/storefront/customers/contact_public');
    $data    = (new CustomerResource($customer))->resolve($request);

    expect($data)->toMatchArray([
        'id'         => 'customer_public',
        'address_id' => 'place_public',
        'internal_id'=> 'C-100',
        'name'       => 'Ada Buyer',
        'address'    => '1 Market Street',
        'token'      => 'customer-token',
        'orders'     => 0,
        'meta'       => ['segment' => 'vip'],
    ])->and($data['addresses'])->toHaveCount(1)
        ->and($data)->not->toHaveKeys(['uuid', 'user_uuid', 'company_uuid', 'place_uuid']);
});

test('customer resource scopes order counts to the requested store or network', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('orders');
    $schema->create('orders', function ($table) {
        $table->string('uuid')->primary();
        $table->string('customer_uuid');
        $table->text('meta');
        $table->timestamp('deleted_at')->nullable();
    });
    $connection->table('orders')->insert([
        [
            'uuid'          => 'order_store',
            'customer_uuid' => 'contact_uuid',
            'meta'          => json_encode(['storefront_id' => 'store_public']),
        ],
        [
            'uuid'          => 'order_network',
            'customer_uuid' => 'contact_uuid',
            'meta'          => json_encode(['storefront_network_id' => 'network_public']),
        ],
    ]);

    $customer = storefrontResourceModel([
        'uuid'      => 'contact_uuid',
        'public_id' => 'contact_public',
    ], [
        'place'  => null,
        'places' => collect(),
    ]);

    $storeRequest = setSimpleStorefrontResourceRoute('v1/storefront/customers/contact_public');
    $storeRequest->query->set('storefront', 'store_public');
    $networkRequest = Request::create('/v1/storefront/customers/contact_public', 'GET', [
        'network' => 'network_public',
    ]);
    $networkRequest->setLaravelSession(request()->session());
    $networkRequest->setRouteResolver(fn () => new class {
        public array $action = [];

        public function uri(): string
        {
            return 'v1/storefront/customers/contact_public';
        }
    });

    expect((new CustomerResource($customer))->resolve($storeRequest)['orders'])->toBe(1)
        ->and((new CustomerResource($customer))->resolve($networkRequest)['orders'])->toBe(1);
});

test('catalog product resource preserves catalog purchasable shape', function () {
    $request = setStorefrontResourceRoute('v1/storefront/catalogs/catalog_public/products');
    $data    = (new CatalogProductResource(storefrontProductResourceFixture()))->resolve($request);

    expect($data)->toMatchArray([
        'id'           => 'product_123',
        'name'         => 'Cold Brew Kit',
        'price'        => 5000,
        'sale_price'   => 4500,
        'currency'     => 'USD',
        'is_available' => true,
        'status'       => 'active',
    ])->and($data['images']->all())->toBe(['https://cdn.test/product.png'])
        ->and($data['videos']->all())->toBe(['https://cdn.test/product.mp4'])
        ->and($data)->not->toHaveKeys(['uuid', 'company_uuid', 'files', 'type']);
});

test('order resources normalize and restrict nested storefront metadata shapes', function () {
    $detailOrder = new Fleetbase\FleetOps\Models\Order();
    $detailOrder->forceFill([
        'meta' => (object) [
            'storefront' => (object) [
                'id'         => 'store_public',
                'name'       => 'Central Store',
                'logo_url'   => 'https://cdn.test/store.png',
                'is_store'   => true,
                'credential' => 'hidden',
            ],
            'storefront_id' => 'store_public',
            'checkout_id'   => 'checkout_public',
            'secret'        => 'hidden',
        ],
    ]);
    $detailResource = new OrderResource($detailOrder);
    $detailMeta     = (new ReflectionMethod($detailResource, 'storefrontOrderMeta'))->invoke($detailResource);

    $indexOrder = new Fleetbase\FleetOps\Models\Order();
    $indexOrder->forceFill([
        'meta' => new StorefrontMetaArrayable([
            'storefront' => [
                'public_id' => 'network_public',
                'name'      => 'Delivery Network',
                'is_network'=> true,
                'private'   => 'hidden',
            ],
            'storefront_network_id' => 'network_public',
            'currency'              => 'MNT',
            'checkout_id'           => 'excluded_from_index',
        ]),
    ]);
    $indexResource    = new IndexOrderResource($indexOrder);
    $indexMeta        = (new ReflectionMethod($indexResource, 'storefrontOrderMeta'))->invoke($indexResource);
    $detailNormalized = (new ReflectionMethod($detailResource, 'normalizeMeta'))->invoke(
        $detailResource,
        new StorefrontMetaArrayable(['currency' => 'USD'])
    );
    $indexNormalized = (new ReflectionMethod($indexResource, 'normalizeMeta'))->invoke(
        $indexResource,
        (object) ['currency' => 'MNT']
    );

    expect($detailMeta)->toBe([
        'storefront' => [
            'id'       => 'store_public',
            'name'     => 'Central Store',
            'logo_url' => 'https://cdn.test/store.png',
            'is_store' => true,
        ],
        'storefront_id' => 'store_public',
        'checkout_id'   => 'checkout_public',
    ])->and($indexMeta)->toBe([
        'storefront' => [
            'public_id' => 'network_public',
            'name'      => 'Delivery Network',
            'is_network'=> true,
        ],
        'storefront_network_id' => 'network_public',
        'currency'              => 'MNT',
    ])->and($detailNormalized)->toBe(['currency' => 'USD'])
        ->and($indexNormalized)->toBe(['currency' => 'MNT']);
});

test('food truck resource returns safe empty logistics relations and offline state', function () {
    $truck = storefrontResourceModel([
        'id'                => 9,
        'uuid'              => 'truck_uuid',
        'public_id'         => 'food_truck_public',
        'company_uuid'      => 'company_uuid',
        'created_by_uuid'   => 'user_uuid',
        'store_uuid'        => 'store_uuid',
        'service_area_uuid' => null,
        'zone_uuid'         => null,
        'vehicle_uuid'      => null,
        'status'            => 'inactive',
        'created_at'        => '2026-07-26',
        'updated_at'        => '2026-07-27',
    ], [
        'vehicle'    => null,
        'serviceArea'=> null,
        'zone'       => null,
        'catalogs'   => collect(),
    ]);

    $data = (new FoodTruckResource($truck))
        ->resolve(setSimpleStorefrontResourceRoute('v1/storefront/food-trucks'));

    expect($data)->toMatchArray([
        'id'           => 'food_truck_public',
        'vehicle'      => null,
        'service_area' => null,
        'zone'         => null,
        'location'     => null,
        'online'       => false,
        'status'       => 'inactive',
    ])->and($data['catalogs'])->toBeEmpty()
        ->and($data)->not->toHaveKeys(['uuid', 'company_uuid', 'vehicle_uuid']);
});

test('review customer resource reports aggregate review and upload counts', function () {
    $customer = new class extends Model {
        protected $guarded = [];

        public function reviews(): object
        {
            return new class {
                public function count(): int
                {
                    return 4;
                }
            };
        }

        public function reviewUploads(): object
        {
            return new class {
                public function count(): int
                {
                    return 6;
                }
            };
        }
    };
    $customer->forceFill([
        'id'         => 10,
        'uuid'       => 'contact_uuid',
        'public_id'  => 'contact_public',
        'name'       => 'Ada Buyer',
        'email'      => 'ada@example.test',
        'phone'      => '+15550100',
        'photo_url'  => 'https://cdn.example.test/ada.png',
        'slug'       => 'ada-buyer',
        'created_at' => '2026-07-26',
        'updated_at' => '2026-07-27',
    ]);

    $data = (new ReviewCustomerResource($customer))
        ->resolve(setSimpleStorefrontResourceRoute('v1/storefront/review-customers'));

    expect($data)->toMatchArray([
        'id'            => 'customer_public',
        'name'          => 'Ada Buyer',
        'reviews_count' => 4,
        'uploads_count' => 6,
    ])->and($data)->not->toHaveKeys(['uuid', 'public_id']);
});

test('store hour and location resources preserve customer-facing scheduling shapes', function () {
    $hour = storefrontResourceModel([
        'id'          => 11,
        'uuid'        => 'hour_uuid',
        'public_id'   => 'hour_public',
        'day_of_week' => 1,
        'start'       => '09:00',
        'end'         => '17:00',
        'created_at'  => '2026-07-26',
        'updated_at'  => '2026-07-27',
    ]);
    $store    = storefrontResourceModel(['public_id' => 'store_public']);
    $location = storefrontResourceModel([
        'id'         => 12,
        'uuid'       => 'location_uuid',
        'public_id'  => 'location_public',
        'name'       => 'Downtown',
        'created_at' => '2026-07-26',
        'updated_at' => '2026-07-27',
    ], [
        'store' => $store,
        'place' => null,
        'hours' => collect([$hour]),
    ]);

    $request      = setSimpleStorefrontResourceRoute('v1/storefront/store-locations');
    $hourData     = (new StoreHourResource($hour))->resolve($request);
    $locationData = (new StoreLocationResource($location))->resolve($request);

    expect($hourData)->toBe([
        'id'    => 'hour_public',
        'day'   => 1,
        'start' => '09:00',
        'end'   => '17:00',
    ])->and($locationData)->toMatchArray([
        'id'    => 'location_public',
        'store' => 'store_public',
        'name'  => 'Downtown',
        'place' => null,
    ])->and($locationData['hours'])->toHaveCount(1)
        ->and($locationData)->not->toHaveKeys(['uuid', 'public_id', 'store_data']);
});

test('store resource exposes public branding and filters internal option state', function () {
    $store = new class extends Model {
        protected $guarded = [];

        public function getNetworkCategoryUsingId(): mixed
        {
            return null;
        }
    };
    $store->forceFill([
        'id'           => 13,
        'uuid'         => 'store_uuid',
        'public_id'    => 'store_public',
        'key'          => 'store_secret',
        'company_uuid' => 'company_uuid',
        'name'         => 'Corner Store',
        'description'  => 'Neighborhood groceries',
        'currency'     => 'USD',
        'options'      => ['pickup' => true, 'alerted_for_new_order' => true],
        'logo_url'     => 'https://cdn.example.test/logo.png',
        'backdrop_url' => 'https://cdn.example.test/backdrop.png',
        'rating'       => 4.8,
        'online'       => true,
        'alertable'    => ['email'],
        'slug'         => 'corner-store',
    ]);
    foreach (['networks', 'locations', 'media'] as $relation) {
        $store->setRelation($relation, collect());
    }

    $data = (new StoreResource($store))
        ->resolve(setSimpleStorefrontResourceRoute('v1/storefront/stores/store_public'));

    expect($data)->toMatchArray([
        'id'          => 'store_public',
        'name'        => 'Corner Store',
        'description' => 'Neighborhood groceries',
        'currency'    => 'USD',
        'country'     => 'AS',
        'options'     => ['pickup' => true],
        'is_network'  => false,
        'is_store'    => true,
    ])->and($data)->not->toHaveKeys(['uuid', 'key', 'company_uuid']);
});
