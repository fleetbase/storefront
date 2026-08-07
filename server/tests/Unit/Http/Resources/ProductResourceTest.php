<?php

use Fleetbase\Storefront\Http\Resources\Product as ProductResource;
use Fleetbase\Storefront\Models\Product as ProductModel;
use Illuminate\Http\Request;

function setStorefrontResourceRoute(string $uri): Request
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

function storefrontProductResourceFixture(): ProductModel
{
    $image = (object) [
        'content_type' => 'image/png',
        'url'          => 'https://cdn.test/product.png',
    ];
    $video = (object) [
        'content_type' => 'video/mp4',
        'url'          => 'https://cdn.test/product.mp4',
    ];
    $addon = (object) [
        'id'          => 30,
        'uuid'        => 'addon_uuid',
        'public_id'   => 'addon_123',
        'name'        => 'Gift wrap',
        'description' => 'Premium wrapping',
        'price'       => 250,
        'sale_price'  => 200,
        'is_on_sale'  => true,
        'slug'        => 'gift-wrap',
        'created_at'  => '2026-01-01',
        'updated_at'  => '2026-01-02',
    ];
    $addonCategory = (object) [
        'id'              => 20,
        'uuid'            => 'product_addon_category_uuid',
        'product_uuid'    => 'product_uuid',
        'category_uuid'   => 'addon_category_uuid',
        'name'            => 'Packaging',
        'excluded_addons' => [],
        'category'        => (object) [
            'public_id'  => 'addon_category_123',
            'description'=> 'Packaging choices',
            'addons'     => collect([$addon]),
        ],
        'created_at' => '2026-01-01',
        'updated_at' => '2026-01-02',
    ];
    $variantOption = (object) [
        'id'              => 50,
        'uuid'            => 'variant_option_uuid',
        'public_id'       => 'variant_option_123',
        'name'            => 'Large',
        'description'     => 'Large size',
        'additional_cost' => 150,
        'created_at'      => '2026-01-01',
        'updated_at'      => '2026-01-02',
    ];
    $variant = (object) [
        'id'             => 40,
        'uuid'           => 'variant_uuid',
        'public_id'      => 'variant_123',
        'name'           => 'Size',
        'description'    => 'Choose a size',
        'is_multiselect' => false,
        'is_required'    => true,
        'slug'           => 'size',
        'options'        => collect([$variantOption]),
        'created_at'     => '2026-01-01',
        'updated_at'     => '2026-01-02',
    ];

    $product = new ProductModel();
    $product->forceFill([
        'id'                 => 10,
        'uuid'               => 'product_uuid',
        'public_id'          => 'product_123',
        'company_uuid'       => 'company_uuid',
        'store_uuid'         => 'store_uuid',
        'category_uuid'      => 'category_uuid',
        'created_by_uuid'    => 'user_uuid',
        'primary_image_uuid' => 'image_uuid',
        'name'               => 'Cold Brew Kit',
        'description'        => 'Brew coffee at home',
        'sku'                => 'CBK-1',
        'price'              => 5000,
        'sale_price'         => 4500,
        'currency'           => 'USD',
        'is_on_sale'         => true,
        'is_recommended'     => true,
        'is_service'         => false,
        'is_bookable'        => false,
        'is_available'       => true,
        'tags'               => ['coffee'],
        'status'             => 'active',
        'meta'               => ['origin' => 'SG'],
        'slug'               => 'cold-brew-kit',
        'translations'       => ['mn' => ['name' => 'Cold Brew']],
        'youtube_urls'       => ['https://youtube.test/demo'],
        'created_at'         => '2026-01-01',
        'updated_at'         => '2026-01-02',
    ]);
    $product->setRelation('category', (object) ['public_id' => 'category_123']);
    $product->setRelation('primaryImage', $image);
    $product->setRelation('addonCategories', collect([$addonCategory]));
    $product->setRelation('variants', collect([$variant]));
    $product->setRelation('files', collect([$image, $video]));
    $product->setRelation('hours', collect([
        [
            'id'          => 60,
            'uuid'        => 'hour_uuid',
            'day_of_week' => 1,
            'start'       => '09:00',
            'end'         => '17:00',
        ],
    ]));

    return $product;
}

