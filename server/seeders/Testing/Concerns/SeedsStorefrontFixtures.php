<?php

namespace Fleetbase\Storefront\Seeders\Testing\Concerns;

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\FleetOps\Support\Utils as FleetOpsUtils;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Models\Category;
use Fleetbase\Models\Company;
use Fleetbase\Models\Transaction;
use Fleetbase\Models\TransactionItem;
use Fleetbase\Storefront\Models\AddonCategory;
use Fleetbase\Storefront\Models\Cart;
use Fleetbase\Storefront\Models\Catalog;
use Fleetbase\Storefront\Models\CatalogCategory;
use Fleetbase\Storefront\Models\CatalogProduct;
use Fleetbase\Storefront\Models\Checkout;
use Fleetbase\Storefront\Models\Gateway;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\NetworkStore;
use Fleetbase\Storefront\Models\Product;
use Fleetbase\Storefront\Models\ProductAddon;
use Fleetbase\Storefront\Models\ProductAddonCategory;
use Fleetbase\Storefront\Models\ProductStatus;
use Fleetbase\Storefront\Models\ProductVariant;
use Fleetbase\Storefront\Models\ProductVariantOption;
use Fleetbase\Storefront\Models\Review;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Models\StoreHour;
use Fleetbase\Storefront\Models\StoreLocation;
use Fleetbase\Storefront\Support\Storefront;
use Fleetbase\Support\Utils;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Builders for complete Storefront fixtures: stores, locations, catalogs, gateways,
 * customers and the carts/checkouts/orders that flow from them.
 *
 * Both the single store seeder and the marketplace seeder are thin declarative
 * definitions on top of this trait, so the shape of a "complete store" lives in one
 * place. A store definition looks like:
 *
 * [
 *     'key'         => 'fleetbase-market',          // used to namespace seed ids
 *     'name'        => 'Fleetbase Market',
 *     'description' => '...',
 *     'email'       => '...', 'phone' => '...', 'website' => '...',
 *     'tags'        => ['groceries'],
 *     'currency'    => 'USD', 'timezone' => 'Asia/Singapore', 'pod_method' => 'scan',
 *     'options'     => ['auto_accept_orders' => false, ...],
 *     'gateway'     => 'stripe' | null,
 *     'location'    => ['name' => ..., 'street1' => ..., 'city' => ..., 'country' => 'SG', 'postal_code' => ..., 'lat' => 1.28, 'lng' => 103.85],
 *     'hours'       => ['start' => '08:00', 'end' => '21:00'],
 *     'categories'  => ['produce' => ['name' => 'Fresh Produce', 'description' => '...']],
 *     'addon_categories' => ['gift' => ['name' => ..., 'description' => ..., 'max_selectable' => 2, 'is_required' => false, 'addons' => [['Gift Wrap', 'desc', 350]]]],
 *     'products'    => ['orchard-box' => ['name' => ..., 'description' => ..., 'price' => 2850, 'category' => 'produce', 'tags' => [], 'recommended' => true,
 *                                          'variants' => [['name' => 'Box Size', 'required' => true, 'multiselect' => false, 'options' => [['Small', 0], ['Family', 1200]]]],
 *                                          'addon_categories' => ['gift']]],
 *     'catalog'     => ['name' => ..., 'description' => ..., 'categories' => ['Fresh Picks' => ['orchard-box', 'market-veg']]],
 * ]
 */
trait SeedsStorefrontFixtures
{
    use SeedsTestingData;

    /** @var array<string, Place> dropoff place per seeded customer uuid */
    protected array $customerPlaces = [];

    /*
    |--------------------------------------------------------------------------
    | Purging
    |--------------------------------------------------------------------------
    */

