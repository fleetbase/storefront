<?php

use Fleetbase\Storefront\Http\Resources\Catalog as CatalogResource;
use Fleetbase\Storefront\Http\Resources\CatalogCategory as CatalogCategoryResource;
use Fleetbase\Storefront\Http\Resources\Category as CategoryResource;
use Fleetbase\Storefront\Http\Resources\Gateway as GatewayResource;
use Fleetbase\Storefront\Http\Resources\Media as MediaResource;
use Fleetbase\Storefront\Http\Resources\Network as NetworkResource;
use Fleetbase\Storefront\Http\Resources\NotificationChannel as NotificationChannelResource;
use Fleetbase\Storefront\Http\Resources\Review as ReviewResource;
use Fleetbase\Storefront\Http\Resources\Store as StoreResource;
use Illuminate\Database\Eloquent\Model;

function storefrontResourceModel(array $attributes = [], array $relations = []): Model
{
    $model = new class extends Model {
        protected $guarded = [];
    };
    $model->forceFill($attributes);

    foreach ($relations as $name => $relation) {
        $model->setRelation($name, $relation);
    }

    return $model;
}

function setSimpleStorefrontResourceRoute(string $uri): Illuminate\Http\Request
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

test('gateway resource hides configuration publicly and exposes it internally', function () {
    $gateway = storefrontResourceModel([
        'id'           => 1,
        'uuid'         => 'gateway_uuid',
        'public_id'    => 'gateway_123',
        'owner_uuid'   => 'store_uuid',
        'name'         => 'Stripe',
        'description'  => 'Card payments',
        'logo_url'     => 'https://cdn.test/stripe.png',
        'code'         => 'stripe',
        'type'         => 'payment',
        'sandbox'      => true,
        'return_url'   => 'https://store.test/return',
        'callback_url' => 'https://store.test/callback',
        'meta'         => ['provider' => 'stripe'],
        'config'       => ['secret_key' => 'must-not-leak'],
        'created_at'   => '2026-01-01',
        'updated_at'   => '2026-01-02',
    ]);

    $publicRequest = setSimpleStorefrontResourceRoute('v1/storefront/gateways');
    $public        = (new GatewayResource($gateway))->resolve($publicRequest);

    expect($public)->toMatchArray([
        'id'      => 'gateway_123',
        'name'    => 'Stripe',
        'code'    => 'stripe',
        'sandbox' => true,
    ])->and($public)->not->toHaveKeys(['uuid', 'owner_uuid', 'config']);

    $internalRequest = setSimpleStorefrontResourceRoute('int/v1/storefront/gateways');
    $internal        = (new GatewayResource($gateway))->resolve($internalRequest);

    expect($internal)->toMatchArray([
        'id'         => 1,
        'uuid'       => 'gateway_uuid',
        'public_id'  => 'gateway_123',
        'owner_uuid' => 'store_uuid',
        'config'     => ['secret_key' => 'must-not-leak'],
    ]);
});

test('notification channel resource preserves delivery scheme and internal ownership', function () {
    $channel = storefrontResourceModel([
        'id'               => 2,
        'uuid'             => 'channel_uuid',
        'public_id'        => 'channel_123',
        'company_uuid'     => 'company_uuid',
        'created_by_uuid'  => 'user_uuid',
        'certificate_uuid' => 'certificate_uuid',
        'owner_uuid'       => 'store_uuid',
        'owner_type'       => 'store',
        'name'             => 'Mobile push',
        'scheme'           => 'fcm',
        'options'          => ['topic' => 'orders'],
        'config'           => ['project' => 'storefront'],
        'app_key'          => 'app-key',
        'is_apn_gateway'   => false,
        'is_fcm_gateway'   => true,
        'created_at'       => '2026-01-01',
        'updated_at'       => '2026-01-02',
    ]);

    $public = (new NotificationChannelResource($channel))
        ->resolve(setSimpleStorefrontResourceRoute('v1/storefront/notification-channels'));

    expect($public)->toMatchArray([
        'id'             => 'channel_123',
        'name'           => 'Mobile push',
        'scheme'         => 'fcm',
        'is_fcm_gateway' => true,
    ])->and($public)->not->toHaveKeys(['uuid', 'company_uuid', 'owner_uuid']);

    $internal = (new NotificationChannelResource($channel))
        ->resolve(setSimpleStorefrontResourceRoute('int/v1/storefront/notification-channels'));

    expect($internal)->toMatchArray([
        'id'           => 2,
        'uuid'         => 'channel_uuid',
        'public_id'    => 'channel_123',
        'company_uuid' => 'company_uuid',
        'owner_uuid'   => 'store_uuid',
    ]);
});

