<?php

namespace Fleetbase\Storefront\Seeders\Testing;

use Fleetbase\Models\Company;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Seeders\Testing\Concerns\SeedsStorefrontFixtures;
use Illuminate\Database\Seeder;

/**
 * Seeds a complete marketplace (network) for end-to-end Storefront testing.
 *
 * Produces a network with an order config and a sandbox Stripe gateway, network
 * store categories, and several member stores each with their own location, product
 * categories, products, catalog and (for some) their own Stripe gateway. One store is
 * left without a network category to exercise the `without_category` filters. Two
 * service stores (tagged `services`) sell bookable services with durations. Shared
 * customers place orders across every store so network-level dashboards, order
 * listings and analytics have data.
 *
 * Marketing: network and store promotions covering every type (percentage, fixed
 * amount, free delivery, buy X get Y) and state (live, happy-hour schedule, code only,
 * first order only, upcoming, ended, draft), customer segments, and sent, scheduled and
 * draft campaigns.
 *
 * Re-running the seeder purges and recreates its own fixtures only.
 *
 *   php artisan db:seed --class="Fleetbase\\Storefront\\Seeders\\Testing\\NetworkSeeder"
 *
 * Optional environment:
 *   SEED_COMPANY_UUID / SEED_COMPANY_PUBLIC_ID   company to seed into (defaults to the oldest company)
 *   SEED_STRIPE_SECRET_KEY / SEED_STRIPE_PUBLISHABLE_KEY   real Stripe test keys for the seeded gateways
 */
class NetworkSeeder extends Seeder
{
    use SeedsStorefrontFixtures;

    public const NETWORK_KEY = 'fleetbase-marketplace';

    protected function seedName(): string
    {
        return 'storefront-testing-network';
    }

    public function run(): void
    {
        $company = $this->prepareCompany();
        if (!$company) {
            return;
        }

        $this->withoutForeignKeyConstraints(fn () => $this->purgeSeedData());

        $network = $this->createNetwork($company, $this->networkDefinition());
        $gateway = $this->createStripeGateway($company, $network, 'storefront:network', 'gateway:' . static::NETWORK_KEY . ':stripe');

        $categories = [];
        foreach ($this->networkCategories() as $categoryKey => $category) {
            $categories[$categoryKey] = $this->createNetworkCategory($company, $network, static::NETWORK_KEY, $categoryKey, $category);
        }

        $customers  = $this->seedCustomers($company, $this->customerFixtures());
        $orderCount = 0;
        $bundles    = [];

        foreach ($this->storeDefinitions() as $definition) {
            $bundle   = $this->seedStore($company, $definition);
            $category = isset($definition['network_category']) ? ($categories[$definition['network_category']] ?? null) : null;

            $this->addStoreToNetwork($network, $bundle['store'], $category);

            $orders = $this->seedStoreActivity($company, $bundle, $customers, $network, $definition['order_count'] ?? 12);
            $orderCount += count($orders);
            $bundles[$definition['key']] = $bundle;
        }

        $marketing = $this->seedMarketing($company, $network, static::NETWORK_KEY, $this->marketingDefinition(), $bundles);
        foreach ($this->storeMarketingDefinitions() as $storeKey => $definition) {
            if (isset($bundles[$storeKey])) {
                $storeMarketing = $this->seedMarketing($company, $bundles[$storeKey]['store'], $storeKey, $definition, $bundles);
                foreach ($storeMarketing as $kind => $records) {
                    $marketing[$kind] = array_merge($marketing[$kind], array_values($records));
                }
            }
        }

        $this->command?->info(sprintf('Seeded Storefront testing network for company %s with %d stores and %d orders.', $company->public_id, count($bundles), $orderCount));
        $this->command?->info(sprintf('  Marketing: %d promotions, %d segments, %d campaigns.', count($marketing['promotions']), count($marketing['segments']), count($marketing['campaigns'])));
        $this->reportStorefront($network, $gateway, '  ');
        foreach ($bundles as $bundle) {
            $this->reportStorefront($bundle['store'], $bundle['gateway'], '    ');
        }
    }

