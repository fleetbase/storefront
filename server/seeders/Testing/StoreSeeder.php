<?php

namespace Fleetbase\Storefront\Seeders\Testing;

use Fleetbase\Models\Company;
use Fleetbase\Storefront\Seeders\Testing\Concerns\SeedsStorefrontFixtures;
use Illuminate\Database\Seeder;

/**
 * Seeds one complete, standalone store for end-to-end Storefront testing.
 *
 * Produces a store with an order config, a store location with opening hours,
 * a sandbox Stripe gateway, product categories, products with variants and addons,
 * a published catalog, customers, an open cart, a pending checkout, a month of
 * captured orders (cash and Stripe, delivery and pickup) and reviews.
 *
 * Re-running the seeder purges and recreates its own fixtures only.
 *
 *   php artisan db:seed --class="Fleetbase\\Storefront\\Seeders\\Testing\\StoreSeeder"
 *
 * Optional environment:
 *   SEED_COMPANY_UUID / SEED_COMPANY_PUBLIC_ID   company to seed into (defaults to the oldest company)
 *   SEED_STRIPE_SECRET_KEY / SEED_STRIPE_PUBLISHABLE_KEY   real Stripe test keys for the seeded gateway
 */
class StoreSeeder extends Seeder
{
    use SeedsStorefrontFixtures;

    public const STORE_KEY = 'fleetbase-market';

    protected function seedName(): string
    {
        return 'storefront-testing-store';
    }

    public function run(): void
    {
        $company = $this->prepareCompany();
        if (!$company) {
            return;
        }

        $this->withoutForeignKeyConstraints(fn () => $this->purgeSeedData());

        $bundle    = $this->seedStore($company, $this->storeDefinition());
        $customers = $this->seedCustomers($company, $this->customerFixtures());
        $orders    = $this->seedStoreActivity($company, $bundle, $customers, null, 30);

        $this->command?->info(sprintf('Seeded Storefront testing store for company %s with %d products and %d orders.', $company->public_id, count($bundle['products']), count($orders)));
        $this->reportStorefront($bundle['store'], $bundle['gateway'], '  ');
    }

    public function purgeSeedData(): void
    {
        $this->purgeStorefrontFixtures();
    }