test('media and review resources map customer-facing file shapes', function () {
    $photo = storefrontResourceModel([
        'id'                => 3,
        'uuid'              => 'file_uuid',
        'public_id'         => 'file_123',
        'original_filename' => 'receipt.jpg',
        'content_type'      => 'image/jpeg',
        'caption'           => 'Delivered order',
        'url'               => 'https://cdn.test/receipt.jpg',
        'created_at'        => '2026-01-01',
        'updated_at'        => '2026-01-02',
    ]);

    $publicMedia = (new MediaResource($photo))
        ->resolve(setSimpleStorefrontResourceRoute('v1/storefront/media'));

    expect($publicMedia)->toBe([
        'id'       => 'file_123',
        'filename' => 'receipt.jpg',
        'type'     => 'image/jpeg',
        'caption'  => 'Delivered order',
        'url'      => 'https://cdn.test/receipt.jpg',
    ]);

    $customer = storefrontResourceModel([
        'public_id' => 'contact_123',
        'name'      => 'Ada Lovelace',
    ]);
    $review = storefrontResourceModel([
        'id'         => 4,
        'uuid'       => 'review_uuid',
        'public_id'  => 'review_123',
        'rating'     => 5,
        'content'    => 'Excellent service',
        'slug'       => 'excellent-service',
        'created_at' => '2026-01-01',
        'updated_at' => '2026-01-02',
    ], [
        'subject'  => storefrontResourceModel(['id' => 99]),
        'customer' => $customer,
        'photos'   => collect([$photo]),
    ]);

    $reviewData = (new ReviewResource($review))->toArray(setSimpleStorefrontResourceRoute('v1/storefront/reviews'));

    expect($reviewData)->toMatchArray([
        'id'         => 'review_123',
        'subject_id' => 99,
        'rating'     => 5,
        'content'    => 'Excellent service',
    ])->and($reviewData['photos'][0])->toBe([
        'id'       => 'file_123',
        'filename' => 'receipt.jpg',
        'type'     => 'image/jpeg',
        'caption'  => 'Delivered order',
        'url'      => 'https://cdn.test/receipt.jpg',
    ]);
});

test('catalog and category resources expose nested commerce navigation', function () {
    $product         = storefrontResourceModel(['public_id' => 'product_123']);
    $catalogCategory = storefrontResourceModel([
        'id'           => 5,
        'uuid'         => 'catalog_category_uuid',
        'public_id'    => 'catalog_category_123',
        'company_uuid' => 'company_uuid',
        'parent_uuid'  => null,
        'store_uuid'   => 'store_uuid',
        'owner_uuid'   => 'catalog_uuid',
        'name'         => 'Coffee',
        'description'  => 'Coffee products',
        'icon_url'     => 'https://cdn.test/coffee.png',
        'tags'         => ['drinks'],
        'meta'         => ['featured' => true],
        'for'          => 'catalog',
        'order'        => 1,
        'created_at'   => '2026-01-01',
        'updated_at'   => '2026-01-02',
    ], [
        'products' => collect([$product]),
    ]);
    $catalog = storefrontResourceModel([
        'id'              => 6,
        'uuid'            => 'catalog_uuid',
        'public_id'       => 'catalog_123',
        'company_uuid'    => 'company_uuid',
        'created_by_uuid' => 'user_uuid',
        'store_uuid'      => 'store_uuid',
        'name'            => 'Main Menu',
        'description'     => 'Available products',
        'status'          => 'published',
        'created_at'      => '2026-01-01',
        'updated_at'      => '2026-01-02',
    ], [
        'categories' => collect([$catalogCategory]),
    ]);

    $request = setSimpleStorefrontResourceRoute('v1/storefront/catalogs');
    $data    = (new CatalogResource($catalog))->resolve($request);

    expect($data)->toMatchArray([
        'id'          => 'catalog_123',
        'name'        => 'Main Menu',
        'description' => 'Available products',
        'status'      => 'published',
    ])->and($data)->not->toHaveKeys(['uuid', 'company_uuid', 'store_uuid'])
        ->and($data['categories'])->toHaveCount(1);

    $categoryData = (new CatalogCategoryResource($catalogCategory))->resolve($request);

    expect($categoryData)->toMatchArray([
        'id'          => 'catalog_category_123',
        'name'        => 'Coffee',
        'description' => 'Coffee products',
        'tags'        => ['drinks'],
        'meta'        => ['featured' => true],
        'order'       => 1,
    ])->and($categoryData['products'])->toHaveCount(1);
});