    public function purgeSeedData(): void
    {
        $this->purgeStorefrontFixtures();
    }

    protected function networkDefinition(): array
    {
        return [
            'key'         => static::NETWORK_KEY,
            'name'        => 'Fleetbase Marketplace',
            'description' => 'Multi-vendor marketplace used to test network browsing, categories, cross-store checkout and network-level reporting.',
            'email'       => 'marketplace@example.test',
            'phone'       => '+65 6100 0200',
            'website'     => 'https://example.test/fleetbase-marketplace',
            'tags'        => ['marketplace', 'testing'],
            'currency'    => 'USD',
            'timezone'    => 'Asia/Singapore',
            'pod_method'  => 'scan',
            'options'     => [
                'auto_accept_orders' => false,
                'auto_dispatch'      => false,
                'require_pod'        => true,
            ],
        ];
    }

    protected function networkCategories(): array
    {
        return [
            'groceries'   => ['name' => 'Groceries', 'description' => 'Supermarkets and fresh food.', 'icon' => 'basket-shopping', 'icon_color' => '#16a34a', 'tags' => ['food', 'fresh']],
            'restaurants' => ['name' => 'Restaurants', 'description' => 'Prepared meals for delivery and pickup.', 'icon' => 'utensils', 'icon_color' => '#ea580c', 'tags' => ['food', 'meals']],
            'health'      => ['name' => 'Health & Beauty', 'description' => 'Pharmacies and personal care.', 'icon' => 'heart-pulse', 'icon_color' => '#2563eb', 'tags' => ['pharmacy']],
            'services'    => ['name' => 'Home & Wellness', 'description' => 'Book cleaners, stylists and therapists.', 'icon' => 'calendar-check', 'icon_color' => '#7c3aed', 'tags' => ['services']],
        ];
    }