    /**
     * Remove every record this seeder tagged, across both the Storefront and Fleetbase
     * databases. Children without a tag column are removed through their tagged parents.
     */
    protected function purgeStorefrontFixtures(): void
    {
        $storeUuids           = $this->seededUuids(Store::class);
        $networkUuids         = $this->seededUuids(Network::class);
        $productUuids         = $this->seededUuids(Product::class);
        $productVariantUuids  = $this->seededUuids(ProductVariant::class);
        $catalogCategoryUuids = $this->seededUuids(CatalogCategory::class);
        $addonCategoryUuids   = $this->seededUuids(AddonCategory::class);
        $checkoutUuids        = $this->seededUuids(Checkout::class);
        $cartUuids            = $this->seededUuids(Cart::class);
        $orderUuids           = $this->seededUuids(Order::class);
        $transactionUuids     = $this->seededUuids(Transaction::class);
        $customerUuids        = $this->seededUuids(Contact::class);
        $storeLocationUuids   = Schema::connection($this->storefrontConnection())->hasTable('store_locations')
            ? DB::connection($this->storefrontConnection())->table('store_locations')->whereIn('store_uuid', $storeUuids)->pluck('uuid')->all()
            : [];

        // Orders, payments and logistics records
        $this->purgeSeededLedgerJournals($orderUuids);
        $this->deleteFrom($this->fleetbaseConnection(), 'transaction_items', fn ($query) => $query->whereIn('transaction_uuid', $transactionUuids)->orWhereIn('meta->seed', $this->seedNames()));
        $this->purgeModel(Entity::class);
        $this->purgeModel(Order::class);
        $this->purgeModel(ServiceQuote::class);
        $this->purgeModel(Payload::class);
        $this->purgeModel(Transaction::class);

        // Carts and checkouts reference each other, so unlink before deleting
        $this->deleteFrom($this->storefrontConnection(), 'carts', fn ($query) => $query->whereIn('uuid', $cartUuids)->orWhereIn('checkout_uuid', $checkoutUuids));
        $this->deleteFrom($this->storefrontConnection(), 'checkouts', fn ($query) => $query->whereIn('uuid', $checkoutUuids)->orWhereIn('cart_uuid', $cartUuids));

        // Customer generated content
        $this->deleteFrom($this->storefrontConnection(), 'reviews', fn ($query) => $query->whereIn('customer_uuid', $customerUuids)->orWhereIn('subject_uuid', array_merge($storeUuids, $productUuids)));
        $this->deleteFrom($this->storefrontConnection(), 'votes', fn ($query) => $query->whereIn('customer_uuid', $customerUuids)->orWhereIn('subject_uuid', array_merge($storeUuids, $productUuids)));
        $this->purgeModel(Contact::class);
        $this->purgeModel(Place::class);

        // Catalog
        $this->deleteFrom($this->storefrontConnection(), 'product_variant_options', fn ($query) => $query->whereIn('product_variant_uuid', $productVariantUuids)->orWhereIn('meta->seed', $this->seedNames()));
        $this->purgeModel(ProductVariant::class);
        $this->deleteFrom($this->storefrontConnection(), 'product_addon_categories', fn ($query) => $query->whereIn('product_uuid', $productUuids)->orWhereIn('category_uuid', $addonCategoryUuids));
        $this->deleteFrom($this->storefrontConnection(), 'product_addons', fn ($query) => $query->whereIn('category_uuid', $addonCategoryUuids));
        $this->deleteFrom($this->storefrontConnection(), 'product_hours', fn ($query) => $query->whereIn('product_uuid', $productUuids));
        $this->deleteFrom($this->storefrontConnection(), 'catalog_category_products', fn ($query) => $query->whereIn('catalog_category_uuid', $catalogCategoryUuids)->orWhereIn('product_uuid', $productUuids));
        $this->purgeModel(Product::class);
        $this->purgeModel(Catalog::class);
        $this->purgeModel(CatalogCategory::class);
        $this->purgeModel(AddonCategory::class);
        $this->purgeModel(Category::class);

        // Payments
        $this->purgeModel(Gateway::class);
        $this->deleteFrom($this->storefrontConnection(), 'payment_methods', fn ($query) => $query->whereIn('owner_uuid', $customerUuids));

        // Stores, locations and networks
        $this->deleteFrom($this->storefrontConnection(), 'store_hours', fn ($query) => $query->whereIn('store_location_uuid', $storeLocationUuids));
        $this->deleteFrom($this->storefrontConnection(), 'store_locations', fn ($query) => $query->whereIn('store_uuid', $storeUuids));
        $this->deleteFrom($this->storefrontConnection(), 'network_stores', fn ($query) => $query->whereIn('network_uuid', $networkUuids)->orWhereIn('store_uuid', $storeUuids));
        $this->purgeModel(Store::class);
        $this->purgeModel(Network::class);

        $this->customerPlaces = [];
    }

    protected function purgeSeededLedgerJournals(array $orderUuids): void
    {
        if (!Schema::connection($this->fleetbaseConnection())->hasTable('ledger_journals')) {
            return;
        }

        DB::connection($this->fleetbaseConnection())
            ->table('ledger_journals')
            ->where('type', 'storefront_sale')
            ->where(function ($query) use ($orderUuids) {
                $query->whereIn('meta->seed', $this->seedNames());

                if (!empty($orderUuids)) {
                    $query->orWhereIn('meta->order_uuid', $orderUuids);
                }
            })
            ->delete();
    }

    /*
    |--------------------------------------------------------------------------
    | Stores
    |--------------------------------------------------------------------------
    */

    /**
     * Create a complete store from a definition: the store record, its order config,
     * a location with opening hours, product categories, products with variants and
     * addons, a published catalog and (optionally) a Stripe gateway.
     *
     * @return array{store: Store, location: StoreLocation|null, place: Place|null, products: array<string, Product>, gateway: Gateway|null}
     */
    protected function seedStore(Company $company, array $definition): array
    {
        $storeKey = $definition['key'];
        $seedId   = 'store:' . $storeKey;

        $store = $this->createRecord(Store::class, [
            'company_uuid'      => $company->uuid,
            'created_by_uuid'   => session('user'),
            'order_config_uuid' => Storefront::getOrderConfig($company)->uuid,
            'online'            => $definition['online'] ?? true,
            'name'              => $definition['name'],
            'description'       => $definition['description'] ?? null,
            'email'             => $definition['email'] ?? null,
            'phone'             => $definition['phone'] ?? null,
            'website'           => $definition['website'] ?? null,
            'tags'              => $definition['tags'] ?? [],
            'currency'          => $definition['currency'] ?? 'USD',
            'timezone'          => $definition['timezone'] ?? 'Asia/Singapore',
            'pod_method'        => $definition['pod_method'] ?? 'scan',
            'options'           => array_merge([
                'auto_accept_orders' => false,
                'auto_dispatch'      => false,
                'require_pod'        => true,
            ], $definition['options'] ?? []),
            'meta'              => $this->meta($seedId),
        ]);

        $place    = null;
        $location = null;
        if (!empty($definition['location'])) {
            [$place, $location] = $this->createStoreLocation($company, $store, $storeKey, $definition['location'], $definition['hours'] ?? []);
        }

        $gateway = null;
        if (($definition['gateway'] ?? null) === 'stripe') {
            $gateway = $this->createStripeGateway($company, $store, 'storefront:store', 'gateway:' . $storeKey . ':stripe');
        }

        $categories = [];
        foreach ($definition['categories'] ?? [] as $categoryKey => $category) {
            $categories[$categoryKey] = $this->createProductCategory($company, $store, $storeKey, $categoryKey, $category);
        }

        $addonCategories = [];
        foreach ($definition['addon_categories'] ?? [] as $addonKey => $addonCategory) {
            $addonCategories[$addonKey] = $this->createAddonCategory($company, $store, $storeKey, $addonKey, $addonCategory);
        }

        $products = [];
        foreach ($definition['products'] ?? [] as $productKey => $product) {
            $category               = isset($product['category']) ? ($categories[$product['category']] ?? null) : null;
            $products[$productKey]  = $this->createProduct($company, $store, $storeKey, $productKey, $product, $category);

            foreach ($product['variants'] ?? [] as $variant) {
                $this->createVariant($products[$productKey], $storeKey, $productKey, $variant);
            }

            foreach ($product['addon_categories'] ?? [] as $addonKey) {
                if (isset($addonCategories[$addonKey])) {
                    $this->attachAddonCategory($products[$productKey], $addonCategories[$addonKey], $definition['addon_categories'][$addonKey]);
                }
            }
        }

        if (!empty($definition['catalog'])) {
            $this->createCatalog($company, $store, $storeKey, $definition['catalog'], $products);
        }

        return [
            'store'    => $store,
            'location' => $location,
            'place'    => $place,
            'products' => $products,
            'gateway'  => $gateway,
        ];
    }

