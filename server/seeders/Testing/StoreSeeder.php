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
 * captured orders (cash and Stripe, delivery and pickup), reviews, and promotions,
 * customer segments and campaigns in several states.
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
        $this->createDeliveryServiceRate($company, 'service-rate:' . static::STORE_KEY, $bundle['store']->currency);
        $customers = $this->seedCustomers($company, $this->customerFixtures());
        $orders    = $this->seedStoreActivity($company, $bundle, $customers, null, 30);
        $marketing = $this->seedMarketing($company, $bundle['store'], static::STORE_KEY, $this->marketingDefinition(), [static::STORE_KEY => $bundle]);

        $this->command?->info(sprintf('Seeded Storefront testing store for company %s with %d products and %d orders.', $company->public_id, count($bundle['products']), count($orders)));
        $this->command?->info(sprintf('  Marketing: %d promotions, %d segments, %d campaigns.', count($marketing['promotions']), count($marketing['segments']), count($marketing['campaigns'])));
        $this->reportStorefront($bundle['store'], $bundle['gateway'], '  ');
    }

    public function purgeSeedData(): void
    {
        $this->purgeStorefrontFixtures();
    }

    /**
     * The network seeder uses the same customer fixtures in the same company, so this
     * store's customers get their own emails (`ava.chen+market@example.test`) and phones
     * (`+65 9101 …`).
     */
    protected function customerIdentity(array $fixture): array
    {
        [$name, $email, $phone] = $fixture;

        return [$name, str_replace('@', '+market@', $email), str_replace('+65 9100 ', '+65 9101 ', $phone)];
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
            'currency'    => $this->seedCurrency(),
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

    /**
     * The store's own promotions, segments and campaigns.
     */
    protected function marketingDefinition(): array
    {
        $key = static::STORE_KEY;

        return [
            'promotions' => [
                'coffee-hour'   => ['name' => 'Coffee hour', 'description' => '20% off beverages, every morning 7 to 10.', 'type' => 'percentage', 'value' => 20, 'applies_to' => ['categories' => [$key . ':beverages']], 'schedule' => [['days' => [1, 2, 3, 4, 5, 6, 7], 'start' => '07:00', 'end' => '10:00']], 'starts_in_days' => -14, 'ends_in_days' => 60],
                'market-five'   => ['name' => '$5 off $30', 'description' => 'Use MARKET5 on orders of $30 or more.', 'type' => 'fixed_amount', 'value' => 500, 'min_subtotal' => 3000, 'code' => 'MARKET5', 'usage_limit_per_customer' => 3, 'starts_in_days' => -7, 'ends_in_days' => 30],
                'free-delivery' => ['name' => 'Free delivery for first orders', 'description' => 'Your first delivery is free.', 'type' => 'free_delivery', 'first_order_only' => true, 'starts_in_days' => -30, 'ends_in_days' => 90],
                'harvest'       => ['name' => 'Harvest week', 'description' => '15% off fresh produce (coming soon).', 'type' => 'percentage', 'value' => 15, 'applies_to' => ['categories' => [$key . ':produce']], 'starts_in_days' => 5, 'ends_in_days' => 12],
            ],
            'segments' => [
                'regulars' => ['name' => 'Regulars', 'description' => 'Five or more orders.', 'rules' => ['min_orders' => 5]],
                'lapsed'   => ['name' => 'Lapsed', 'description' => 'No order in the last 21 days.', 'rules' => ['not_ordered_within_days' => 21]],
            ],
            'campaigns' => [
                'coffee-sent'    => ['name' => 'Coffee hour', 'title' => '20% off coffee before 10', 'body' => 'Cold brew and lattes, every morning.', 'status' => 'sent', 'segment' => 'regulars', 'promotion' => 'coffee-hour', 'action' => ['type' => 'promotion'], 'sent_days_ago' => 2, 'stats' => ['targeted' => 6, 'batches' => 1]],
                'harvest-soon'   => ['name' => 'Harvest week', 'title' => 'Harvest week starts soon', 'body' => '15% off fresh produce all week.', 'status' => 'scheduled', 'promotion' => 'harvest', 'action' => ['type' => 'promotion'], 'send_in_days' => 5],
                'winback-draft'  => ['name' => 'Win back (draft)', 'title' => '$5 off your next order', 'body' => 'Use MARKET5 on orders of $30 or more.', 'status' => 'draft', 'segment' => 'lapsed', 'promotion' => 'market-five'],
            ],
        ];
    }
}