test('category resource controls optional products and nested categories', function () {
    $child = storefrontResourceModel([
        'public_id'   => 'category_child',
        'name'        => 'Child',
        'description' => 'Nested category',
        'tags'        => [],
        'translations'=> [],
        'meta'        => [],
        'order'       => 2,
        'slug'        => 'child',
    ], [
        'parentCategory' => null,
        'products'       => collect(),
        'subCategories'  => collect(),
    ]);
    $category = storefrontResourceModel([
        'uuid'        => 'category_uuid',
        'public_id'   => 'category_parent',
        'name'        => 'Parent',
        'description' => 'Top category',
        'icon_url'    => 'https://cdn.test/category.png',
        'tags'        => ['featured'],
        'translations'=> [],
        'meta'        => ['color' => 'blue'],
        'order'       => 1,
        'slug'        => 'parent',
    ], [
        'parentCategory' => storefrontResourceModel(['public_id' => 'category_root']),
        'products'       => collect([storefrontResourceModel(['public_id' => 'product_123'])]),
        'subCategories'  => collect([$child]),
    ]);

    $request = setSimpleStorefrontResourceRoute('v1/storefront/categories');
    $request->query->set('with', ['products', 'subcategories']);
    $data = (new CategoryResource($category))->resolve($request);

    expect($data)->toMatchArray([
        'id'          => 'category_parent',
        'name'        => 'Parent',
        'description' => 'Top category',
        'tags'        => ['featured'],
        'meta'        => ['color' => 'blue'],
        'order'       => 1,
        'parent'      => 'category_root',
    ])->and($data['products'])->toHaveCount(1)
        ->and($data['subcategories'])->toHaveCount(1);
});

test('store options remove internal alert state while preserving storefront configuration', function () {
    $resource = new StoreResource(storefrontResourceModel());

    expect($resource->formatOptions(null))->toBe([])
        ->and($resource->formatOptions('invalid'))->toBe([])
        ->and($resource->formatOptions([
            'alerted_for_new_order' => true,
            'show_tax'              => true,
            'theme'                 => 'dark',
        ]))->toBe([
            'show_tax' => true,
            'theme'    => 'dark',
        ]);
});

test('network resource includes requested related collections and storefront flags', function () {
    $network = storefrontResourceModel([
        'id'                => 7,
        'uuid'              => 'network_uuid',
        'public_id'         => 'network_123',
        'key'               => 'network-key',
        'company_uuid'      => 'company_uuid',
        'created_by_uuid'   => 'user_uuid',
        'logo_uuid'         => 'logo_uuid',
        'backdrop_uuid'     => 'backdrop_uuid',
        'order_config_uuid' => 'order_config_uuid',
        'name'              => 'Merchant Network',
        'description'       => 'Shared marketplace',
        'translations'      => [],
        'website'           => 'https://network.test',
        'facebook'          => null,
        'instagram'         => null,
        'twitter'           => null,
        'email'             => 'network@example.test',
        'phone'             => '+1 555 0100',
        'tags'              => ['marketplace'],
        'currency'          => 'USD',
        'options'           => ['pickup' => true],
        'alertable'         => true,
        'logo_url'          => 'https://cdn.test/logo.png',
        'backdrop_url'      => 'https://cdn.test/backdrop.png',
        'rating'            => 4.8,
        'online'            => true,
        'slug'              => 'merchant-network',
    ], [
        'stores'               => collect(),
        'categories'           => collect(),
        'gateways'             => collect(),
        'notificationChannels' => collect(),
    ]);

    $request = setSimpleStorefrontResourceRoute('v1/storefront/networks');
    $request->query->replace([
        'with_stores'     => true,
        'with_categories' => true,
    ]);
    $data = (new NetworkResource($network))->resolve($request);

    expect($data)->toMatchArray([
        'id'         => 'network_123',
        'name'       => 'Merchant Network',
        'currency'   => 'USD',
        'is_network' => true,
        'is_store'   => false,
    ])->and($data)->toHaveKeys(['stores', 'categories'])
        ->and($data)->not->toHaveKeys(['gateways', 'notification_channels', 'uuid']);
});
