<?php

namespace Fleetbase\Storefront\Seeders\Testing;

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\FleetOps\Models\ServiceRate;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Zone;
use Fleetbase\FleetOps\Support\Utils as FleetOpsUtils;
use Fleetbase\LaravelMysqlSpatial\Eloquent\SpatialExpression;
use Fleetbase\LaravelMysqlSpatial\Types\LineString;
use Fleetbase\LaravelMysqlSpatial\Types\MultiPolygon;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\LaravelMysqlSpatial\Types\Polygon;
use Fleetbase\Models\Company;
use Fleetbase\Storefront\Models\Catalog;
use Fleetbase\Storefront\Models\CatalogSubject;
use Fleetbase\Storefront\Models\FoodTruck;
use Fleetbase\Storefront\Models\Gateway;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Seeders\Testing\Concerns\SeedsStorefrontFixtures;
use Fleetbase\Storefront\Support\Storefront;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds a Mongolian marketplace for testing the app in Mongolian, in tögrög and with QPay.
 *
 * Produces a network in Ulaanbaatar (MNT, Asia/Ulaanbaatar) with a sandbox QPay gateway
 * and multi-store carts, network categories, and three member stores with Mongolian
 * catalogs: a supermarket (with its own QPay gateway), a buuz restaurant that runs food
 * trucks (with its own QPay gateway) and a salon selling bookable services. Each store can
 * also be used on its own with its store key.
 *
 * Food trucks: an Ulaanbaatar service area with three zones, and three trucks owned by the
 * restaurant, each a Fleet-Ops vehicle with a position and its own menu:
 *   - live in the Central zone (where the test customers live),
 *   - live in the Zaisan zone (a different zone, so it can't deliver to the customers),
 *   - offline in the Bayanzürkh zone.
 *
 * QPay uses the public sandbox merchant unless SEED_QPAY_* are set. Customers have
 * Mongolian phone numbers and addresses in the Central zone.
 *
 * Re-running the seeder purges and recreates its own fixtures only, keeping the network and
 * store keys so apps built against them keep working.
 *
 *   php artisan db:seed --class="Fleetbase\\Storefront\\Seeders\\Testing\\MongoliaSeeder"
 *
 * Optional environment:
 *   SEED_COMPANY_UUID / SEED_COMPANY_PUBLIC_ID   company to seed into (defaults to the oldest company)
 *   SEED_QPAY_USERNAME / SEED_QPAY_PASSWORD      QPay merchant (defaults to the public sandbox merchant)
 *   SEED_QPAY_INVOICE_ID / SEED_QPAY_EBARIMT_INVOICE_ID
 */
class MongoliaSeeder extends Seeder
{
    use SeedsStorefrontFixtures;

    public const NETWORK_KEY = 'ulaanbaatar-market';
    public const TRUCK_STORE = 'khaan-buuz';

    /** Central Ulaanbaatar: Sükhbaatar Square. */
    protected const CENTER = [47.9189, 106.9176];

    protected function seedName(): string
    {
        return 'storefront-testing-mongolia';
    }

    /**
     * Only this seeder's records: the legacy seed name belongs to the original combined
     * seeder, which the other testing seeders clean up.
     */
    protected function seedNames(): array
    {
        return [$this->seedName()];
    }

    protected function seedCurrency(): string
    {
        return 'MNT';
    }

    protected function seedTimezone(): string
    {
        return 'Asia/Ulaanbaatar';
    }

    public function run(): void
    {
        $company = $this->prepareCompany();
        if (!$company) {
            return;
        }

        $this->withoutForeignKeyConstraints(fn () => $this->purgeSeedData());

        $network = $this->createNetwork($company, $this->networkDefinition());
        $this->createMongoliaDeliveryRate($company);
        $gateway = $this->createQPayGateway($company, $network, 'storefront:network', 'gateway:' . static::NETWORK_KEY . ':qpay');

        $categories = [];
        foreach ($this->networkCategories() as $categoryKey => $category) {
            $categories[$categoryKey] = $this->createNetworkCategory($company, $network, static::NETWORK_KEY, $categoryKey, $category);
        }

        $customers  = $this->seedCustomers($company, $this->customerFixtures());
        $orderCount = 0;
        $bundles    = [];

        foreach ($this->storeDefinitions() as $definition) {
            $bundle = $this->seedStore($company, $definition);
            if (($definition['gateway'] ?? null) === 'qpay') {
                $bundle['gateway'] = $this->createQPayGateway($company, $bundle['store'], 'storefront:store', 'gateway:' . $definition['key'] . ':qpay');
            }

            $this->addStoreToNetwork($network, $bundle['store'], $categories[$definition['network_category']] ?? null);

            $orders = $this->seedStoreActivity($company, $bundle, $customers, $network, $definition['order_count'] ?? 6);
            $orderCount += count($orders);
            $bundles[$definition['key']] = $bundle;
        }

        $marketing = $this->seedMarketing($company, $network, static::NETWORK_KEY, $this->marketingDefinition(), $bundles);
        foreach ($this->storeMarketingDefinitions() as $storeKey => $definition) {
            if (isset($bundles[$storeKey])) {
                foreach ($this->seedMarketing($company, $bundles[$storeKey]['store'], $storeKey, $definition, $bundles) as $kind => $records) {
                    $marketing[$kind] = array_merge($marketing[$kind], array_values($records));
                }
            }
        }

        [$serviceArea, $zones] = $this->seedServiceArea($company);
        $trucks                = $this->seedFoodTrucks($company, $bundles[static::TRUCK_STORE], $serviceArea, $zones);

        $this->command?->info(sprintf('Seeded the Mongolian testing network for company %s with %d stores, %d food trucks and %d orders.', $company->public_id, count($bundles), count($trucks), $orderCount));
        $this->command?->info(sprintf('  Marketing: %d promotions, %d segments, %d campaigns.', count($marketing['promotions']), count($marketing['segments']), count($marketing['campaigns'])));
        $this->reportStorefront($network, $gateway, '  ');
        foreach ($bundles as $bundle) {
            $this->reportStorefront($bundle['store'], $bundle['gateway'], '    ');
        }
        foreach ($trucks as $truck) {
            $this->command?->line(sprintf('      Food truck %s "%s" zone=%s %s', $truck->public_id, $truck->vehicle?->name, $truck->zone?->name, $truck->vehicle?->online ? 'online' : 'offline'));
        }
        $this->command?->line(sprintf('  Customers sign in with %s (and the others in customerFixtures()).', $customers[0]->phone));
    }

    public function purgeSeedData(): void
    {
        $storeUuids = $this->seededUuids(Store::class);
        $truckUuids = DB::connection($this->storefrontConnection())->table('food_trucks')->whereIn('store_uuid', $storeUuids)->pluck('uuid')->all();

        $this->deleteFrom($this->storefrontConnection(), 'catalog_subjects', fn ($query) => $query->whereIn('subject_uuid', $truckUuids));
        $this->deleteFrom($this->storefrontConnection(), 'food_trucks', fn ($query) => $query->whereIn('uuid', $truckUuids));
        $this->purgeModel(Vehicle::class);
        $this->purgeModel(Zone::class);
        $this->purgeModel(ServiceArea::class);

        $this->purgeStorefrontFixtures();
    }

    /*
    |--------------------------------------------------------------------------
    | Payments and delivery
    |--------------------------------------------------------------------------
    */

    /**
     * A sandbox QPay gateway. Sandbox checkouts use QPay's TEST_INVOICE, so the invoice
     * ids only matter for live gateways.
     */
    protected function createQPayGateway(Company $company, Store|Network $owner, string $ownerType, string $seedId): Gateway
    {
        return $this->createRecord(Gateway::class, [
            'company_uuid'    => $company->uuid,
            'created_by_uuid' => session('user'),
            'owner_uuid'      => $owner->uuid,
            'owner_type'      => $ownerType,
            'name'            => 'QPay',
            'description'     => 'Sandbox QPay gateway seeded for Storefront testing.',
            'code'            => 'qpay',
            'type'            => 'qpay',
            'sandbox'         => true,
            'config'          => [
                'username'           => env('SEED_QPAY_USERNAME', 'TEST_MERCHANT'),
                'password'           => env('SEED_QPAY_PASSWORD', '123456'),
                'invoice_id'         => env('SEED_QPAY_INVOICE_ID', 'TEST_INVOICE'),
                'ebarimt_invoice_id' => env('SEED_QPAY_EBARIMT_INVOICE_ID', 'TEST_INVOICE'),
            ],
            'return_url'      => null,
            'callback_url'    => null,
            'meta'            => $this->meta($seedId),
        ]);
    }

    /**
     * Delivery in tögrög: 3,000₮ plus 500₮ per kilometre (amounts are in minor units).
     */
    protected function createMongoliaDeliveryRate(Company $company): ServiceRate
    {
        $orderConfig = Storefront::getOrderConfig($company);

        return $this->createRecord(ServiceRate::class, [
            '_key'                    => $this->fixtureKey('service-rate:' . static::NETWORK_KEY),
            'company_uuid'            => $company->uuid,
            'created_by_uuid'         => session('user'),
            'order_config_uuid'       => $orderConfig?->uuid,
            'service_name'            => 'Улаанбаатар хүргэлт',
            'service_type'            => data_get($orderConfig, 'key', 'storefront'),
            'rate_calculation_method' => 'per_meter',
            'base_fee'                => 300000,
            'per_meter_flat_rate_fee' => 50000,
            'per_meter_unit'          => 'km',
            'currency'                => 'MNT',
            'duration_terms'          => 'Нэг цагийн дотор хүргэнэ',
            'estimated_days'          => 0,
            'has_cod_fee'             => false,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Service area, zones and food trucks
    |--------------------------------------------------------------------------
    */

    /**
     * A closed ring of points from [south, west, north, east] bounds.
     */
    protected function ring(array $bounds): LineString
    {
        [$south, $west, $north, $east] = $bounds;

        return new LineString([
            new Point($south, $west),
            new Point($north, $west),
            new Point($north, $east),
            new Point($south, $east),
            new Point($south, $west),
        ]);
    }

    /**
     * @return array{0: ServiceArea, 1: array<string, Zone>}
     */
    protected function seedServiceArea(Company $company): array
    {
        $serviceArea = $this->createRecord(ServiceArea::class, [
            '_key'         => $this->fixtureKey('service-area:ulaanbaatar'),
            'company_uuid' => $company->uuid,
            'name'         => 'Улаанбаатар',
            'type'         => 'city',
            'country'      => 'MN',
            'border'       => new MultiPolygon([new Polygon([$this->ring([47.80, 106.70, 48.00, 107.15])])]),
            'color'        => '#2563eb33',
            'stroke_color' => '#2563eb',
            'status'       => 'active',
        ]);

        $zones = [];
        foreach ($this->zoneDefinitions() as $zoneKey => $zone) {
            $zones[$zoneKey] = $this->createRecord(Zone::class, [
                '_key'              => $this->fixtureKey('zone:' . $zoneKey),
                'company_uuid'      => $company->uuid,
                'service_area_uuid' => $serviceArea->uuid,
                'name'              => $zone['name'],
                'description'       => $zone['description'],
                // Passed as a spatial expression: the zone's cast keeps a Polygon object
                // out of the spatial insert, which then sends it as plain text.
                'border'            => new SpatialExpression(new Polygon([$this->ring($zone['bounds'])])),
                'color'             => $zone['color'] . '33',
                'stroke_color'      => $zone['color'],
                'status'            => 'active',
            ]);
        }

        return [$serviceArea, $zones];
    }

    protected function zoneDefinitions(): array
    {
        return [
            'central'     => ['name' => 'Төв', 'description' => 'Сүхбаатарын талбай, Энхтайвны өргөн чөлөө.', 'bounds' => [47.905, 106.880, 47.930, 106.940], 'color' => '#16a34a'],
            'zaisan'      => ['name' => 'Зайсан', 'description' => 'Хан-Уул, Зайсангийн орчим.', 'bounds' => [47.865, 106.880, 47.903, 106.950], 'color' => '#ea580c'],
            'bayanzurkh'  => ['name' => 'Баянзүрх', 'description' => 'Баянзүрх дүүрэг, зүүн хэсэг.', 'bounds' => [47.905, 106.942, 47.940, 107.010], 'color' => '#7c3aed'],
        ];
    }

    /**
     * @param array{store: Store, products: array<string, \Fleetbase\Storefront\Models\Product>} $bundle
     * @param array<string, Zone>                                                                  $zones
     *
     * @return FoodTruck[]
     */
    protected function seedFoodTrucks(Company $company, array $bundle, ServiceArea $serviceArea, array $zones): array
    {
        $store  = $bundle['store'];
        $trucks = [];

        foreach ($this->truckDefinitions() as $truckKey => $definition) {
            $seedId  = 'vehicle:' . $truckKey;
            $vehicle = $this->createRecord(Vehicle::class, [
                'company_uuid' => $company->uuid,
                'name'         => $definition['name'],
                'make'         => 'Hyundai',
                'model'        => 'Porter II',
                'year'         => '2021',
                'plate_number' => $definition['plate'],
                'type'         => 'food_truck',
                'status'       => 'active',
                'online'       => $definition['online'],
                'location'     => new Point($definition['position'][0], $definition['position'][1]),
                'meta'         => $this->meta($seedId),
            ]);

            $truck = $this->createRecord(FoodTruck::class, [
                'company_uuid'      => $company->uuid,
                'created_by_uuid'   => session('user'),
                'store_uuid'        => $store->uuid,
                'vehicle_uuid'      => $vehicle->uuid,
                'service_area_uuid' => $serviceArea->uuid,
                'zone_uuid'         => $zones[$definition['zone']]->uuid,
                'status'            => 'active',
            ]);

            $catalog = $this->createCatalog($company, $store, static::TRUCK_STORE . '-' . $truckKey, $definition['catalog'], $bundle['products']);
            $this->createRecord(CatalogSubject::class, [
                'catalog_uuid'    => $catalog->uuid,
                'subject_type'    => FoodTruck::class,
                'subject_uuid'    => $truck->uuid,
                'company_uuid'    => $company->uuid,
                'created_by_uuid' => session('user'),
            ]);

            $trucks[] = $truck->load(['vehicle', 'zone']);
        }

        return $trucks;
    }

    protected function truckDefinitions(): array
    {
        return [
            'central' => [
                'name'     => 'Хаан Бууз — Төв',
                'plate'    => '1234 УБА',
                'zone'     => 'central',
                'online'   => true,
                'position' => [47.9178, 106.9122],
                'catalog'  => ['name' => 'Төв машины цэс', 'categories' => ['Бууз' => ['buuz', 'buuz-mix'], 'Хуушуур' => ['khuushuur'], 'Ундаа' => ['suutei-tsai', 'aaruul-shake']]],
            ],
            'zaisan' => [
                'name'     => 'Хаан Бууз — Зайсан',
                'plate'    => '5678 УБВ',
                'zone'     => 'zaisan',
                'online'   => true,
                'position' => [47.8860, 106.9170],
                'catalog'  => ['name' => 'Зайсан машины цэс', 'categories' => ['Хуушуур' => ['khuushuur'], 'Шөл' => ['guriltai-shul'], 'Ундаа' => ['suutei-tsai']]],
            ],
            'bayanzurkh' => [
                'name'     => 'Хаан Бууз — Баянзүрх',
                'plate'    => '9012 УБГ',
                'zone'     => 'bayanzurkh',
                'online'   => false,
                'position' => [47.9215, 106.9710],
                'catalog'  => ['name' => 'Баянзүрх машины цэс', 'categories' => ['Бууз' => ['buuz'], 'Ундаа' => ['suutei-tsai']]],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Customers
    |--------------------------------------------------------------------------
    */

    protected function customerFixtures(): array
    {
        return [
            ['Болд Батбаяр', 'bold.batbayar@example.test', '+976 9911 0001'],
            ['Сарнай Ганбаатар', 'sarnai.ganbaatar@example.test', '+976 9911 0002'],
            ['Тэмүүлэн Дорж', 'temuulen.dorj@example.test', '+976 9911 0003'],
            ['Номин Энхбаяр', 'nomin.enkhbayar@example.test', '+976 9911 0004'],
            ['Ариунаа Мөнх', 'ariunaa.munkh@example.test', '+976 9911 0005'],
            ['Ганзориг Сүх', 'ganzorig.sukh@example.test', '+976 9911 0006'],
        ];
    }

    /**
     * Customer addresses are in the Central zone, so the Central truck delivers to them
     * and the Zaisan truck doesn't.
     */
    protected function customerPlace(Company $company, Contact $customer, int $index): Place
    {
        if (isset($this->customerPlaces[$customer->uuid])) {
            return $this->customerPlaces[$customer->uuid];
        }

        $addresses = [
            ['Энхтайвны өргөн чөлөө 44', '14200', 47.9160, 106.9105],
            ['Сөүлийн гудамж 21', '14251', 47.9132, 106.9150],
            ['Бага тойруу 15', '14201', 47.9205, 106.9120],
            ['Чингисийн өргөн чөлөө 9', '14240', 47.9110, 106.9205],
            ['Жуулчны гудамж 5', '14192', 47.9150, 106.9260],
        ];
        [$street, $postal, $lat, $lng] = $addresses[$index % count($addresses)];
        $seedId                        = 'customer-address:' . Str::slug($customer->name);

        return $this->customerPlaces[$customer->uuid] = $this->createRecord(Place::class, [
            '_key'         => $this->fixtureKey($seedId),
            'company_uuid' => $company->uuid,
            'owner_uuid'   => $customer->uuid,
            'owner_type'   => FleetOpsUtils::getMutationType('fleet-ops:contact'),
            'name'         => 'Гэр',
            'street1'      => $street,
            'city'         => 'Улаанбаатар',
            'postal_code'  => $postal,
            'country'      => 'MN',
            'location'     => new Point($lat, $lng),
            'meta'         => $this->meta($seedId),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Marketing (amounts in minor units)
    |--------------------------------------------------------------------------
    */

    protected function marketingDefinition(): array
    {
        return [
            'promotions' => [
                'welcome'       => ['name' => 'Тавтай морил', 'description' => 'Анхны захиалгадаа 15% хөнгөлөлт, дээд тал нь 10,000₮.', 'type' => 'percentage', 'value' => 15, 'max_discount_amount' => 1000000, 'min_subtotal' => 2000000, 'first_order_only' => true, 'code' => 'SAIN15', 'usage_limit_per_customer' => 1, 'starts_in_days' => -30, 'ends_in_days' => 60],
                'free-delivery' => ['name' => '50,000₮-өөс дээш үнэгүй хүргэлт', 'description' => '50,000₮ ба түүнээс дээш захиалгад хүргэлт үнэгүй.', 'type' => 'free_delivery', 'min_subtotal' => 5000000, 'starts_in_days' => -14, 'ends_in_days' => 30, 'priority' => 5],
                'weekend'       => ['name' => 'Амралтын өдрийн 5,000₮', 'description' => 'Бямба, ням гарагт 30,000₮-өөс дээш захиалгад 5,000₮ хасна.', 'type' => 'fixed_amount', 'value' => 500000, 'min_subtotal' => 3000000, 'schedule' => [['days' => [6, 7], 'start' => '00:00', 'end' => '23:59']], 'starts_in_days' => -7, 'ends_in_days' => 45],
                'upcoming'      => ['name' => 'Цагаан сарын урамшуулал', 'description' => 'Удахгүй: бүх бүтээгдэхүүнд 10%.', 'type' => 'percentage', 'value' => 10, 'starts_in_days' => 5, 'ends_in_days' => 20],
            ],
            'segments' => [
                'loyal' => ['name' => 'Байнгын үйлчлүүлэгч', 'description' => 'Гурав ба түүнээс дээш захиалга.', 'rules' => ['min_orders' => 3]],
                'never' => ['name' => 'Захиалга хийгээгүй', 'description' => 'Бүртгүүлсэн ч захиалаагүй.', 'rules' => ['max_orders' => 0]],
            ],
            'campaigns' => [
                'welcome-sent' => ['name' => 'Тавтай морил', 'title' => 'Анхны захиалгадаа 15%', 'body' => 'SAIN15 кодыг ашиглаарай.', 'status' => 'sent', 'segment' => 'never', 'promotion' => 'welcome', 'action' => ['type' => 'promotion'], 'sent_days_ago' => 2, 'stats' => ['targeted' => 0, 'batches' => 0]],
            ],
        ];
    }

    protected function storeMarketingDefinitions(): array
    {
        return [
            static::TRUCK_STORE => [
                'promotions' => [
                    'buuz-bogo' => ['name' => '2 бууз авбал 1 үнэгүй', 'description' => 'Ямар ч буузны багцад.', 'type' => 'bogo', 'bogo_config' => ['buy_quantity' => 2, 'get_quantity' => 1, 'discount_percent' => 100], 'applies_to' => ['products' => [static::TRUCK_STORE . ':buuz', static::TRUCK_STORE . ':buuz-mix']], 'starts_in_days' => -5, 'ends_in_days' => 25],
                    'lunch'     => ['name' => 'Өдрийн хоолны цаг', 'description' => 'Ажлын өдөр 12-14 цагт 20% хөнгөлөлт.', 'type' => 'percentage', 'value' => 20, 'schedule' => [['days' => [1, 2, 3, 4, 5], 'start' => '12:00', 'end' => '14:00']], 'starts_in_days' => -10, 'ends_in_days' => 50],
                ],
            ],
            'nomin-supermarket' => [
                'promotions' => [
                    'dairy' => ['name' => 'Сүүн бүтээгдэхүүн 10%', 'description' => 'Сүү, тараг, ааруулд 10% хөнгөлөлт.', 'type' => 'percentage', 'value' => 10, 'applies_to' => ['categories' => ['nomin-supermarket:dairy']], 'starts_in_days' => -3, 'ends_in_days' => 27],
                ],
            ],
            'modern-salon' => [
                'promotions' => [
                    'first-visit' => ['name' => 'Анхны үйлчилгээнд 10,000₮', 'description' => 'Шинэ үйлчлүүлэгчдэд, 30,000₮-өөс дээш.', 'type' => 'fixed_amount', 'value' => 1000000, 'min_subtotal' => 3000000, 'first_order_only' => true, 'code' => 'SALON10', 'starts_in_days' => -20, 'ends_in_days' => 70],
                ],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Network and stores
    |--------------------------------------------------------------------------
    */

    protected function networkDefinition(): array
    {
        return [
            'key'         => static::NETWORK_KEY,
            'name'        => 'Улаанбаатар Маркет',
            'description' => 'Улаанбаатарын дэлгүүр, ресторан, үйлчилгээ нэг дор. Монгол хэл, төгрөг, QPay-г туршихад зориулсан.',
            'email'       => 'market@example.mn',
            'phone'       => '+976 7700 0100',
            'website'     => 'https://example.mn/ulaanbaatar-market',
            'tags'        => ['marketplace', 'testing', 'mongolia'],
            'currency'    => 'MNT',
            'timezone'    => 'Asia/Ulaanbaatar',
            'pod_method'  => 'scan',
            'options'     => [
                'auto_accept_orders' => false,
                'auto_dispatch'      => false,
                'require_pod'        => false,
                'multi_cart_enabled' => true,
                'tips_enabled'       => true,
                'reviews_enabled'    => true,
                'pickup_enabled'     => true,
            ],
        ];
    }

    protected function networkCategories(): array
    {
        return [
            'groceries'   => ['name' => 'Хүнсний дэлгүүр', 'description' => 'Супермаркет, шинэ хүнс.', 'icon' => 'basket-shopping', 'icon_color' => '#16a34a', 'tags' => ['food']],
            'restaurants' => ['name' => 'Ресторан', 'description' => 'Хүргэлт болон авч явах хоол.', 'icon' => 'utensils', 'icon_color' => '#ea580c', 'tags' => ['food', 'meals']],
            'services'    => ['name' => 'Гоо сайхан', 'description' => 'Үсчин, гоо сайхны үйлчилгээ захиалах.', 'icon' => 'calendar-check', 'icon_color' => '#7c3aed', 'tags' => ['services']],
        ];
    }

    protected function storeDefinitions(): array
    {
        $common = ['currency' => 'MNT', 'timezone' => 'Asia/Ulaanbaatar', 'pod_method' => 'scan'];

        return [
            $common + [
                'key'              => 'nomin-supermarket',
                'network_category' => 'groceries',
                'order_count'      => 6,
                'name'             => 'Номин Супермаркет',
                'description'      => 'Энхтайвны өргөн чөлөөн дээрх супермаркет. Өдөр бүр хүргэнэ.',
                'email'            => 'nomin@example.mn',
                'phone'            => '+976 7700 0201',
                'tags'             => ['groceries', 'supermarket'],
                'gateway'          => 'qpay',
                'options'          => ['pickup_enabled' => true],
                'location'         => ['name' => 'Номин Супермаркет', 'street1' => 'Энхтайвны өргөн чөлөө 34', 'city' => 'Улаанбаатар', 'postal_code' => '14200', 'country' => 'MN', 'lat' => 47.9166, 'lng' => 106.9050],
                'hours'            => ['start' => '08:00', 'end' => '23:00'],
                'categories'       => [
                    'dairy'  => ['name' => 'Сүү, сүүн бүтээгдэхүүн', 'description' => 'Сүү, тараг, ааруул.'],
                    'meat'   => ['name' => 'Мах', 'description' => 'Шинэ мах.'],
                    'bakery' => ['name' => 'Талх, нарийн боов', 'description' => 'Өдөр бүр шинэ.'],
                ],
                'addon_categories' => [
                    'bags' => ['name' => 'Савлагаа', 'description' => 'Уут сонгох.', 'max_selectable' => 1, 'is_required' => false, 'addons' => [['Цаасан уут', 'Дахин боловсруулдаг.', 20000], ['Хөргөгчтэй уут', 'Хүйтэн байлгана.', 150000]]],
                ],
                'products' => [
                    'milk'      => ['name' => 'Сүү 1л', 'description' => 'Пастержүүлсэн үнээний сүү.', 'price' => 380000, 'category' => 'dairy', 'tags' => ['dairy'], 'recommended' => true, 'addon_categories' => ['bags']],
                    'tarag'     => ['name' => 'Тараг 500г', 'description' => 'Уламжлалт исгэлэн тараг.', 'price' => 420000, 'sale_price' => 350000, 'category' => 'dairy', 'tags' => ['dairy']],
                    'aaruul'    => ['name' => 'Ааруул 250г', 'description' => 'Гэрийн хатаасан ааруул.', 'price' => 650000, 'category' => 'dairy', 'tags' => ['dairy']],
                    'mutton'    => ['name' => 'Хонины мах 1кг', 'description' => 'Шинэ хонины мах.', 'price' => 1800000, 'category' => 'meat', 'tags' => ['meat'], 'variants' => [['name' => 'Хэрчилт', 'required' => true, 'options' => [['Бүтэн', 0], ['Хэрчсэн', 100000]]]]],
                    'beef'      => ['name' => 'Үхрийн мах 1кг', 'description' => 'Ястай үхрийн мах.', 'price' => 2200000, 'category' => 'meat', 'tags' => ['meat']],
                    'bread'     => ['name' => 'Атар талх', 'description' => 'Өглөө бүр шинээр жигнэнэ.', 'price' => 250000, 'category' => 'bakery', 'tags' => ['bread'], 'recommended' => true],
                    'boortsog'  => ['name' => 'Боорцог 500г', 'description' => 'Шаржигнуур боорцог.', 'price' => 600000, 'category' => 'bakery', 'tags' => ['pastry']],
                ],
                'catalog' => ['name' => 'Номин Супермаркетын каталог', 'categories' => ['Сүүн бүтээгдэхүүн' => ['milk', 'tarag', 'aaruul'], 'Мах' => ['mutton', 'beef'], 'Талх' => ['bread', 'boortsog']]],
            ],
            $common + [
                'key'              => static::TRUCK_STORE,
                'network_category' => 'restaurants',
                'order_count'      => 6,
                'name'             => 'Хаан Бууз',
                'description'      => 'Гар хийцийн бууз, хуушуур. Ресторан болон хоолны машинууд.',
                'email'            => 'khaanbuuz@example.mn',
                'phone'            => '+976 7700 0202',
                'tags'             => ['restaurant', 'buuz', 'food-truck'],
                'gateway'          => 'qpay',
                'options'          => ['auto_accept_orders' => true, 'pickup_enabled' => true],
                'location'         => ['name' => 'Хаан Бууз', 'street1' => 'Бага тойруу 12', 'city' => 'Улаанбаатар', 'postal_code' => '14201', 'country' => 'MN', 'lat' => 47.9203, 'lng' => 106.9180],
                'hours'            => ['start' => '09:00', 'end' => '22:00'],
                'categories'       => [
                    'buuz'      => ['name' => 'Бууз', 'description' => 'Уурын бууз.'],
                    'khuushuur' => ['name' => 'Хуушуур', 'description' => 'Шарсан хуушуур.'],
                    'soup'      => ['name' => 'Шөл', 'description' => 'Халуун шөл.'],
                    'drinks'    => ['name' => 'Ундаа', 'description' => 'Цай, ундаа.'],
                ],
                'addon_categories' => [
                    'sides' => ['name' => 'Нэмэлт', 'description' => 'Нэмэлт хачир.', 'max_selectable' => 2, 'is_required' => false, 'addons' => [['Даршилсан ногоо', 'Байцаа, лууван.', 150000], ['Кетчуп', 'Нэмэлт соус.', 0]]],
                ],
                'products' => [
                    'buuz'          => ['name' => 'Бууз (10ш)', 'description' => 'Хонины махтай уурын бууз.', 'price' => 1200000, 'category' => 'buuz', 'tags' => ['buuz'], 'recommended' => true, 'addon_categories' => ['sides']],
                    'buuz-mix'      => ['name' => 'Холимог бууз (10ш)', 'description' => 'Үхэр, хонины холимог махтай.', 'price' => 1300000, 'category' => 'buuz', 'tags' => ['buuz'], 'variants' => [['name' => 'Хэмжээ', 'required' => true, 'options' => [['10ш', 0], ['20ш', 1200000]]]]],
                    'khuushuur'     => ['name' => 'Хуушуур (4ш)', 'description' => 'Шинэхэн шарсан хуушуур.', 'price' => 1000000, 'sale_price' => 850000, 'category' => 'khuushuur', 'tags' => ['khuushuur'], 'recommended' => true, 'addon_categories' => ['sides']],
                    'guriltai-shul' => ['name' => 'Гурилтай шөл', 'description' => 'Гар гурилтай махан шөл.', 'price' => 1100000, 'category' => 'soup', 'tags' => ['soup']],
                    'suutei-tsai'   => ['name' => 'Сүүтэй цай', 'description' => 'Халуун сүүтэй цай.', 'price' => 200000, 'category' => 'drinks', 'tags' => ['tea']],
                    'aaruul-shake'  => ['name' => 'Ааруулын коктейль', 'description' => 'Ааруул, сүү, зөгийн бал.', 'price' => 650000, 'category' => 'drinks', 'tags' => ['drink']],
                ],
                'catalog' => ['name' => 'Хаан Буузын цэс', 'categories' => ['Бууз' => ['buuz', 'buuz-mix'], 'Хуушуур' => ['khuushuur'], 'Шөл' => ['guriltai-shul'], 'Ундаа' => ['suutei-tsai', 'aaruul-shake']]],
            ],
            $common + [
                'key'              => 'modern-salon',
                'network_category' => 'services',
                'order_count'      => 4,
                'name'             => 'Модерн Салон',
                'description'      => 'Үс засалт, маникюр. Цагаа урьдчилан захиална уу.',
                'email'            => 'salon@example.mn',
                'phone'            => '+976 7700 0203',
                'tags'             => ['services', 'beauty'],
                'gateway'          => null,
                'location'         => ['name' => 'Модерн Салон', 'street1' => 'Сөүлийн гудамж 18', 'city' => 'Улаанбаатар', 'postal_code' => '14251', 'country' => 'MN', 'lat' => 47.9128, 'lng' => 106.9162],
                'hours'            => ['start' => '10:00', 'end' => '20:00', 'closed' => ['monday']],
                'categories'       => [
                    'hair'  => ['name' => 'Үс засалт', 'description' => 'Эрэгтэй, эмэгтэй үс засалт.'],
                    'nails' => ['name' => 'Хумс', 'description' => 'Маникюр, педикюр.'],
                ],
                'products' => [
                    'haircut'  => ['name' => 'Эмэгтэй үс засалт', 'description' => 'Угаалт, засалт, хатаалт.', 'price' => 3500000, 'category' => 'hair', 'tags' => ['hair'], 'is_service' => true, 'is_bookable' => true, 'meta' => ['duration' => 60], 'recommended' => true],
                    'barber'   => ['name' => 'Эрэгтэй үс засалт', 'description' => 'Сахал засалттай.', 'price' => 2500000, 'category' => 'hair', 'tags' => ['hair'], 'is_service' => true, 'is_bookable' => true, 'meta' => ['duration' => 45]],
                    'manicure' => ['name' => 'Гель маникюр', 'description' => 'Өнгө сонгох боломжтой.', 'price' => 4000000, 'category' => 'nails', 'tags' => ['nails'], 'is_service' => true, 'is_bookable' => true, 'meta' => ['duration' => 90]],
                ],
                'catalog' => ['name' => 'Модерн Салоны үйлчилгээ', 'categories' => ['Үс засалт' => ['haircut', 'barber'], 'Хумс' => ['manicure']]],
            ],
        ];
    }
}