    protected function storeDefinitions(): array
    {
        return [
            [
                'key'              => 'orchard-grocers',
                'network_category' => 'groceries',
                'order_count'      => 12,
                'name'             => 'Orchard Grocers',
                'description'      => 'Supermarket on Orchard Road with same-day delivery.',
                'email'            => 'orchard@example.test',
                'phone'            => '+65 6100 0301',
                'tags'             => ['groceries', 'supermarket'],
                'gateway'          => 'stripe',
                'location'         => ['name' => 'Orchard Grocers', 'street1' => '2 Orchard Turn', 'city' => 'Singapore', 'postal_code' => '238801', 'country' => 'SG', 'lat' => 1.3040, 'lng' => 103.8318],
                'hours'            => ['start' => '07:00', 'end' => '22:00'],
                'categories'       => [
                    'produce' => ['name' => 'Fresh Produce', 'description' => 'Fruit and vegetables.'],
                    'dairy'   => ['name' => 'Dairy & Eggs', 'description' => 'Milk, cheese and eggs.'],
                    'bakery'  => ['name' => 'Bakery', 'description' => 'Baked daily.'],
                ],
                'addon_categories' => [
                    'bags' => ['name' => 'Packaging', 'description' => 'Bag options.', 'max_selectable' => 1, 'is_required' => false, 'addons' => [['Paper Bag', 'Recyclable paper bag.', 20], ['Insulated Bag', 'Keeps cold items cold.', 250]]],
                ],
                'products' => [
                    'fruit-basket'  => ['name' => 'Tropical Fruit Basket', 'description' => 'Mango, papaya, pineapple and dragon fruit.', 'price' => 3200, 'category' => 'produce', 'tags' => ['fruit'], 'recommended' => true, 'addon_categories' => ['bags']],
                    'salad-greens'  => ['name' => 'Salad Greens Mix', 'description' => 'Washed and ready to eat.', 'price' => 690, 'category' => 'produce', 'tags' => ['vegetables']],
                    'fresh-milk'    => ['name' => 'Fresh Milk 1L', 'description' => 'Pasteurised full cream milk.', 'price' => 420, 'category' => 'dairy', 'tags' => ['dairy'], 'addon_categories' => ['bags']],
                    'free-range'    => ['name' => 'Free Range Eggs (12)', 'description' => 'Dozen free range eggs.', 'price' => 780, 'category' => 'dairy', 'tags' => ['eggs']],
                    'sourdough'     => ['name' => 'Sourdough Loaf', 'description' => 'Naturally leavened, baked this morning.', 'price' => 950, 'category' => 'bakery', 'tags' => ['bread'], 'recommended' => true, 'variants' => [['name' => 'Slice', 'required' => true, 'options' => [['Whole', 0], ['Sliced', 0]]]]],
                    'croissants'    => ['name' => 'Butter Croissants (4)', 'description' => 'Four all-butter croissants.', 'price' => 1200, 'sale_price' => 980, 'category' => 'bakery', 'tags' => ['pastry']],
                ],
                'catalog' => ['name' => 'Orchard Grocers Catalog', 'categories' => ['Fresh' => ['fruit-basket', 'salad-greens'], 'Dairy' => ['fresh-milk', 'free-range'], 'Bakery' => ['sourdough', 'croissants']]],
            ],
            [
                'key'              => 'rochor-noodle-house',
                'network_category' => 'restaurants',
                'order_count'      => 12,
                'name'             => 'Rochor Noodle House',
                'description'      => 'Hand pulled noodles and dumplings, delivery and pickup.',
                'email'            => 'rochor@example.test',
                'phone'            => '+65 6100 0302',
                'tags'             => ['restaurant', 'noodles', 'halal'],
                'gateway'          => null,
                'options'          => ['auto_accept_orders' => true],
                'location'         => ['name' => 'Rochor Noodle House', 'street1' => '1 Rochor Canal Road', 'city' => 'Singapore', 'postal_code' => '188504', 'country' => 'SG', 'lat' => 1.3039, 'lng' => 103.8520],
                'hours'            => ['start' => '11:00', 'end' => '23:00', 'closed' => ['monday']],
                'categories'       => [
                    'noodles'   => ['name' => 'Noodles', 'description' => 'Hand pulled daily.'],
                    'dumplings' => ['name' => 'Dumplings', 'description' => 'Steamed or pan fried.'],
                    'drinks'    => ['name' => 'Drinks', 'description' => 'Teas and soft drinks.'],
                ],
                'addon_categories' => [
                    'toppings' => ['name' => 'Toppings', 'description' => 'Extra toppings.', 'max_selectable' => 3, 'is_required' => false, 'addons' => [['Extra Beef', 'Additional sliced beef.', 350], ['Soft Egg', 'Soy marinated egg.', 150], ['Chilli Oil', 'House chilli oil.', 0]]],
                ],
                'products' => [
                    'beef-noodles'    => ['name' => 'Braised Beef Noodles', 'description' => 'Slow braised beef in clear broth.', 'price' => 1280, 'category' => 'noodles', 'tags' => ['beef', 'soup'], 'recommended' => true, 'variants' => [['name' => 'Spice Level', 'required' => true, 'options' => [['Mild', 0], ['Medium', 0], ['Hot', 0]]]], 'addon_categories' => ['toppings']],
                    'dan-dan'         => ['name' => 'Dan Dan Noodles', 'description' => 'Sesame, chilli and minced meat.', 'price' => 1080, 'category' => 'noodles', 'tags' => ['spicy'], 'addon_categories' => ['toppings']],
                    'pork-dumplings'  => ['name' => 'Pork & Chive Dumplings (10)', 'description' => 'Ten dumplings.', 'price' => 900, 'category' => 'dumplings', 'tags' => ['dumplings'], 'variants' => [['name' => 'Style', 'required' => true, 'options' => [['Steamed', 0], ['Pan Fried', 100]]]]],
                    'veg-dumplings'   => ['name' => 'Vegetable Dumplings (10)', 'description' => 'Cabbage, mushroom and glass noodle.', 'price' => 850, 'category' => 'dumplings', 'tags' => ['vegetarian']],
                    'jasmine-tea'     => ['name' => 'Iced Jasmine Tea', 'description' => 'Lightly sweetened.', 'price' => 350, 'category' => 'drinks', 'tags' => ['tea']],
                ],
                'catalog' => ['name' => 'Rochor Noodle House Menu', 'categories' => ['Noodles' => ['beef-noodles', 'dan-dan'], 'Dumplings' => ['pork-dumplings', 'veg-dumplings'], 'Drinks' => ['jasmine-tea']]],
            ],
            [
                'key'              => 'tampines-fresh-mart',
                'network_category' => 'groceries',
                'order_count'      => 8,
                'name'             => 'Tampines Fresh Mart',
                'description'      => 'Wet market produce and seafood in the east.',
                'email'            => 'tampines@example.test',
                'phone'            => '+65 6100 0303',
                'tags'             => ['groceries', 'seafood', 'market'],
                'gateway'          => 'stripe',
                'location'         => ['name' => 'Tampines Fresh Mart', 'street1' => '4 Tampines Central 5', 'city' => 'Singapore', 'postal_code' => '529510', 'country' => 'SG', 'lat' => 1.3525, 'lng' => 103.9447],
                'hours'            => ['start' => '06:00', 'end' => '14:00'],
                'categories'       => [
                    'seafood' => ['name' => 'Seafood', 'description' => 'Landed this morning.'],
                    'produce' => ['name' => 'Vegetables', 'description' => 'Local and imported greens.'],
                ],
                'products' => [
                    'sea-bass'     => ['name' => 'Whole Sea Bass', 'description' => 'Cleaned and scaled, about 800g.', 'price' => 1850, 'category' => 'seafood', 'tags' => ['fish'], 'recommended' => true, 'variants' => [['name' => 'Preparation', 'required' => false, 'options' => [['Whole', 0], ['Filleted', 200]]]]],
                    'prawns'       => ['name' => 'Tiger Prawns 500g', 'description' => 'Large tiger prawns.', 'price' => 2200, 'category' => 'seafood', 'tags' => ['shellfish']],
                    'kai-lan'      => ['name' => 'Kai Lan Bundle', 'description' => 'Chinese broccoli.', 'price' => 320, 'category' => 'produce', 'tags' => ['vegetables']],
                    'bean-sprouts' => ['name' => 'Bean Sprouts 300g', 'description' => 'Crisp bean sprouts.', 'price' => 150, 'category' => 'produce', 'tags' => ['vegetables']],
                ],
                'catalog' => ['name' => 'Tampines Fresh Mart Catalog', 'categories' => ['Seafood' => ['sea-bass', 'prawns'], 'Greens' => ['kai-lan', 'bean-sprouts']]],
            ],
            [
                'key'              => 'bayfront-pharmacy',
                'network_category' => 'health',
                'order_count'      => 8,
                'name'             => 'Bayfront Pharmacy',
                'description'      => 'Pharmacy and personal care with scheduled delivery.',
                'email'            => 'bayfront@example.test',
                'phone'            => '+65 6100 0304',
                'tags'             => ['pharmacy', 'health'],
                'gateway'          => 'stripe',
                'options'          => ['require_pod' => true],
                'pod_method'       => 'signature',
                'location'         => ['name' => 'Bayfront Pharmacy', 'street1' => '10 Bayfront Avenue', 'city' => 'Singapore', 'postal_code' => '018956', 'country' => 'SG', 'lat' => 1.2838, 'lng' => 103.8591],
                'hours'            => ['start' => '09:00', 'end' => '21:00'],
                'categories'       => [
                    'medicine' => ['name' => 'Over the Counter', 'description' => 'Common remedies.'],
                    'care'     => ['name' => 'Personal Care', 'description' => 'Skin and body care.'],
                ],
                'products' => [
                    'paracetamol' => ['name' => 'Paracetamol 500mg (20)', 'description' => 'Pain and fever relief.', 'price' => 480, 'category' => 'medicine', 'tags' => ['medicine']],
                    'vitamin-c'   => ['name' => 'Vitamin C 1000mg (60)', 'description' => 'Daily immune support.', 'price' => 1590, 'category' => 'medicine', 'tags' => ['vitamins'], 'recommended' => true],
                    'sunscreen'   => ['name' => 'SPF 50 Sunscreen', 'description' => 'Broad spectrum, 100ml.', 'price' => 2190, 'category' => 'care', 'tags' => ['skincare']],
                    'hand-soap'   => ['name' => 'Hand Soap Refill 1L', 'description' => 'Fragrance free.', 'price' => 890, 'category' => 'care', 'tags' => ['household']],
                ],
                'catalog' => ['name' => 'Bayfront Pharmacy Catalog', 'categories' => ['Medicine' => ['paracetamol', 'vitamin-c'], 'Care' => ['sunscreen', 'hand-soap']]],
            ],
            [
                'key'              => 'marina-flowers',
                'network_category' => null,
                'order_count'      => 6,
                'name'             => 'Marina Flowers',
                'description'      => 'Florist without a network category, to test uncategorised store listings.',
                'email'            => 'flowers@example.test',
                'phone'            => '+65 6100 0305',
                'tags'             => ['flowers', 'gifts'],
                'gateway'          => null,
                'location'         => ['name' => 'Marina Flowers', 'street1' => '8 Marina Boulevard', 'city' => 'Singapore', 'postal_code' => '018981', 'country' => 'SG', 'lat' => 1.2790, 'lng' => 103.8540],
                'hours'            => ['start' => '10:00', 'end' => '19:00', 'closed' => ['sunday']],
                'categories'       => [
                    'bouquets' => ['name' => 'Bouquets', 'description' => 'Hand tied bouquets.'],
                ],
                'addon_categories' => [
                    'gift' => ['name' => 'Gift Options', 'description' => 'Cards and vases.', 'max_selectable' => 2, 'is_required' => false, 'addons' => [['Message Card', 'Handwritten card.', 200], ['Glass Vase', 'Clear glass vase.', 1500]]],
                ],
                'products' => [
                    'rose-bouquet'  => ['name' => 'Dozen Red Roses', 'description' => 'Twelve long stem roses.', 'price' => 6500, 'category' => 'bouquets', 'tags' => ['roses'], 'recommended' => true, 'addon_categories' => ['gift']],
                    'mixed-bouquet' => ['name' => 'Seasonal Mixed Bouquet', 'description' => 'Florist choice of seasonal stems.', 'price' => 4800, 'category' => 'bouquets', 'tags' => ['seasonal'], 'variants' => [['name' => 'Size', 'required' => true, 'options' => [['Petite', 0], ['Standard', 1500], ['Grand', 3500]]]], 'addon_categories' => ['gift']],
                ],
                'catalog' => ['name' => 'Marina Flowers Catalog', 'categories' => ['Bouquets' => ['rose-bouquet', 'mixed-bouquet']]],
            ],
            [
                'key'              => 'lion-city-cleaning',
                'network_category' => 'services',
                'order_count'      => 5,
                'name'             => 'Lion City Cleaning',
                'description'      => 'Home cleaning by vetted cleaners. Book a date and start time.',
                'email'            => 'cleaning@example.test',
                'phone'            => '+65 6100 0306',
                'tags'             => ['services', 'cleaning', 'home'],
                'gateway'          => 'stripe',
                'location'         => ['name' => 'Lion City Cleaning', 'street1' => '7 Raffles Place', 'city' => 'Singapore', 'postal_code' => '048616', 'country' => 'SG', 'lat' => 1.2840, 'lng' => 103.8515],
                'hours'            => ['start' => '08:00', 'end' => '20:00', 'closed' => ['sunday']],
                'categories'       => [
                    'cleaning'  => ['name' => 'Home Cleaning', 'description' => 'Regular and deep cleans.'],
                    'specialty' => ['name' => 'Specialty', 'description' => 'Appliances, windows and upholstery.'],
                ],
                'addon_categories' => [
                    'extras' => ['name' => 'Extras', 'description' => 'Add to your clean.', 'max_selectable' => 3, 'is_required' => false, 'addons' => [['Inside the Fridge', 'Empty, wipe and restock.', 1500], ['Inside the Oven', 'Degrease racks and walls.', 2000], ['Ironing (1 hour)', 'Shirts and linens.', 2500]]],
                ],
                'products' => [
                    'standard-clean' => ['name' => 'Standard Home Clean', 'description' => 'Dusting, floors, kitchen and bathrooms.', 'price' => 6500, 'category' => 'cleaning', 'tags' => ['cleaning'], 'recommended' => true, 'is_service' => true, 'is_bookable' => true, 'can_pickup' => false, 'meta' => ['duration' => 180], 'variants' => [['name' => 'Home Size', 'required' => true, 'multiselect' => false, 'options' => [['Studio / 1 bedroom', 0], ['2 bedrooms', 2000], ['3+ bedrooms', 4000]]]], 'addon_categories' => ['extras']],
                    'deep-clean'     => ['name' => 'Deep Clean', 'description' => 'Top to bottom, including inside cabinets.', 'price' => 14500, 'category' => 'cleaning', 'tags' => ['cleaning'], 'is_service' => true, 'is_bookable' => true, 'can_pickup' => false, 'meta' => ['duration' => 360], 'addon_categories' => ['extras']],
                    'aircon-service' => ['name' => 'Aircon Servicing', 'description' => 'Filter wash and coil check, per unit.', 'price' => 3500, 'category' => 'specialty', 'tags' => ['aircon'], 'is_service' => true, 'is_bookable' => true, 'can_pickup' => false, 'meta' => ['duration' => 45]],
                    'sofa-clean'     => ['name' => 'Sofa Shampoo', 'description' => 'Fabric sofa, up to 3 seats.', 'price' => 8900, 'sale_price' => 7500, 'category' => 'specialty', 'tags' => ['upholstery'], 'is_service' => true, 'is_bookable' => true, 'can_pickup' => false, 'meta' => ['duration' => 90]],
                ],
                'catalog' => ['name' => 'Lion City Cleaning Services', 'categories' => ['Home Cleaning' => ['standard-clean', 'deep-clean'], 'Specialty' => ['aircon-service', 'sofa-clean']]],
            ],
            [
                'key'              => 'tiong-bahru-wellness',
                'network_category' => 'services',
                'order_count'      => 5,
                'name'             => 'Tiong Bahru Wellness',
                'description'      => 'Hair, nails and massage. Book a time at the studio.',
                'email'            => 'wellness@example.test',
                'phone'            => '+65 6100 0307',
                'tags'             => ['services', 'salon', 'spa'],
                'gateway'          => null,
                'location'         => ['name' => 'Tiong Bahru Wellness', 'street1' => '55 Tiong Bahru Road', 'city' => 'Singapore', 'postal_code' => '160055', 'country' => 'SG', 'lat' => 1.2860, 'lng' => 103.8302],
                'hours'            => ['start' => '10:00', 'end' => '21:00', 'closed' => ['monday']],
                'categories'       => [
                    'hair'    => ['name' => 'Hair', 'description' => 'Cuts and colour.'],
                    'massage' => ['name' => 'Massage', 'description' => 'Relaxation and deep tissue.'],
                    'nails'   => ['name' => 'Nails', 'description' => 'Manicures and pedicures.'],
                ],
                'products' => [
                    'haircut'        => ['name' => 'Haircut & Blow Dry', 'description' => 'Consultation, wash, cut and style.', 'price' => 4800, 'category' => 'hair', 'tags' => ['hair'], 'recommended' => true, 'is_service' => true, 'is_bookable' => true, 'meta' => ['duration' => 60], 'variants' => [['name' => 'Stylist', 'required' => true, 'multiselect' => false, 'options' => [['Stylist', 0], ['Senior Stylist', 1500]]]]],
                    'hair-colour'    => ['name' => 'Full Colour', 'description' => 'Single process colour with gloss.', 'price' => 12000, 'category' => 'hair', 'tags' => ['colour'], 'is_service' => true, 'is_bookable' => true, 'meta' => ['duration' => 120]],
                    'swedish-60'     => ['name' => 'Swedish Massage 60 min', 'description' => 'Full body, light to medium pressure.', 'price' => 9800, 'category' => 'massage', 'tags' => ['massage'], 'recommended' => true, 'is_service' => true, 'is_bookable' => true, 'meta' => ['duration' => 60]],
                    'deep-tissue-90' => ['name' => 'Deep Tissue Massage 90 min', 'description' => 'Firm pressure for tight muscles.', 'price' => 14800, 'category' => 'massage', 'tags' => ['massage'], 'is_service' => true, 'is_bookable' => true, 'meta' => ['duration' => 90]],
                    'gel-manicure'   => ['name' => 'Gel Manicure', 'description' => 'Shape, cuticle care and gel colour.', 'price' => 4500, 'category' => 'nails', 'tags' => ['nails'], 'is_service' => true, 'is_bookable' => true, 'meta' => ['duration' => 45]],
                ],
                'catalog' => ['name' => 'Tiong Bahru Wellness Menu', 'categories' => ['Hair' => ['haircut', 'hair-colour'], 'Massage' => ['swedish-60', 'deep-tissue-90'], 'Nails' => ['gel-manicure']]],
            ],
        ];
    }

