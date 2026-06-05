<?php

namespace Fleetbase\Storefront\Seeders\Testing;

use Fleetbase\Models\Category;
use Fleetbase\Models\Company;
use Fleetbase\Storefront\Models\AddonCategory;
use Fleetbase\Storefront\Models\Catalog;
use Fleetbase\Storefront\Models\CatalogCategory;
use Fleetbase\Storefront\Models\CatalogProduct;
use Fleetbase\Storefront\Models\Product;
use Fleetbase\Storefront\Models\ProductAddon;
use Fleetbase\Storefront\Models\ProductAddonCategory;
use Fleetbase\Storefront\Models\ProductStatus;
use Fleetbase\Storefront\Models\ProductVariant;
use Fleetbase\Storefront\Models\ProductVariantOption;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Seeders\Testing\Concerns\SeedsTestingData;
use Fleetbase\Storefront\Support\Storefront;
use Fleetbase\Support\Utils;
use Illuminate\Database\Seeder;

class CatalogAndProductsSeeder extends Seeder
{
    use SeedsTestingData;

    public function run(): void
    {
        $company = $this->prepareCompany();
        if (!$company) {
            return;
        }

        $this->seedStorefront($company);
    }

    public function purgeSeedData(): void
    {
        $storeUuids           = $this->seededUuids(Store::class);
        $productUuids         = $this->seededUuids(Product::class);
        $productVariantUuids  = $this->seededUuids(ProductVariant::class);
        $catalogCategoryUuids = $this->seededUuids(CatalogCategory::class);
        $addonCategoryUuids   = $this->seededUuids(AddonCategory::class);

        $this->deleteFrom($this->storefrontConnection(), 'product_variant_options', fn ($query) => $query->whereIn('product_variant_uuid', $productVariantUuids)->orWhere('meta->seed', static::SEED_NAME));
        $this->purgeModel(ProductVariant::class);
        $this->deleteFrom($this->storefrontConnection(), 'product_addon_categories', fn ($query) => $query->whereIn('product_uuid', $productUuids)->orWhereIn('category_uuid', $addonCategoryUuids));
        $this->deleteFrom($this->storefrontConnection(), 'product_addons', fn ($query) => $query->whereIn('category_uuid', $addonCategoryUuids));
        $this->deleteFrom($this->storefrontConnection(), 'catalog_category_products', fn ($query) => $query->whereIn('catalog_category_uuid', $catalogCategoryUuids)->orWhereIn('product_uuid', $productUuids));
        $this->purgeModel(Product::class);
        $this->purgeModel(Catalog::class);
        $this->purgeModel(CatalogCategory::class);
        $this->purgeModel(AddonCategory::class);
        $this->deleteFrom($this->storefrontConnection(), 'store_locations', fn ($query) => $query->whereIn('store_uuid', $storeUuids));
        $this->purgeModel(Store::class);
    }

    protected function seedStorefront(Company $company): void
    {
        $userUuid     = session('user');
        $orderConfig  = Storefront::createStorefrontConfig($company);
        $store        = $this->createRecord(Store::class, [
            'company_uuid'      => $company->uuid,
            'created_by_uuid'   => $userUuid,
            'order_config_uuid' => $orderConfig->uuid,
            'online'            => true,
            'name'              => 'Fleetbase Market',
            'description'       => 'Local demo storefront for testing commerce-to-logistics workflows.',
            'email'             => 'market@example.test',
            'phone'             => '+1 555 0100',
            'website'           => 'https://example.test/fleetbase-market',
            'tags'              => ['demo', 'groceries', 'local'],
            'currency'          => 'USD',
            'timezone'          => 'Asia/Singapore',
            'pod_method'        => 'scan',
            'options'           => [
                'auto_accept_orders' => false,
                'auto_dispatch'      => false,
                'require_pod'        => true,
            ],
            'meta'              => $this->meta('store:fleetbase-market'),
        ]);

        $categories = [
            'produce' => $this->createStoreCategory($company, $store, 'Fresh Produce', 'Fruit and vegetables for local delivery.'),
            'pantry'  => $this->createStoreCategory($company, $store, 'Pantry Staples', 'Shelf-stable goods and household essentials.'),
        ];

        $products = [
            'orchard-box' => $this->createProduct($company, $store, $categories['produce'], 'Orchard Fruit Box', 'Seasonal fruit selection packed for same-day delivery.', 2850, ['fruit', 'fresh'], true),
            'market-veg'  => $this->createProduct($company, $store, $categories['produce'], 'Market Vegetable Bundle', 'Weekly vegetable bundle with leafy greens and root vegetables.', 2250, ['vegetables', 'bundle'], false),
            'coffee-kit'  => $this->createProduct($company, $store, $categories['pantry'], 'Cold Brew Starter Kit', 'Coffee, filters, and syrup for storefront pickup or delivery.', 3400, ['coffee', 'beverage'], true),
            'rice-pack'   => $this->createProduct($company, $store, $categories['pantry'], 'Jasmine Rice Pack', 'Premium jasmine rice in a delivery-friendly pack.', 1890, ['rice', 'pantry'], false),
        ];

        $addonCategory = $this->createAddonCategory($company, 'Gift Options', 'Optional packaging and notes for demo products.');
        $giftWrap      = $this->createAddon($addonCategory, 'Gift Wrap', 'Reusable kraft gift wrap.', 350);
        $noteCard      = $this->createAddon($addonCategory, 'Note Card', 'Handwritten note card.', 150);

        foreach ([$products['orchard-box'], $products['coffee-kit']] as $product) {
            $this->createRecord(ProductAddonCategory::class, [
                'product_uuid'     => $product->uuid,
                'category_uuid'    => $addonCategory->uuid,
                'excluded_addons'  => [],
                'max_selectable'   => 2,
                'is_required'      => false,
            ]);
        }

        $this->createVariant($products['orchard-box'], 'Box Size', true, false, [
            ['Small', 0],
            ['Family', 1200],
        ]);
        $this->createVariant($products['coffee-kit'], 'Grind', true, false, [
            ['Whole Bean', 0],
            ['Coarse Ground', 0],
        ]);

        $catalog = $this->createRecord(Catalog::class, [
            'store_uuid'       => $store->uuid,
            'company_uuid'     => $company->uuid,
            'created_by_uuid'  => $userUuid,
            'name'             => 'Everyday Delivery Catalog',
            'description'      => 'Demo catalog containing products used by Storefront local QA.',
            'status'           => 'published',
            'meta'             => $this->meta('catalog:everyday-delivery'),
        ]);

        $this->createCatalogCategory($company, $catalog, 'Fresh Picks', [$products['orchard-box'], $products['market-veg']]);
        $this->createCatalogCategory($company, $catalog, 'Pantry', [$products['coffee-kit'], $products['rice-pack']]);

        unset($giftWrap, $noteCard);
    }

