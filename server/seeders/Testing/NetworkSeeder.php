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
 * left without a network category to exercise the `without_category` filters. Shared
 * customers place orders across every store so network-level dashboards, order
 * listings and analytics have data.
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
            $bundles[] = $bundle;
        }

        $this->command?->info(sprintf('Seeded Storefront testing network for company %s with %d stores and %d orders.', $company->public_id, count($bundles), $orderCount));
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
        ];
    }
}