    /**
     * @return array{0: Place, 1: StoreLocation}
     */
    protected function createStoreLocation(Company $company, Store $store, string $storeKey, array $location, array $hours = []): array
    {
        $seedId = 'store-location:' . $storeKey;

        $place = $this->createRecord(Place::class, [
            '_key'         => $this->fixtureKey($seedId),
            'company_uuid' => $company->uuid,
            'owner_uuid'   => $store->uuid,
            'owner_type'   => Utils::getMutationType('storefront:store'),
            'name'         => $location['name'] ?? $store->name,
            'street1'      => $location['street1'] ?? null,
            'street2'      => $location['street2'] ?? null,
            'city'         => $location['city'] ?? null,
            'province'     => $location['province'] ?? null,
            'postal_code'  => $location['postal_code'] ?? null,
            'country'      => $location['country'] ?? null,
            'type'         => 'storefront',
            'location'     => new Point($location['lat'], $location['lng']),
            'meta'         => $this->meta($seedId),
        ]);

        $storeLocation = $this->createRecord(StoreLocation::class, [
            'store_uuid'      => $store->uuid,
            'created_by_uuid' => session('user'),
            'place_uuid'      => $place->uuid,
            'name'            => $location['name'] ?? $store->name,
        ]);

        $start  = $hours['start'] ?? '08:00';
        $end    = $hours['end'] ?? '21:00';
        $closed = $hours['closed'] ?? [];
        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
            if (in_array($day, $closed, true)) {
                continue;
            }

            $this->createRecord(StoreHour::class, [
                'store_location_uuid' => $storeLocation->uuid,
                'day_of_week'         => $day,
                'start'               => $start,
                'end'                 => $end,
            ]);
        }