test('public product resource exposes purchasable shape and filters media types', function () {
    $request = setStorefrontResourceRoute('v1/storefront/products/product_123');
    $data    = (new ProductResource(storefrontProductResourceFixture()))->resolve($request);

    expect($data)->toMatchArray([
        'id'                => 'product_123',
        'name'              => 'Cold Brew Kit',
        'price'             => 5000,
        'sale_price'        => 4500,
        'currency'          => 'USD',
        'is_on_sale'        => true,
        'is_available'      => true,
        'youtube_urls'      => ['https://youtube.test/demo'],
    ])->and($data['images']->all())->toBe(['https://cdn.test/product.png'])
        ->and($data['videos']->all())->toBe(['https://cdn.test/product.mp4'])
        ->and($data)->not->toHaveKeys([
            'uuid',
            'company_uuid',
            'store_uuid',
            'files',
            'type',
        ])->and($data['addon_categories'][0])->toMatchArray([
            'id'          => 'addon_category_123',
            'name'        => 'Packaging',
            'description' => 'Packaging choices',
        ])->and($data['addon_categories'][0]['addons'][0])->toMatchArray([
            'id'   => 'addon_123',
            'name' => 'Gift wrap',
        ])->and($data['variants'][0])->toMatchArray([
            'id'   => 'variant_123',
            'name' => 'Size',
        ])->and($data['variants'][0]['options'][0])->toMatchArray([
            'id'              => 'variant_option_123',
            'additional_cost' => 150,
        ])->and($data['hours'][0])->toMatchArray([
            'day'   => 1,
            'start' => '09:00',
            'end'   => '17:00',
        ]);
});

test('internal product resource includes database identities and raw files', function () {
    $request = setStorefrontResourceRoute('int/v1/storefront/products/product_123');
    $data    = (new ProductResource(storefrontProductResourceFixture()))->resolve($request);

    expect($data)->toMatchArray([
        'id'           => 10,
        'uuid'         => 'product_uuid',
        'public_id'    => 'product_123',
        'company_uuid' => 'company_uuid',
        'store_uuid'   => 'store_uuid',
        'type'         => 'product',
    ])->and($data)->toHaveKey('files')
        ->and($data)->not->toHaveKeys(['images', 'videos'])
        ->and($data['addon_categories'][0])->toMatchArray([
            'id'            => 20,
            'uuid'          => 'product_addon_category_uuid',
            'product_uuid'  => 'product_uuid',
            'category_uuid' => 'addon_category_uuid',
            'public_id'     => 'addon_category_123',
        ])->and($data['addon_categories'][0])->not->toHaveKey('addons')
        ->and($data['variants'][0])->toMatchArray([
            'id'        => 40,
            'uuid'      => 'variant_uuid',
            'public_id' => 'variant_123',
        ])->and($data['variants'][0]['options'][0])->toMatchArray([
            'id'        => 50,
            'uuid'      => 'variant_option_uuid',
            'public_id' => 'variant_option_123',
        ])->and($data['hours'][0])->toMatchArray([
            'id'   => 60,
            'uuid' => 'hour_uuid',
        ]);
});

test('product mapping helpers accept arrays collections exclusions and empty inputs', function () {
    $resource = new ProductResource((object) []);
    $fixture  = storefrontProductResourceFixture();

    setStorefrontResourceRoute('v1/storefront/products');

    expect($resource->mapHours([]))->toBe([])
        ->and($resource->mapHours([[
            'day_of_week' => 2,
            'start'       => '10:00',
            'end'         => '18:00',
        ]]))->toBe([[
            'day_of_week' => 2,
            'start'       => '10:00',
            'end'         => '18:00',
            'day'         => 2,
        ]])->and($resource->mapFiles([]))->toBeInstanceOf(Illuminate\Support\Collection::class)
        ->and($resource->mapFiles($fixture->files, 'audio'))->toBeEmpty()
        ->and($resource->mapAddonCategories([]))->toBeEmpty()
        ->and($resource->mapVariants([]))->toBeEmpty();

    $addons = $fixture->addonCategories[0]->category->addons;

    expect($resource->mapProductAddons($addons, ['addon_uuid']))->toBeEmpty()
        ->and($resource->mapProductAddons($addons, 'not-an-array'))->toHaveCount(1);

    setStorefrontResourceRoute('int/v1/storefront/products');
    $internalAddon = $resource->mapProductAddons($addons)->first();

    expect($internalAddon)->toMatchArray([
        'id'        => 30,
        'uuid'      => 'addon_uuid',
        'public_id' => 'addon_123',
        'name'      => 'Gift wrap',
    ]);
});