    /**
     * Network-wide promotions, segments and campaigns (owned by the network).
     */
    protected function marketingDefinition(): array
    {
        return [
            'promotions' => [
                'welcome'         => ['name' => 'Welcome to Fleetbase Marketplace', 'description' => '15% off your first order, up to $10.', 'type' => 'percentage', 'value' => 15, 'max_discount_amount' => 1000, 'min_subtotal' => 2000, 'first_order_only' => true, 'code' => 'WELCOME15', 'usage_limit_per_customer' => 1, 'starts_in_days' => -30, 'ends_in_days' => 60],
                'free-delivery'   => ['name' => 'Free delivery over $40', 'description' => 'Delivery is on us when your basket is $40 or more.', 'type' => 'free_delivery', 'min_subtotal' => 4000, 'starts_in_days' => -14, 'ends_in_days' => 30, 'priority' => 5],
                'weekend-five'    => ['name' => '$5 off weekend orders', 'description' => 'Every Saturday and Sunday, $5 off orders of $25 or more.', 'type' => 'fixed_amount', 'value' => 500, 'min_subtotal' => 2500, 'schedule' => [['days' => [6, 7], 'start' => '00:00', 'end' => '23:59']], 'starts_in_days' => -7, 'ends_in_days' => 45],
                'services-launch' => ['name' => 'Home & Wellness launch', 'description' => '20% off any booked service.', 'type' => 'percentage', 'value' => 20, 'max_discount_amount' => 3000, 'applies_to' => ['stores' => ['lion-city-cleaning', 'tiong-bahru-wellness']], 'starts_in_days' => 3, 'ends_in_days' => 33],
                'spring-sale'     => ['name' => 'Spring sale', 'description' => '10% off everything (ended).', 'type' => 'percentage', 'value' => 10, 'starts_in_days' => -40, 'ends_in_days' => -10],
                'vip-preview'     => ['name' => 'VIP preview (draft)', 'description' => 'Not published yet.', 'type' => 'percentage', 'value' => 25, 'status' => 'draft', 'is_public' => false, 'code' => 'VIPPREVIEW'],
            ],
            'segments' => [
                'loyal'       => ['name' => 'Loyal customers', 'description' => 'Three or more orders.', 'rules' => ['min_orders' => 3]],
                'recent'      => ['name' => 'Ordered this month', 'description' => 'At least one order in the last 30 days.', 'rules' => ['ordered_within_days' => 30]],
                'lapsed'      => ['name' => 'Lapsed customers', 'description' => 'Ordered before, but not in the last 14 days.', 'rules' => ['not_ordered_within_days' => 14]],
                'never'       => ['name' => 'Never ordered', 'description' => 'Signed up but no orders yet.', 'rules' => ['max_orders' => 0]],
                'push-ready'  => ['name' => 'Push enabled', 'description' => 'Customers with a registered push device.', 'rules' => ['has_push_device' => true]],
            ],
            'campaigns' => [
                'welcome-sent'   => ['name' => 'Welcome offer', 'title' => '15% off your first order', 'body' => 'Use WELCOME15 at checkout. Ends soon.', 'status' => 'sent', 'segment' => 'never', 'promotion' => 'welcome', 'action' => ['type' => 'promotion'], 'sent_days_ago' => 3, 'stats' => ['targeted' => 0, 'batches' => 0]],
                'delivery-sent'  => ['name' => 'Free delivery week', 'title' => 'Free delivery over $40', 'body' => 'Stock up this week, delivery is on us.', 'status' => 'sent', 'segment' => 'loyal', 'promotion' => 'free-delivery', 'action' => ['type' => 'promotion'], 'sent_days_ago' => 1, 'stats' => ['targeted' => 12, 'batches' => 1]],
                'services-soon'  => ['name' => 'Home & Wellness launch', 'title' => 'Book a clean or a massage', 'body' => 'New on Fleetbase Marketplace: 20% off booked services.', 'status' => 'scheduled', 'segment' => 'recent', 'promotion' => 'services-launch', 'action' => ['type' => 'store', 'ref' => 'lion-city-cleaning'], 'send_in_days' => 14],
                'winback-draft'  => ['name' => 'We miss you (draft)', 'title' => 'Come back for $5 off', 'body' => 'Here is $5 off your next weekend order.', 'status' => 'draft', 'segment' => 'lapsed', 'promotion' => 'weekend-five'],
            ],
        ];
    }