    protected function createStoreCategory(Company $company, Store $store, string $name, string $description): Category
    {
        return $this->createRecord(Category::class, [
            'company_uuid' => $company->uuid,
            'owner_uuid'   => $store->uuid,
            'owner_type'   => Utils::getMutationType('storefront:store'),
            'for'          => 'storefront_product',
            'name'         => $name,
            'description'  => $description,
            'meta'         => $this->meta('category:' . str($name)->slug()),
        ]);
    }

    protected function createProduct(Company $company, Store $store, Category $category, string $name, string $description, int $price, array $tags, bool $recommended): Product
    {
        return $this->createRecord(Product::class, [
            'company_uuid'    => $company->uuid,
            'created_by_uuid' => session('user'),
            'store_uuid'      => $store->uuid,
            'category_uuid'   => $category->uuid,
            'name'            => $name,
            'description'     => $description,
            'tags'            => $tags,
            'meta'            => $this->meta('product:' . str($name)->slug()),
            'sku'             => 'SF-DEMO-' . strtoupper(str($name)->slug('-')),
            'price'           => $price,
            'currency'        => 'USD',
            'sale_price'      => 0,
            'is_service'      => false,
            'is_bookable'     => false,
            'is_available'    => true,
            'is_on_sale'      => false,
            'is_recommended'  => $recommended,
            'can_pickup'      => true,
            'status'          => ProductStatus::PUBLISHED,
        ]);
    }

    protected function createAddonCategory(Company $company, string $name, string $description): AddonCategory
    {
        return $this->createRecord(AddonCategory::class, [
            'company_uuid' => $company->uuid,
            'for'          => 'storefront_product_addon',
            'name'         => $name,
            'description'  => $description,
            'meta'         => $this->meta('addon-category:' . str($name)->slug()),
        ]);
    }

    protected function createAddon(AddonCategory $category, string $name, string $description, int $price): ProductAddon
    {
        return $this->createRecord(ProductAddon::class, [
            'created_by_uuid' => session('user'),
            'category_uuid'   => $category->uuid,
            'name'            => $name,
            'description'     => $description,
            'price'           => $price,
            'sale_price'      => 0,
            'is_on_sale'      => false,
        ]);
    }

    protected function createVariant(Product $product, string $name, bool $required, bool $multiselect, array $options): ProductVariant
    {
        $variant = $this->createRecord(ProductVariant::class, [
            'product_uuid'    => $product->uuid,
            'name'            => $name,
            'description'     => $name . ' options',
            'meta'            => $this->meta('variant:' . str($product->name . '-' . $name)->slug()),
            'is_required'     => $required,
            'is_multiselect'  => $multiselect,
            'min'             => $required ? 1 : 0,
            'max'             => $multiselect ? count($options) : 1,
        ]);

        foreach ($options as [$optionName, $additionalCost]) {
            $this->createRecord(ProductVariantOption::class, [
                'product_variant_uuid' => $variant->uuid,
                'name'                 => $optionName,
                'description'          => $optionName,
                'meta'                 => $this->meta('variant-option:' . str($product->name . '-' . $name . '-' . $optionName)->slug()),
                'additional_cost'      => $additionalCost,
            ]);
        }

        return $variant;
    }

    protected function createCatalogCategory(Company $company, Catalog $catalog, string $name, array $products): CatalogCategory
    {
        $category = $this->createRecord(CatalogCategory::class, [
            'company_uuid' => $company->uuid,
            'owner_uuid'   => $catalog->uuid,
            'owner_type'   => Utils::getMutationType('storefront:catalog'),
            'for'          => 'storefront_catalog',
            'name'         => $name,
            'meta'         => $this->meta('catalog-category:' . str($name)->slug()),
        ]);

        foreach ($products as $product) {
            $this->createRecord(CatalogProduct::class, [
                'catalog_category_uuid' => $category->uuid,
                'product_uuid'          => $product->uuid,
            ]);
        }

        return $category;
    }
}