        return [$place, $storeLocation];
    }

    /**
     * Create a sandbox Stripe gateway owned by a store or network.
     *
     * Keys come from `SEED_STRIPE_SECRET_KEY` / `SEED_STRIPE_PUBLISHABLE_KEY` when a
     * developer wants to exercise real Stripe test mode; otherwise obvious placeholders
     * are stored so the gateway is selectable and the "missing secret" checkout path
     * can be exercised.
     */
    protected function createStripeGateway(Company $company, Store|Network $owner, string $ownerType, string $seedId): Gateway
    {
        return $this->createRecord(Gateway::class, [
            'company_uuid'    => $company->uuid,
            'created_by_uuid' => session('user'),
            'owner_uuid'      => $owner->uuid,
            'owner_type'      => $ownerType,
            'name'            => 'Stripe',
            'description'     => 'Sandbox Stripe gateway seeded for Storefront testing.',
            'code'            => 'stripe',
            'type'            => 'stripe',
            'sandbox'         => true,
            'config'          => [
                'secret_key'      => env('SEED_STRIPE_SECRET_KEY') ?: 'sk_test_storefront_seed_placeholder',
                'publishable_key' => env('SEED_STRIPE_PUBLISHABLE_KEY') ?: 'pk_test_storefront_seed_placeholder',
            ],
            'return_url'      => null,
            'callback_url'    => null,
            'meta'            => $this->meta($seedId),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Catalog
    |--------------------------------------------------------------------------
    */

    protected function createProductCategory(Company $company, Store $store, string $storeKey, string $categoryKey, array $category): Category
    {
        return $this->createRecord(Category::class, [
            'company_uuid' => $company->uuid,
            'owner_uuid'   => $store->uuid,
            'owner_type'   => Utils::getMutationType('storefront:store'),
            'for'          => 'storefront_product',
            'name'         => $category['name'],
            'description'  => $category['description'] ?? null,
            'icon'         => $category['icon'] ?? null,
            'tags'         => $category['tags'] ?? [],
            'meta'         => $this->meta('category:' . $storeKey . ':' . $categoryKey),
        ]);
    }

    protected function createProduct(Company $company, Store $store, string $storeKey, string $productKey, array $product, ?Category $category): Product
    {
        $salePrice = (int) ($product['sale_price'] ?? 0);

        return $this->createRecord(Product::class, [
            'company_uuid'    => $company->uuid,
            'created_by_uuid' => session('user'),
            'store_uuid'      => $store->uuid,
            'category_uuid'   => $category?->uuid,
            'name'            => $product['name'],
            'description'     => $product['description'] ?? null,
            'tags'            => $product['tags'] ?? [],
            'meta'            => $this->meta('product:' . $storeKey . ':' . $productKey),
            'sku'             => $product['sku'] ?? 'SF-' . Str::upper(Str::slug($storeKey . '-' . $productKey)),
            'price'           => (int) $product['price'],
            'currency'        => $product['currency'] ?? $store->currency,
            'sale_price'      => $salePrice,
            'is_service'      => $product['is_service'] ?? false,
            'is_bookable'     => $product['is_bookable'] ?? false,
            'is_available'    => $product['is_available'] ?? true,
            'is_on_sale'      => $salePrice > 0,
            'is_recommended'  => $product['recommended'] ?? false,
            'can_pickup'      => $product['can_pickup'] ?? true,
            'status'          => $product['status'] ?? ProductStatus::PUBLISHED,
        ]);
    }

    protected function createAddonCategory(Company $company, Store $store, string $storeKey, string $addonKey, array $addonCategory): AddonCategory
    {
        $category = $this->createRecord(AddonCategory::class, [
            'company_uuid' => $company->uuid,
            'owner_uuid'   => $store->uuid,
            'owner_type'   => Utils::getMutationType('storefront:store'),
            'for'          => 'storefront_product_addon',
            'name'         => $addonCategory['name'],
            'description'  => $addonCategory['description'] ?? null,
            'meta'         => $this->meta('addon-category:' . $storeKey . ':' . $addonKey),
        ]);

        foreach ($addonCategory['addons'] ?? [] as [$name, $description, $price]) {
            $this->createRecord(ProductAddon::class, [
                'created_by_uuid' => session('user'),
                'category_uuid'   => $category->uuid,
                'name'            => $name,
                'description'     => $description,
                'price'           => $price,
                'sale_price'      => 0,
                'is_on_sale'      => false,
            ]);
        }

        return $category;
    }

    protected function attachAddonCategory(Product $product, AddonCategory $category, array $addonCategory): ProductAddonCategory
    {
        return $this->createRecord(ProductAddonCategory::class, [
            'product_uuid'    => $product->uuid,
            'category_uuid'   => $category->uuid,
            'excluded_addons' => [],
            'max_selectable'  => $addonCategory['max_selectable'] ?? 1,
            'is_required'     => $addonCategory['is_required'] ?? false,
        ]);
    }

    protected function createVariant(Product $product, string $storeKey, string $productKey, array $variant): ProductVariant
    {
        $required    = $variant['required'] ?? false;
        $multiselect = $variant['multiselect'] ?? false;
        $options     = $variant['options'] ?? [];
        $variantKey  = Str::slug($variant['name']);

        $model = $this->createRecord(ProductVariant::class, [
            'product_uuid'   => $product->uuid,
            'name'           => $variant['name'],
            'description'    => $variant['description'] ?? $variant['name'] . ' options',
            'meta'           => $this->meta('variant:' . $storeKey . ':' . $productKey . ':' . $variantKey),
            'is_required'    => $required,
            'is_multiselect' => $multiselect,
            'min'            => $required ? 1 : 0,
            'max'            => $multiselect ? count($options) : 1,
        ]);

        foreach ($options as [$optionName, $additionalCost]) {
            $this->createRecord(ProductVariantOption::class, [
                'product_variant_uuid' => $model->uuid,
                'name'                 => $optionName,
                'description'          => $optionName,
                'meta'                 => $this->meta('variant-option:' . $storeKey . ':' . $productKey . ':' . $variantKey . ':' . Str::slug($optionName)),
                'additional_cost'      => $additionalCost,
            ]);
        }

        return $model;
    }

    /**
     * @param array<string, Product> $products
     */
    protected function createCatalog(Company $company, Store $store, string $storeKey, array $catalog, array $products): Catalog
    {
        $model = $this->createRecord(Catalog::class, [
            'store_uuid'      => $store->uuid,
            'company_uuid'    => $company->uuid,
            'created_by_uuid' => session('user'),
            'name'            => $catalog['name'],
            'description'     => $catalog['description'] ?? null,
            'status'          => $catalog['status'] ?? 'published',
            'meta'            => $this->meta('catalog:' . $storeKey),
        ]);

        foreach ($catalog['categories'] ?? [] as $categoryName => $productKeys) {
            $category = $this->createRecord(CatalogCategory::class, [
                'company_uuid' => $company->uuid,
                'owner_uuid'   => $model->uuid,
                'owner_type'   => Utils::getMutationType('storefront:catalog'),
                'for'          => 'storefront_catalog',
                'name'         => $categoryName,
                'meta'         => $this->meta('catalog-category:' . $storeKey . ':' . Str::slug($categoryName)),
            ]);

            foreach ($productKeys as $productKey) {
                if (!isset($products[$productKey])) {
                    continue;
                }

                $this->createRecord(CatalogProduct::class, [
                    'catalog_category_uuid' => $category->uuid,
                    'product_uuid'          => $products[$productKey]->uuid,
                ]);
            }
        }

        return $model;
    }

    /*
    |--------------------------------------------------------------------------
    | Networks
    |--------------------------------------------------------------------------
    */

    protected function createNetwork(Company $company, array $definition): Network
    {
        $network = $this->createRecord(Network::class, [
            'company_uuid'      => $company->uuid,
            'created_by_uuid'   => session('user'),
            'order_config_uuid' => Storefront::getOrderConfig($company)->uuid,
            'online'            => $definition['online'] ?? true,
            'name'              => $definition['name'],
            'description'       => $definition['description'] ?? null,
            'email'             => $definition['email'] ?? null,
            'phone'             => $definition['phone'] ?? null,
            'website'           => $definition['website'] ?? null,
            'tags'              => $definition['tags'] ?? [],
            'currency'          => $definition['currency'] ?? 'USD',
            'timezone'          => $definition['timezone'] ?? 'Asia/Singapore',
            'pod_method'        => $definition['pod_method'] ?? 'scan',
            'options'           => array_merge([
                'auto_accept_orders' => false,
                'auto_dispatch'      => false,
                'require_pod'        => true,
            ], $definition['options'] ?? [], $this->meta('network:' . $definition['key'])),
        ]);

        return $network;
    }

    protected function createNetworkCategory(Company $company, Network $network, string $networkKey, string $categoryKey, array $category): Category
    {
        return $this->createRecord(Category::class, [
            'company_uuid' => $company->uuid,
            'owner_uuid'   => $network->uuid,
            'owner_type'   => Utils::getMutationType('storefront:network'),
            'for'          => 'storefront_network',
            'name'         => $category['name'],
            'description'  => $category['description'] ?? null,
            'icon'         => $category['icon'] ?? null,
            'icon_color'   => $category['icon_color'] ?? '#000000',
            'tags'         => $category['tags'] ?? [],
            'meta'         => $this->meta('network-category:' . $networkKey . ':' . $categoryKey),
        ]);
    }

    protected function addStoreToNetwork(Network $network, Store $store, ?Category $category = null): NetworkStore
    {
        return $this->createRecord(NetworkStore::class, [
            'network_uuid'  => $network->uuid,
            'store_uuid'    => $store->uuid,
            'category_uuid' => $category?->uuid,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Customers
    |--------------------------------------------------------------------------
    */

    /**
     * @return Contact[]
     */
    protected function seedCustomers(Company $company, array $fixtures): array
    {
        return array_values(array_map(fn (array $fixture) => $this->createCustomer($company, ...$fixture), $fixtures));
    }

    protected function createCustomer(Company $company, string $name, string $email, string $phone): Contact
    {
        $seedId = 'customer:' . Str::slug($name);

        return $this->createRecord(Contact::class, [
            '_key'         => $this->fixtureKey($seedId),
            'company_uuid' => $company->uuid,
            'name'         => $name,
            'email'        => $email,
            'phone'        => $phone,
            'type'         => 'customer',
            'notes'        => 'Storefront testing customer.',
            'meta'         => $this->meta($seedId),
        ]);
    }

    protected function customerPlace(Company $company, Contact $customer, int $index): Place
    {
        if (isset($this->customerPlaces[$customer->uuid])) {
            return $this->customerPlaces[$customer->uuid];
        }

        $addresses = [
            ['18 Orchard Road', '238826', 1.3048, 103.8318],
            ['5 Rochor Canal Road', '188555', 1.3039, 103.8520],
            ['21 Tampines Central 1', '529538', 1.3525, 103.9447],
            ['88 Marina Bay Drive', '018956', 1.2830, 103.8600],
            ['3 Jurong East Street 21', '609601', 1.3345, 103.7420],
        ];
        [$street, $postal, $lat, $lng] = $addresses[$index % count($addresses)];
        $seedId                        = 'customer-address:' . Str::slug($customer->name);

        return $this->customerPlaces[$customer->uuid] = $this->createRecord(Place::class, [
            '_key'         => $this->fixtureKey($seedId),
            'company_uuid' => $company->uuid,
            'owner_uuid'   => $customer->uuid,
            'owner_type'   => FleetOpsUtils::getMutationType('fleet-ops:contact'),
            'name'         => $customer->name,
            'street1'      => $street,
            'city'         => 'Singapore',
            'postal_code'  => $postal,
            'country'      => 'SG',
            'location'     => new Point($lat, $lng),
            'meta'         => $this->meta($seedId),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Carts, checkouts and orders
    |--------------------------------------------------------------------------
    */

    /**
     * Seed commerce activity for a store: one open cart, one pending checkout and a run
     * of captured orders spread over the last month, alternating cash and Stripe where a
     * Stripe gateway exists, with some pickup orders and a mix of statuses.
     *
     * @param array{store: Store, place: Place|null, products: array<string, Product>, gateway: Gateway|null} $bundle
     * @param Contact[]                                                                                       $customers
     *
     * @return Order[]
     */
    protected function seedStoreActivity(Company $company, array $bundle, array $customers, ?Network $network = null, int $orderCount = 30): array
    {
        /** @var Store $store */
        $store    = $bundle['store'];
        $products = array_values($bundle['products']);
        $storeKey = data_get($store, 'meta.seed_id');
        $storeKey = Str::after($storeKey, 'store:');

        if (empty($products) || empty($customers)) {
            return [];
        }

        $this->createCart($company, $store, $customers[0], [[$products[0], 1], [$products[count($products) - 1], 1]], 'cart:' . $storeKey . ':open', [
            ['code' => 'cart_created', 'message' => 'Testing cart created.', 'created_at' => $this->timestamp(1)->toISOString()],
        ]);

        $this->createPendingCheckout($company, $store, $network, $customers[1 % count($customers)], [[$products[1 % count($products)], 2]], 'checkout:' . $storeKey . ':pending');

        $orders = [];
        for ($i = 1; $i <= $orderCount; $i++) {
            $customerIndex = ($i - 1) % count($customers);
            $customer      = $customers[$customerIndex];
            $first         = $products[($i - 1) % count($products)];
            $second        = $products[$i % count($products)];
            $isPickup      = $i % 5 === 0;
            $useStripe     = $bundle['gateway'] instanceof Gateway && $i % 2 === 0;
            $deliveryFee   = $isPickup ? 0 : 500 + (($i % 4) * 125);
            $daysAgo       = (int) floor(($orderCount - $i) * (30 / max($orderCount, 1)));

            $orders[] = $this->createCompletedOrder($company, $store, $network, $customer, $this->customerPlace($company, $customer, $customerIndex), $bundle['place'], [
                [$first, ($i % 3) + 1],
                [$second, ($i % 2) + 1],
            ], 'order:' . $storeKey . ':' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), [
                'pickup'       => $isPickup,
                'delivery_fee' => $deliveryFee,
                'gateway'      => $useStripe ? $bundle['gateway'] : null,
                'status'       => $this->orderStatus($i, $isPickup),
                'days_ago'     => $daysAgo,
                'hour'         => $i % 10,
            ]);
        }

        $this->seedReviews($store, $products, $customers);

        return $orders;
    }

    protected function orderStatus(int $sequence, bool $pickup): string
    {
        if ($pickup) {
            return $sequence % 2 === 0 ? 'pickup_ready' : 'created';
        }

        return match ($sequence % 6) {
            0       => 'completed',
            1       => 'created',
            2       => 'preparing',
            3       => 'dispatched',
            4       => 'started',
            default => 'ready',
        };
    }

    protected function createCart(Company $company, Store $store, Contact $customer, array $lines, string $seedId, array $events, ?int $daysAgo = null): Cart
    {
        $items = collect($lines)->filter(fn ($line) => $line[0] instanceof Product)->map(function ($line) use ($store) {
            /** @var Product $product */
            [$product, $quantity] = $line;
            $unitPrice            = $product->is_on_sale && (int) $product->sale_price > 0 ? (int) $product->sale_price : (int) $product->price;

            return [
                'id'         => $product->public_id,
                'product_id' => $product->public_id,
                'store_id'   => $store->public_id,
                'name'       => $product->name,
                'sku'        => $product->sku,
                'price'      => $unitPrice,
                'currency'   => $product->currency,
                'quantity'   => (int) $quantity,
                'subtotal'   => $unitPrice * (int) $quantity,
                'variants'   => [],
                'addons'     => [],
            ];
        })->values()->all();

        $attributes = [
            'company_uuid'      => $company->uuid,
            'customer_id'       => $customer->public_id,
            'unique_identifier' => $this->fixtureKey($seedId),
            'currency'          => $store->currency,
            'items'             => $items,
            'events'            => $events,
            'expires_at'        => now()->addDays(14),
        ];

        if ($daysAgo !== null) {
            $attributes['created_at'] = $this->timestamp(0, $daysAgo);
            $attributes['updated_at'] = $this->timestamp(0, $daysAgo);
        }

        return $this->createRecord(Cart::class, $attributes);
    }

    protected function createPendingCheckout(Company $company, Store $store, ?Network $network, Contact $customer, array $lines, string $seedId): Checkout
    {
        $cart = $this->createCart($company, $store, $customer, $lines, $seedId . ':cart', [
            ['code' => 'checkout_initialized', 'message' => 'Testing checkout initialized.', 'created_at' => $this->timestamp(2)->toISOString()],
        ]);

        $checkout = $this->createRecord(Checkout::class, [
            'company_uuid' => $company->uuid,
            'store_uuid'   => $store->uuid,
            'network_uuid' => $network?->uuid,
            'cart_uuid'    => $cart->uuid,
            'owner_uuid'   => $customer->uuid,
            'owner_type'   => FleetOpsUtils::getMutationType('fleet-ops:contact'),
            'amount'       => $cart->subtotal,
            'currency'     => $store->currency,
            'is_cod'       => true,
            'is_pickup'    => false,
            'options'      => $this->meta($seedId),
            'cart_state'   => $cart->items,
            'captured'     => false,
        ]);

        $cart->update(['checkout_uuid' => $checkout->uuid]);

        return $checkout;
    }

    /**
     * Create a captured checkout with its cart, service quote, payload, entities,
     * transaction and order, mirroring what `CheckoutController::captureOrder` writes.
     *
     * @param array{pickup: bool, delivery_fee: int, gateway: Gateway|null, status: string, days_ago: int, hour: int} $options
     */
    protected function createCompletedOrder(Company $company, Store $store, ?Network $network, Contact $customer, Place $dropoff, ?Place $pickupPlace, array $lines, string $seedId, array $options): Order
    {
        $pickup      = (bool) $options['pickup'];
        $deliveryFee = $pickup ? 0 : (int) $options['delivery_fee'];
        $gateway     = $options['gateway'] ?? null;
        $gatewayType = $gateway instanceof Gateway ? $gateway->type : 'cash';
        $isCod       = $gatewayType === 'cash';
        $daysAgo     = (int) ($options['days_ago'] ?? 0);
        $createdAt   = $this->timestamp((int) ($options['hour'] ?? 0), $daysAgo);
        $currency    = $store->currency;
        $timestamps  = ['created_at' => $createdAt, 'updated_at' => $createdAt];

        $cart = $this->createCart($company, $store, $customer, $lines, $seedId . ':cart', [
            ['code' => 'checkout_captured', 'message' => 'Testing checkout captured.', 'created_at' => $createdAt->toISOString()],
        ], $daysAgo);

        $total        = $cart->subtotal + $deliveryFee;
        $pickupPlace  = $pickupPlace ?? $dropoff;
        $serviceQuote = $pickup ? null : $this->createServiceQuote($company, $store, $pickupPlace, $dropoff, $seedId, $deliveryFee, $currency, $timestamps);

        $checkout = $this->createRecord(Checkout::class, array_merge($timestamps, [
            'company_uuid'             => $company->uuid,
            'store_uuid'               => $store->uuid,
            'network_uuid'             => $network?->uuid,
            'cart_uuid'                => $cart->uuid,
            'gateway_uuid'             => $gateway?->uuid,
            'service_quote_uuid'       => $serviceQuote?->uuid,
            'owner_uuid'               => $customer->uuid,
            'owner_type'               => FleetOpsUtils::getMutationType('fleet-ops:contact'),
            'amount'                   => $total,
            'currency'                 => $currency,
            'is_cod'                   => $isCod,
            'is_pickup'                => $pickup,
            'options'                  => $this->meta($seedId),
            'cart_state'               => $cart->items,
            'captured'                 => true,
            'stripe_payment_intent_id' => $gatewayType === 'stripe' ? 'pi_seed_' . md5($this->fixtureKey($seedId)) : null,
        ]));

        $payload = $this->createRecord(Payload::class, array_merge($timestamps, [
            '_key'               => $this->fixtureKey($seedId . ':payload'),
            'company_uuid'       => $company->uuid,
            'pickup_uuid'        => $pickupPlace->uuid,
            'dropoff_uuid'       => $pickup ? $pickupPlace->uuid : $dropoff->uuid,
            'return_uuid'        => $pickupPlace->uuid,
            'payment_method'     => $gatewayType,
            'cod_amount'         => $isCod ? $total : null,
            'cod_currency'       => $isCod ? $currency : null,
            'cod_payment_method' => $isCod ? 'cash' : null,
            'type'               => 'storefront',
            'meta'               => $this->meta($seedId . ':payload'),
        ]));

        if ($serviceQuote) {
            $serviceQuote->update(['payload_uuid' => $payload->uuid]);
        }

        $this->createEntities($company, $payload, $customer, $cart, $pickup ? $pickupPlace : $dropoff, $seedId, $timestamps);

        $transaction = $this->createRecord(Transaction::class, array_merge($timestamps, [
            'company_uuid'           => $company->uuid,
            'customer_uuid'          => $customer->uuid,
            'customer_type'          => FleetOpsUtils::getMutationType('fleet-ops:contact'),
            'gateway_transaction_id' => $gatewayType === 'stripe' ? 'pi_seed_' . md5($this->fixtureKey($seedId)) : 'sf-seed-' . Str::slug($seedId),
            'gateway'                => $gatewayType,
            'amount'                 => $total,
            'net_amount'             => $total,
            'currency'               => $currency,
            'description'            => 'Storefront testing order',
            'type'                   => 'storefront',
            'direction'              => 'credit',
            'status'                 => 'success',
            'meta'                   => $this->meta($seedId . ':transaction', [
                'storefront'    => $store->name,
                'storefront_id' => $store->public_id,
            ]),
        ]));

        $this->createTransactionItems($transaction, $cart, $deliveryFee, $currency, $timestamps);

        $orderMeta = [
            'storefront'    => $store->name,
            'storefront_id' => $store->public_id,
        ];

        if ($network) {
            $orderMeta['storefront_network']    = $network->name;
            $orderMeta['storefront_network_id'] = $network->public_id;
        }

        $order = $this->createRecord(Order::class, array_merge($timestamps, [
            '_key'              => $this->fixtureKey($seedId),
            'company_uuid'      => $company->uuid,
            'payload_uuid'      => $payload->uuid,
            'customer_uuid'     => $customer->uuid,
            'customer_type'     => FleetOpsUtils::getMutationType('fleet-ops:contact'),
            'transaction_uuid'  => $transaction->uuid,
            'order_config_uuid' => $store->getOrderConfigId(),
            'adhoc'             => false,
            'type'              => 'storefront',
            'status'            => $options['status'],
            'meta'              => $this->meta($seedId, array_merge($orderMeta, [
                'checkout_id'  => $checkout->public_id,
                'subtotal'     => $cart->subtotal,
                'delivery_fee' => $deliveryFee,
                'tip'          => null,
                'delivery_tip' => null,
                'total'        => $total,
                'currency'     => $currency,
                'gateway'      => $gatewayType,
                'require_pod'  => (bool) data_get($store, 'options.require_pod', true),
                'pod_method'   => $store->pod_method,
                'is_pickup'    => $pickup,
            ])),
            'notes'             => 'Seeded Storefront testing order.',
        ]));

        // Creating an order runs the FleetOps order flow, which resets the persisted status
        // to `created` (the in-memory model still holds the requested value, so this cannot
        // be skipped by comparing attributes). Re-apply the desired lifecycle status without
        // events so dashboards and listings get a realistic spread of statuses.
        Order::withoutEvents(fn () => DB::connection($order->getConnectionName())
            ->table($order->getTable())
            ->where('uuid', $order->uuid)
            ->update(['status' => $options['status'], 'updated_at' => $createdAt]));
        $order->setRawAttributes(array_merge($order->getAttributes(), ['status' => $options['status']]), true);

        $checkout->update(['order_uuid' => $order->uuid]);
        $cart->update(['checkout_uuid' => $checkout->uuid]);

        return $order;
    }

    protected function createServiceQuote(Company $company, Store $store, Place $origin, Place $destination, string $seedId, int $deliveryFee, string $currency, array $timestamps): ServiceQuote
    {
        return $this->createRecord(ServiceQuote::class, array_merge($timestamps, [
            '_key'         => $this->fixtureKey($seedId . ':service-quote'),
            'company_uuid' => $company->uuid,
            'amount'       => $deliveryFee,
            'currency'     => $currency,
            'meta'         => $this->meta($seedId . ':service-quote', [
                'origin'      => $this->placeSummary($origin, $store->name),
                'destination' => $this->placeSummary($destination, 'Customer Address'),
            ]),
            'expired_at'   => now()->addDays(14),
        ]));
    }

    protected function placeSummary(Place $place, string $fallbackName): array
    {
        $location = $place->location;

        return [
            'name'     => $place->name ?: $fallbackName,
            'street1'  => $place->street1,
            'city'     => $place->city,
            'country'  => $place->country,
            'location' => [
                'latitude'  => $location instanceof Point ? $location->getLat() : null,
                'longitude' => $location instanceof Point ? $location->getLng() : null,
            ],
        ];
    }

    protected function createEntities(Company $company, Payload $payload, Contact $customer, Cart $cart, Place $destination, string $seedId, array $timestamps): void
    {
        foreach ($cart->items as $index => $item) {
            $this->createRecord(Entity::class, array_merge($timestamps, [
                '_key'             => $this->fixtureKey($seedId . ':entity:' . ($index + 1)),
                'company_uuid'     => $company->uuid,
                'payload_uuid'     => $payload->uuid,
                'customer_uuid'    => $customer->uuid,
                'customer_type'    => FleetOpsUtils::getMutationType('fleet-ops:contact'),
                'destination_uuid' => $destination->uuid,
                'internal_id'      => data_get($item, 'product_id'),
                'name'             => data_get($item, 'name'),
                'type'             => 'storefront_product',
                'description'      => 'Storefront testing order item.',
                'currency'         => data_get($item, 'currency'),
                'sku'              => data_get($item, 'sku'),
                'price'            => data_get($item, 'price'),
                'sale_price'       => 0,
                'meta'             => $this->meta($seedId . ':entity:' . ($index + 1), [
                    'product_id'   => data_get($item, 'product_id'),
                    'variants'     => data_get($item, 'variants', []),
                    'addons'       => data_get($item, 'addons', []),
                    'subtotal'     => data_get($item, 'subtotal'),
                    'quantity'     => data_get($item, 'quantity'),
                    'scheduled_at' => data_get($item, 'scheduled_at'),
                ]),
            ]), true);
        }
    }

    protected function createTransactionItems(Transaction $transaction, Cart $cart, int $deliveryFee, string $currency, array $timestamps): void
    {
        foreach ($cart->items as $index => $item) {
            $this->createRecord(TransactionItem::class, array_merge($timestamps, [
                'transaction_uuid' => $transaction->uuid,
                'quantity'         => Arr::get((array) $item, 'quantity', 1),
                'unit_price'       => Arr::get((array) $item, 'price', 0),
                'amount'           => Arr::get((array) $item, 'subtotal', 0),
                'currency'         => $currency,
                'details'          => Arr::get((array) $item, 'name', 'Storefront item'),
                'description'      => Arr::get((array) $item, 'name', 'Storefront item'),
                'code'             => 'product',
                'sort_order'       => $index,
                'meta'             => $this->meta('transaction-item:' . $transaction->uuid . ':' . $index),
            ]));
        }

        if ($deliveryFee > 0) {
            $this->createRecord(TransactionItem::class, array_merge($timestamps, [
                'transaction_uuid' => $transaction->uuid,
                'quantity'         => 1,
                'unit_price'       => $deliveryFee,
                'amount'           => $deliveryFee,
                'currency'         => $currency,
                'details'          => 'Delivery fee',
                'description'      => 'Delivery fee',
                'code'             => 'delivery_fee',
                'sort_order'       => 99,
                'meta'             => $this->meta('transaction-item:' . $transaction->uuid . ':delivery'),
            ]));
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Reviews
    |--------------------------------------------------------------------------
    */

    /**
     * @param Product[] $products
     * @param Contact[] $customers
     */
    protected function seedReviews(Store $store, array $products, array $customers): void
    {
        $comments = [
            [5, 'Delivered fast and everything arrived fresh.'],
            [4, 'Good selection, packaging could be lighter.'],
            [5, 'Pickup was ready before I arrived. Great service.'],
            [3, 'Solid products but delivery took longer than quoted.'],
        ];

        foreach (array_slice($customers, 0, count($comments)) as $index => $customer) {
            [$rating, $content] = $comments[$index];

            $this->createRecord(Review::class, [
                'created_by_uuid' => session('user'),
                'customer_uuid'   => $customer->uuid,
                'subject_uuid'    => $store->uuid,
                'subject_type'    => Utils::getMutationType('storefront:store'),
                'rating'          => $rating,
                'content'         => $content,
                'rejected'        => false,
                'created_at'      => $this->timestamp($index, $index + 1),
            ]);

            $product = $products[$index % count($products)];
            $this->createRecord(Review::class, [
                'created_by_uuid' => session('user'),
                'customer_uuid'   => $customer->uuid,
                'subject_uuid'    => $product->uuid,
                'subject_type'    => Utils::getMutationType('storefront:product'),
                'rating'          => $rating,
                'content'         => $content,
                'rejected'        => false,
                'created_at'      => $this->timestamp($index, $index + 2),
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Reporting
    |--------------------------------------------------------------------------
    */

    protected function reportStorefront(Store|Network $about, ?Gateway $gateway = null, ?string $prefix = null): void
    {
        $label = $about instanceof Store ? 'Store' : 'Network';
        $line  = sprintf('%s%s "%s" id=%s key=%s', $prefix ?? '', $label, $about->name, $about->public_id, $about->key);

        if ($gateway) {
            $line .= sprintf(' gateway=%s (%s)', $gateway->code, $gateway->sandbox ? 'sandbox' : 'live');
        }

        $this->command?->line($line);
    }

    protected function customerFixtures(): array
    {
        return [
            ['Ava Chen', 'ava.chen@example.test', '+65 9100 0201'],
            ['Ben Ortiz', 'ben.ortiz@example.test', '+65 9100 0202'],
            ['Mia Brooks', 'mia.brooks@example.test', '+65 9100 0203'],
            ['Noah Patel', 'noah.patel@example.test', '+65 9100 0204'],
            ['Emma Johnson', 'emma.johnson@example.test', '+65 9100 0205'],
            ['Liam Garcia', 'liam.garcia@example.test', '+65 9100 0206'],
            ['Olivia Smith', 'olivia.smith@example.test', '+65 9100 0207'],
            ['Lucas Brown', 'lucas.brown@example.test', '+65 9100 0208'],
            ['Sophia Davis', 'sophia.davis@example.test', '+65 9100 0209'],
            ['Ethan Wilson', 'ethan.wilson@example.test', '+65 9100 0210'],
            ['Amelia Martinez', 'amelia.martinez@example.test', '+65 9100 0211'],
            ['Mason Lee', 'mason.lee@example.test', '+65 9100 0212'],
            ['Isabella Taylor', 'isabella.taylor@example.test', '+65 9100 0213'],
            ['James Anderson', 'james.anderson@example.test', '+65 9100 0214'],
            ['Charlotte Thomas', 'charlotte.thomas@example.test', '+65 9100 0215'],
            ['Henry Moore', 'henry.moore@example.test', '+65 9100 0216'],
            ['Harper Jackson', 'harper.jackson@example.test', '+65 9100 0217'],
            ['Alexander White', 'alexander.white@example.test', '+65 9100 0218'],
            ['Evelyn Harris', 'evelyn.harris@example.test', '+65 9100 0219'],
            ['Daniel Martin', 'daniel.martin@example.test', '+65 9100 0220'],
        ];
    }
}