    protected function storeDefinition(): array
    {
        return [
            'key'         => static::STORE_KEY,
            'name'        => 'Fleetbase Market',
            'description' => 'Neighbourhood grocer used to test the complete Storefront flow: catalog, checkout, payments and delivery.',
            'email'       => 'market@example.test',
            'phone'       => '+65 6100 0100',
            'website'     => 'https://example.test/fleetbase-market',
            'tags'        => ['groceries', 'local', 'testing'],
            'currency'    => 'USD',
            'timezone'    => 'Asia/Singapore',
            'pod_method'  => 'scan',
            'gateway'     => 'stripe',
            'options'     => [
                'auto_accept_orders' => false,
                'auto_dispatch'      => false,
                'require_pod'        => true,
            ],
            'location' => [
                'name'        => 'Fleetbase Market',
                'street1'     => '100 Market Street',
                'city'        => 'Singapore',
                'postal_code' => '048946',
                'country'     => 'SG',
                'lat'         => 1.2835,
                'lng'         => 103.8515,
            ],
            'hours'      => ['start' => '08:00', 'end' => '21:00'],
            'categories' => [
                'produce'   => ['name' => 'Fresh Produce', 'description' => 'Fruit and vegetables for local delivery.', 'icon' => 'apple-whole'],
                'pantry'    => ['name' => 'Pantry Staples', 'description' => 'Shelf-stable goods and household essentials.', 'icon' => 'jar'],
                'beverages' => ['name' => 'Beverages', 'description' => 'Coffee, tea and cold drinks.', 'icon' => 'mug-hot'],
            ],
            'addon_categories' => [
                'gift' => [
                    'name'           => 'Gift Options',
                    'description'    => 'Optional packaging and notes.',
                    'max_selectable' => 2,
                    'is_required'    => false,
                    'addons'         => [
                        ['Gift Wrap', 'Reusable kraft gift wrap.', 350],
                        ['Note Card', 'Handwritten note card.', 150],
                    ],
                ],
                'extras' => [
                    'name'           => 'Extras',
                    'description'    => 'Add-ons for beverages.',
                    'max_selectable' => 3,
                    'is_required'    => false,
                    'addons'         => [
                        ['Oat Milk', 'Swap to oat milk.', 80],
                        ['Extra Shot', 'Additional espresso shot.', 120],
                        ['Ice', 'Served over ice.', 0],
                    ],
                ],
            ],
            'products' => [
                'orchard-box' => [
                    'name'             => 'Orchard Fruit Box',
                    'description'      => 'Seasonal fruit selection packed for same-day delivery.',
                    'price'            => 2850,
                    'category'         => 'produce',
                    'tags'             => ['fruit', 'fresh'],
                    'recommended'      => true,
                    'variants'         => [
                        ['name' => 'Box Size', 'required' => true, 'options' => [['Small', 0], ['Family', 1200]]],
                    ],
                    'addon_categories' => ['gift'],
                ],
                'market-veg' => [
                    'name'        => 'Market Vegetable Bundle',
                    'description' => 'Weekly vegetable bundle with leafy greens and root vegetables.',
                    'price'       => 2250,
                    'sale_price'  => 1950,
                    'category'    => 'produce',
                    'tags'        => ['vegetables', 'bundle'],
                ],
                'herb-kit' => [
                    'name'        => 'Fresh Herb Kit',
                    'description' => 'Basil, coriander, mint and spring onion.',
                    'price'       => 890,
                    'category'    => 'produce',
                    'tags'        => ['herbs'],
                ],
                'coffee-kit' => [
                    'name'             => 'Cold Brew Starter Kit',
                    'description'      => 'Coffee, filters and syrup for pickup or delivery.',
                    'price'            => 3400,
                    'category'         => 'beverages',
                    'tags'             => ['coffee', 'beverage'],
                    'recommended'      => true,
                    'variants'         => [
                        ['name' => 'Grind', 'required' => true, 'options' => [['Whole Bean', 0], ['Coarse Ground', 0]]],
                    ],
                    'addon_categories' => ['gift', 'extras'],
                ],
                'iced-latte' => [
                    'name'             => 'Iced Latte',
                    'description'      => 'Double shot latte over ice.',
                    'price'            => 650,
                    'category'         => 'beverages',
                    'tags'             => ['coffee', 'cold'],
                    'variants'         => [
                        ['name' => 'Size', 'required' => true, 'options' => [['Regular', 0], ['Large', 150]]],
                        ['name' => 'Sweetness', 'required' => false, 'options' => [['No Sugar', 0], ['Less Sugar', 0], ['Regular', 0]]],
                    ],
                    'addon_categories' => ['extras'],
                ],
                'rice-pack' => [
                    'name'        => 'Jasmine Rice Pack',
                    'description' => 'Premium jasmine rice in a delivery-friendly 5kg pack.',
                    'price'       => 1890,
                    'category'    => 'pantry',
                    'tags'        => ['rice', 'pantry'],
                ],
                'olive-oil' => [
                    'name'        => 'Extra Virgin Olive Oil',
                    'description' => 'Cold pressed olive oil, 750ml.',
                    'price'       => 2400,
                    'category'    => 'pantry',
                    'tags'        => ['oil', 'pantry'],
                ],
                'cleaning-bundle' => [
                    'name'        => 'Household Cleaning Bundle',
                    'description' => 'Dish soap, surface spray and sponges.',
                    'price'       => 1650,
                    'category'    => 'pantry',
                    'tags'        => ['household'],
                    'can_pickup'  => false,
                ],
                'unavailable-item' => [
                    'name'         => 'Seasonal Mango Crate',
                    'description'  => 'Out of season. Kept unavailable to test availability handling.',
                    'price'        => 4200,
                    'category'     => 'produce',
                    'tags'         => ['fruit', 'seasonal'],
                    'is_available' => false,
                ],
                'draft-item' => [
                    'name'        => 'Weekend Brunch Box',
                    'description' => 'Unpublished draft product to test status filtering.',
                    'price'       => 3900,
                    'category'    => 'pantry',
                    'status'      => 'draft',
                ],
            ],
            'catalog' => [
                'name'        => 'Everyday Delivery Catalog',
                'description' => 'Published catalog containing the products used by Storefront QA.',
                'status'      => 'published',
                'categories'  => [
                    'Fresh Picks' => ['orchard-box', 'market-veg', 'herb-kit'],
                    'Drinks'      => ['coffee-kit', 'iced-latte'],
                    'Pantry'      => ['rice-pack', 'olive-oil', 'cleaning-bundle'],
                ],
            ],
        ];
    }
}
