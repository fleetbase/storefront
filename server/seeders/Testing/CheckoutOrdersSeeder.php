<?php

namespace Fleetbase\Storefront\Seeders\Testing;

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\FleetOps\Support\Utils as FleetOpsUtils;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Models\Company;
use Fleetbase\Models\Transaction;
use Fleetbase\Models\TransactionItem;
use Fleetbase\Storefront\Models\Cart;
use Fleetbase\Storefront\Models\Checkout;
use Fleetbase\Storefront\Models\Product;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Seeders\Testing\Concerns\SeedsTestingData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CheckoutOrdersSeeder extends Seeder
{
    use SeedsTestingData;

    public function run(): void
    {
        $company = $this->prepareCompany();
        if (!$company) {
            return;
        }

        $store = $this->seededModel(Store::class, 'store:fleetbase-market');
        if (!$store) {
            $this->call(CatalogAndProductsSeeder::class);
            $store = $this->seededModel(Store::class, 'store:fleetbase-market');
        }

        if (!$store) {
            $this->command?->error('No Storefront demo store was available for checkout/order seeding.');

            return;
        }

        $products = Product::where('meta->seed', static::SEED_NAME)->where('store_uuid', $store->uuid)->get()->keyBy(fn (Product $product) => data_get($product, 'meta.seed_id'));
        if ($products->isEmpty()) {
            $this->command?->error('No Storefront demo products were available for checkout/order seeding.');

            return;
        }

        $customers = collect($this->customerFixtures())->mapWithKeys(function (array $fixture, int $index) use ($company) {
            [$name, $email, $phone] = $fixture;

            return [$index => $this->createCustomer($company, $name, $email, $phone)];
        })->all();
        $productLines = $products->values()->all();

        $this->createOpenCart($company, $store, $customers[0], [
            [$products->get('product:orchard-fruit-box'), 1],
            [$products->get('product:cold-brew-starter-kit'), 1],
        ], 'active-cart');

        $this->createPendingCheckout($company, $store, $customers[1], [
            [$products->get('product:market-vegetable-bundle'), 2],
        ], 'pending-checkout');

        for ($i = 1; $i <= 30; $i++) {
            $customer    = $customers[($i - 1) % count($customers)];
            $first       = $productLines[($i - 1) % count($productLines)];
            $second      = $productLines[$i % count($productLines)];
            $isPickup    = $i % 5 === 0;
            $deliveryFee = $isPickup ? 0 : 500 + (($i % 4) * 125);

            $this->createCompletedOrder($company, $store, $customer, [
                [$first, ($i % 3) + 1],
                [$second, ($i % 2) + 1],
            ], 'completed-order-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), $isPickup, $deliveryFee, $i);
        }
    }

    public function purgeSeedData(): void
    {
        $checkoutUuids    = $this->seededUuids(Checkout::class);
        $cartUuids        = $this->seededUuids(Cart::class);
        $transactionUuids = $this->seededUuids(Transaction::class);

        $this->deleteFrom($this->fleetbaseConnection(), 'transaction_items', fn ($query) => $query->whereIn('transaction_uuid', $transactionUuids)->orWhere('meta->seed', static::SEED_NAME));
        $this->purgeModel(Entity::class);
        $this->purgeModel(Order::class);
        $this->purgeModel(ServiceQuote::class);
        $this->purgeModel(Payload::class);
        $this->purgeModel(Transaction::class);
        $this->purgeModel(Place::class);

        DB::connection($this->storefrontConnection())->table('carts')
            ->whereIn('uuid', $cartUuids)
            ->orWhereIn('checkout_uuid', $checkoutUuids)
            ->update(['checkout_uuid' => null]);
        DB::connection($this->storefrontConnection())->table('checkouts')
            ->whereIn('uuid', $checkoutUuids)
            ->orWhereIn('cart_uuid', $cartUuids)
            ->update(['cart_uuid' => null, 'order_uuid' => null]);

        $this->deleteFrom($this->storefrontConnection(), 'carts', fn ($query) => $query->whereIn('uuid', $cartUuids));
        $this->deleteFrom($this->storefrontConnection(), 'checkouts', fn ($query) => $query->whereIn('uuid', $checkoutUuids));
        $this->purgeModel(Contact::class);
    }

    protected function customerFixtures(): array
    {
        return [
            ['Ava Chen', 'ava.chen@example.test', '+1 555 0201'],
            ['Ben Ortiz', 'ben.ortiz@example.test', '+1 555 0202'],
            ['Mia Brooks', 'mia.brooks@example.test', '+1 555 0203'],
            ['Noah Patel', 'noah.patel@example.test', '+1 555 0204'],
            ['Emma Johnson', 'emma.johnson@example.test', '+1 555 0205'],
            ['Liam Garcia', 'liam.garcia@example.test', '+1 555 0206'],
            ['Olivia Smith', 'olivia.smith@example.test', '+1 555 0207'],
            ['Lucas Brown', 'lucas.brown@example.test', '+1 555 0208'],
            ['Sophia Davis', 'sophia.davis@example.test', '+1 555 0209'],
            ['Ethan Wilson', 'ethan.wilson@example.test', '+1 555 0210'],
            ['Amelia Martinez', 'amelia.martinez@example.test', '+1 555 0211'],
            ['Mason Lee', 'mason.lee@example.test', '+1 555 0212'],
            ['Isabella Taylor', 'isabella.taylor@example.test', '+1 555 0213'],
            ['James Anderson', 'james.anderson@example.test', '+1 555 0214'],
            ['Charlotte Thomas', 'charlotte.thomas@example.test', '+1 555 0215'],
            ['Henry Moore', 'henry.moore@example.test', '+1 555 0216'],
            ['Harper Jackson', 'harper.jackson@example.test', '+1 555 0217'],
            ['Alexander White', 'alexander.white@example.test', '+1 555 0218'],
            ['Evelyn Harris', 'evelyn.harris@example.test', '+1 555 0219'],
            ['Daniel Martin', 'daniel.martin@example.test', '+1 555 0220'],
        ];
    }

    protected function createCustomer(Company $company, string $name, string $email, string $phone): Contact
    {
        $seedId = 'customer:' . str($name)->slug();

        return $this->createRecord(Contact::class, [
            '_key'         => $this->fixtureKey($seedId),
            'company_uuid' => $company->uuid,
            'name'         => $name,
            'email'        => $email,
            'phone'        => $phone,
            'type'         => 'customer',
            'notes'        => 'Storefront demo customer.',
            'meta'         => $this->meta($seedId),
        ]);
    }

    protected function createOpenCart(Company $company, Store $store, Contact $customer, array $lines, string $seedId): Cart
    {
        return $this->createCart($company, $store, $customer, $lines, $seedId, [
            [
                'code'       => 'cart_created',
                'message'    => 'Demo cart created.',
                'created_at' => $this->timestamp(1)->toISOString(),
            ],
        ]);
    }

    protected function createPendingCheckout(Company $company, Store $store, Contact $customer, array $lines, string $seedId): Checkout
    {
        $cart = $this->createCart($company, $store, $customer, $lines, $seedId . ':cart', [
            [
                'code'       => 'checkout_initialized',
                'message'    => 'Demo checkout initialized.',
                'created_at' => $this->timestamp(2)->toISOString(),
            ],
        ]);

        $checkout = $this->createRecord(Checkout::class, [
            'company_uuid' => $company->uuid,
            'store_uuid'   => $store->uuid,
            'cart_uuid'    => $cart->uuid,
            'owner_uuid'   => $customer->uuid,
            'owner_type'   => FleetOpsUtils::getMutationType('fleet-ops:contact'),
            'amount'       => $cart->subtotal,
            'currency'     => 'USD',
            'is_cod'       => true,
            'is_pickup'    => false,
            'options'      => $this->meta($seedId),
            'cart_state'   => $cart->items,
            'captured'     => false,
        ]);

        $cart->update(['checkout_uuid' => $checkout->uuid]);

        return $checkout;
    }

    protected function createCompletedOrder(Company $company, Store $store, Contact $customer, array $lines, string $seedId, bool $pickup, int $deliveryFee, int $sequence): Order
    {
        $cart = $this->createCart($company, $store, $customer, $lines, $seedId . ':cart', [
            [
                'code'       => 'checkout_captured',
                'message'    => 'Demo checkout captured.',
                'created_at' => $this->timestamp(3)->toISOString(),
            ],
        ]);

        $total = $cart->subtotal + $deliveryFee;

        $serviceQuote = $pickup ? null : $this->createServiceQuote($company, $seedId, $deliveryFee);

        $checkout = $this->createRecord(Checkout::class, [
            'company_uuid'       => $company->uuid,
            'store_uuid'         => $store->uuid,
            'cart_uuid'          => $cart->uuid,
            'service_quote_uuid' => $serviceQuote?->uuid,
            'owner_uuid'         => $customer->uuid,
            'owner_type'         => 'fleet-ops:contact',
            'amount'             => $total,
            'currency'           => 'USD',
            'is_cod'             => true,
            'is_pickup'          => $pickup,
            'options'            => $this->meta($seedId),
            'cart_state'         => $cart->items,
            'captured'           => true,
        ]);

        $pickupPlace = $this->createPlace($company, $store->name, '100 Market Street', 'Singapore', 'SG', 1.2835, 103.8515, $seedId . ':pickup');
        $dropoff     = $this->createPlace($company, $customer->name, $pickup ? '100 Market Street' : '18 Orchard Road', 'Singapore', 'SG', 1.3048, 103.8318, $seedId . ':dropoff');

        $payload = $this->createRecord(Payload::class, [
            '_key'           => $this->fixtureKey($seedId . ':payload'),
            'company_uuid'   => $company->uuid,
            'pickup_uuid'    => $pickupPlace->uuid,
            'dropoff_uuid'   => $dropoff->uuid,
            'return_uuid'    => $pickupPlace->uuid,
            'payment_method' => 'cash',
            'cod_amount'     => $total,
            'cod_currency'   => 'USD',
            'type'           => 'storefront',
            'meta'           => $this->meta($seedId . ':payload'),
        ]);

        if ($serviceQuote) {
            $serviceQuote->update(['payload_uuid' => $payload->uuid]);
        }

        $this->createEntities($company, $payload, $customer, $cart, $dropoff, $seedId);

        $transaction = $this->createRecord(Transaction::class, [
            'company_uuid'           => $company->uuid,
            'customer_uuid'          => $customer->uuid,
            'customer_type'          => FleetOpsUtils::getMutationType('fleet-ops:contact'),
            'gateway_transaction_id' => 'sf-demo-' . str($seedId)->slug('-'),
            'gateway'                => 'cash',
            'amount'                 => $total,
            'net_amount'             => $total,
            'currency'               => 'USD',
            'description'            => 'Storefront demo order',
            'type'                   => 'storefront',
            'direction'              => 'credit',
            'status'                 => 'success',
            'meta'                   => $this->meta($seedId . ':transaction', [
                'storefront'    => $store->name,
                'storefront_id' => $store->public_id,
            ]),
        ]);

        $this->createTransactionItems($transaction, $cart, $deliveryFee);

        $order = $this->createRecord(Order::class, [
            '_key'              => $this->fixtureKey($seedId),
            'company_uuid'      => $company->uuid,
            'payload_uuid'      => $payload->uuid,
            'customer_uuid'     => $customer->uuid,
            'customer_type'     => FleetOpsUtils::getMutationType('fleet-ops:contact'),
            'transaction_uuid'  => $transaction->uuid,
            'order_config_uuid' => $store->getOrderConfigId(),
            'adhoc'             => false,
            'type'              => 'storefront',
            'status'            => $this->orderStatus($sequence, $pickup),
            'meta'              => $this->meta($seedId, [
                'storefront'    => $store->name,
                'storefront_id' => $store->public_id,
                'checkout_id'   => $checkout->public_id,
                'subtotal'      => $cart->subtotal,
                'delivery_fee'  => $deliveryFee,
                'tip'           => null,
                'delivery_tip'  => null,
                'total'         => $total,
                'currency'      => 'USD',
                'gateway'       => 'cash',
                'require_pod'   => true,
                'pod_method'    => $store->pod_method,
                'is_pickup'     => $pickup,
            ]),
            'notes'             => 'Seeded Storefront demo order.',
        ]);

        $checkout->update(['order_uuid' => $order->uuid]);
        $cart->update(['checkout_uuid' => $checkout->uuid]);

        return $order;
    }

    protected function createServiceQuote(Company $company, string $seedId, int $deliveryFee): ServiceQuote
    {
        return $this->createRecord(ServiceQuote::class, [
            '_key'         => $this->fixtureKey($seedId . ':service-quote'),
            'company_uuid' => $company->uuid,
            'amount'       => $deliveryFee,
            'currency'     => 'USD',
            'meta'         => $this->meta($seedId . ':service-quote', [
                'origin'      => [
                    'name'     => 'Fleetbase Market',
                    'street1'  => '100 Market Street',
                    'city'     => 'Singapore',
                    'country'  => 'SG',
                    'location' => ['latitude' => 1.2835, 'longitude' => 103.8515],
                ],
                'destination' => [
                    'name'     => 'Customer Address',
                    'street1'  => '18 Orchard Road',
                    'city'     => 'Singapore',
                    'country'  => 'SG',
                    'location' => ['latitude' => 1.3048, 'longitude' => 103.8318],
                ],
            ]),
            'expired_at'   => now()->addDays(14),
        ]);
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

    protected function createCart(Company $company, Store $store, Contact $customer, array $lines, string $seedId, array $events): Cart
    {
        $items = collect($lines)->filter(fn ($line) => $line[0] instanceof Product)->map(function ($line) use ($store) {
            /** @var Product $product */
            [$product, $quantity] = $line;
            $subtotal             = (int) $product->price * (int) $quantity;

            return [
                'id'          => $product->public_id,
                'product_id'  => $product->public_id,
                'store_id'    => $store->public_id,
                'name'        => $product->name,
                'sku'         => $product->sku,
                'price'       => (int) $product->price,
                'currency'    => $product->currency,
                'quantity'    => (int) $quantity,
                'subtotal'    => $subtotal,
                'variants'    => [],
                'addons'      => [],
            ];
        })->values()->all();

        return $this->createRecord(Cart::class, [
            'company_uuid'       => $company->uuid,
            'customer_id'        => $customer->public_id,
            'unique_identifier'  => $this->fixtureKey($seedId),
            'currency'           => 'USD',
            'items'              => $items,
            'events'             => $events,
            'expires_at'         => now()->addDays(14),
        ]);
    }

    protected function createPlace(Company $company, string $name, string $street, string $city, string $country, float $lat, float $lng, string $seedId): Place
    {
        return $this->createRecord(Place::class, [
            '_key'         => $this->fixtureKey($seedId),
            'company_uuid' => $company->uuid,
            'name'         => $name,
            'type'         => 'storefront',
            'street1'      => $street,
            'city'         => $city,
            'country'      => $country,
            'location'     => new Point($lat, $lng),
            'meta'         => $this->meta($seedId),
        ]);
    }

    protected function createEntities(Company $company, Payload $payload, Contact $customer, Cart $cart, Place $destination, string $seedId): void
    {
        foreach ($cart->items as $index => $item) {
            $this->createRecord(Entity::class, [
                '_key'             => $this->fixtureKey($seedId . ':entity:' . ($index + 1)),
                'company_uuid'     => $company->uuid,
                'payload_uuid'     => $payload->uuid,
                'customer_uuid'    => $customer->uuid,
                'customer_type'    => FleetOpsUtils::getMutationType('fleet-ops:contact'),
                'destination_uuid' => $destination->uuid,
                'internal_id'      => data_get($item, 'product_id'),
                'name'             => data_get($item, 'name'),
                'type'             => 'storefront_product',
                'description'      => 'Storefront demo order item.',
                'currency'         => data_get($item, 'currency', 'USD'),
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
            ], true);
        }
    }

    protected function createTransactionItems(Transaction $transaction, Cart $cart, int $deliveryFee): void
    {
        foreach ($cart->items as $index => $item) {
            $this->createRecord(TransactionItem::class, [
                'transaction_uuid' => $transaction->uuid,
                'quantity'         => Arr::get((array) $item, 'quantity', 1),
                'unit_price'       => Arr::get((array) $item, 'price', 0),
                'amount'           => Arr::get((array) $item, 'subtotal', 0),
                'currency'         => 'USD',
                'details'          => Arr::get((array) $item, 'name', 'Storefront item'),
                'description'      => Arr::get((array) $item, 'name', 'Storefront item'),
                'code'             => 'product',
                'sort_order'       => $index,
                'meta'             => $this->meta('transaction-item:' . $transaction->uuid . ':' . $index),
            ]);
        }

        if ($deliveryFee > 0) {
            $this->createRecord(TransactionItem::class, [
                'transaction_uuid' => $transaction->uuid,
                'quantity'         => 1,
                'unit_price'       => $deliveryFee,
                'amount'           => $deliveryFee,
                'currency'         => 'USD',
                'details'          => 'Delivery fee',
                'description'      => 'Delivery fee',
                'code'             => 'delivery_fee',
                'sort_order'       => 99,
                'meta'             => $this->meta('transaction-item:' . $transaction->uuid . ':delivery'),
            ]);
        }
    }
}