    /**
     * Promotions and campaigns owned by individual member stores, by store key.
     */
    protected function storeMarketingDefinitions(): array
    {
        return [
            'orchard-grocers' => [
                'promotions' => [
                    'bakery-happy-hour' => ['name' => 'Bakery happy hour', 'description' => '30% off bakery, weekdays 5 to 8 pm.', 'type' => 'percentage', 'value' => 30, 'applies_to' => ['categories' => ['orchard-grocers:bakery']], 'schedule' => [['days' => [1, 2, 3, 4, 5], 'start' => '17:00', 'end' => '20:00']], 'starts_in_days' => -10, 'ends_in_days' => 50],
                ],
                'campaigns' => [
                    'bakery-sent' => ['name' => 'Bakery happy hour', 'title' => '30% off fresh bread after 5', 'body' => 'Sourdough and croissants, weekdays 5 to 8 pm.', 'status' => 'sent', 'promotion' => 'bakery-happy-hour', 'action' => ['type' => 'product', 'ref' => 'orchard-grocers:sourdough'], 'sent_days_ago' => 2, 'stats' => ['targeted' => 8, 'batches' => 1]],
                ],
            ],
            'rochor-noodle-house' => [
                'promotions' => [
                    'dumpling-bogo' => ['name' => 'Buy 2 dumplings, get 1 free', 'description' => 'On any dumpling plate.', 'type' => 'bogo', 'bogo_config' => ['buy_quantity' => 2, 'get_quantity' => 1, 'discount_percent' => 100], 'applies_to' => ['products' => ['rochor-noodle-house:pork-dumplings', 'rochor-noodle-house:veg-dumplings']], 'starts_in_days' => -5, 'ends_in_days' => 25],
                ],
            ],
            'lion-city-cleaning' => [
                'promotions' => [
                    'first-clean' => ['name' => '$20 off your first clean', 'description' => 'For new customers, on any home clean.', 'type' => 'fixed_amount', 'value' => 2000, 'min_subtotal' => 6000, 'first_order_only' => true, 'code' => 'FIRSTCLEAN', 'applies_to' => ['categories' => ['lion-city-cleaning:cleaning']], 'starts_in_days' => -20, 'ends_in_days' => 70],
                ],
            ],
        ];
    }
}
