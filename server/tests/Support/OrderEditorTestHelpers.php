<?php

namespace Fleetbase\Storefront\Support {
    // The suite runs without Laravel's session store and exception handler: the global
    // `session()` stub has no `forget()`, and `report()` is not defined at all. These
    // namespace-level shims give the Support classes what the framework gives them in
    // production (a session that forgets keys, and a reporter that logs and carries on);
    // every other call still goes to the global stubs.
    if (!function_exists(__NAMESPACE__ . '\session')) {
        function session($key = null, $default = null)
        {
            if ($key !== null) {
                return \session($key, $default);
            }

            return new class {
                public function forget($keys): void
                {
                    \session(array_fill_keys((array) $keys, null));
                }

                public function get(string $key, $default = null)
                {
                    return \session($key, $default);
                }
            };
        }
    }

    if (!function_exists(__NAMESPACE__ . '\report')) {
        function report(\Throwable $exception): void
        {
            $GLOBALS['orderEditorCoverageReported'][] = $exception;
        }
    }
}

namespace Fleetbase\FleetOps\Events {
    // Fleet-Ops releases before `order.changed` have no OrderChanged event; the editor then
    // skips the broadcast. Stand one in that records what would have been broadcast.
    if (!class_exists(OrderChanged::class)) {
        class OrderChanged
        {
            public static array $orderEditorCoverageDispatched = [];

            public static function dispatchFor($order, array $changes, string $summary = '', array $extra = [], bool $notifyDriver = true): ?self
            {
                static::$orderEditorCoverageDispatched[] = ['order' => $order->public_id, 'changes' => $changes, 'summary' => $summary, 'extra' => $extra, 'notify_driver' => $notifyDriver];

                return null;
            }
        }
    }
}

namespace {
    use Fleetbase\FleetOps\Models\Order;
    use Fleetbase\Storefront\Http\Controllers\v1\ServiceQuoteController;
    use Fleetbase\Storefront\Http\Requests\GetServiceQuoteFromCart;
    use Fleetbase\Storefront\Promotions\PromotionCalculator;
    use Fleetbase\Storefront\Promotions\PromotionContext;
    use Fleetbase\Storefront\Promotions\PromotionEngine;
    use Fleetbase\Storefront\Promotions\PromotionResult;
    use Fleetbase\Storefront\Support\Inventory;
    use Illuminate\Database\Eloquent\Model;

    if (!class_exists('OrderEditorCoverageQuoteController')) {
        /** The store's quote endpoint, answering (or failing) with whatever the test sets. */
        class OrderEditorCoverageQuoteController extends ServiceQuoteController
        {
            /** @var mixed|Closure */
            public static $response;
            public static array $requests = [];

            public function fromCart(GetServiceQuoteFromCart $request)
            {
                static::$requests[] = $request->all();
                if (static::$response instanceof Throwable) {
                    throw static::$response;
                }

                return static::$response instanceof Closure ? (static::$response)($request) : static::$response;
            }
        }
    }

    if (!class_exists('OrderEditorCoverageCreatingDispatcher')) {
        /** Model events limited to `creating`, where models get their uuid and public id. */
        class OrderEditorCoverageCreatingDispatcher extends Illuminate\Events\Dispatcher
        {
            public function listen($events, $listener = null)
            {
                $events = array_values(array_filter((array) $events, fn ($event) => is_string($event) && str_starts_with($event, 'eloquent.creating: ')));
                if ($events) {
                    parent::listen($events, $listener);
                }
            }
        }
    }

    if (!class_exists('OrderEditorCoveragePromotionEngine')) {
        /** The promotion engine, answering with a set result and keeping what it was asked. */
        class OrderEditorCoveragePromotionEngine extends PromotionEngine
        {
            public ?PromotionResult $result = null;
            public ?Throwable $failure      = null;
            public array $calls             = [];

            public function __construct()
            {
                parent::__construct(new PromotionCalculator());
            }

            public function evaluate(PromotionContext $context, array $codes = []): PromotionResult
            {
                $this->calls[] = ['context' => $context, 'codes' => $codes];
                if ($this->failure) {
                    throw $this->failure;
                }

                return $this->result ?? new PromotionResult();
            }
        }
    }

    if (!function_exists('orderEditorCoverageDb')) {
        function orderEditorCoverageDb()
        {
            return Model::getConnectionResolver()->connection('mysql');
        }

        /**
         * The tables an order edit reads and writes, recreated empty.
         */
        function orderEditorCoverageSchema(): void
        {
            $schema = orderEditorCoverageDb()->getSchemaBuilder();
            foreach (['stores', 'store_locations', 'store_hours', 'products', 'product_hours', 'files', 'orders', 'payloads', 'places', 'waypoints', 'entities', 'contacts', 'drivers', 'users', 'transactions', 'checkouts', 'gateways', 'carts', 'promotions', 'promotion_codes', 'promotion_redemptions', 'companies', 'inventory_reservations', 'inventory_movements', 'product_inventories', 'tracking_numbers', 'custom_field_values'] as $table) {
                $schema->dropIfExists($table);
            }

            $schema->create('stores', function ($table) {
                $table->increments('id');
                $table->string('uuid');
                $table->string('public_id')->nullable();
                $table->string('company_uuid')->nullable();
                $table->string('key')->nullable();
                $table->string('name')->nullable();
                $table->string('currency')->nullable();
                $table->string('timezone')->nullable();
                $table->text('options')->nullable();
                $table->timestamps();
                $table->timestamp('deleted_at')->nullable();
            });
            $schema->create('store_locations', function ($table) {
                $table->increments('id');
                $table->string('uuid');
                $table->string('public_id')->nullable();
                $table->string('store_uuid')->nullable();
                $table->string('place_uuid')->nullable();
                $table->string('name')->nullable();
                $table->timestamps();
                $table->timestamp('deleted_at')->nullable();
            });
            $schema->create('store_hours', function ($table) {
                $table->increments('id');
                $table->string('uuid')->nullable();
                $table->string('store_location_uuid')->nullable();
                $table->string('day_of_week')->nullable();
                $table->string('start')->nullable();
                $table->string('end')->nullable();
                $table->timestamps();
                $table->timestamp('deleted_at')->nullable();
            });
            $schema->create('products', function ($table) {
                $table->increments('id');
                $table->string('uuid');
                $table->string('public_id')->nullable();
                $table->string('company_uuid')->nullable();
                $table->string('store_uuid')->nullable();
                $table->string('category_uuid')->nullable();
                $table->string('primary_image_uuid')->nullable();
                $table->string('name')->nullable();
                $table->text('description')->nullable();
                $table->string('currency')->nullable();
                $table->string('sku')->nullable();
                $table->integer('price')->nullable();
                $table->integer('sale_price')->nullable();
                $table->boolean('is_on_sale')->default(false);
                $table->boolean('is_bookable')->default(false);
                $table->boolean('is_available')->default(true);
                $table->boolean('track_inventory')->default(false);
                $table->string('status')->nullable();
                $table->text('meta')->nullable();
                $table->timestamps();
                $table->timestamp('deleted_at')->nullable();
            });
            $schema->create('product_hours', function ($table) {
                $table->increments('id');
                $table->string('uuid')->nullable();
                $table->string('product_uuid')->nullable();
                $table->string('day_of_week')->nullable();
                $table->string('start')->nullable();
                $table->string('end')->nullable();
                $table->timestamps();
                $table->timestamp('deleted_at')->nullable();
            });
            $schema->create('files', function ($table) {
                $table->increments('id');
                $table->string('uuid')->nullable();
                $table->string('subject_uuid')->nullable();
                $table->string('subject_type')->nullable();
                $table->timestamps();
                $table->timestamp('deleted_at')->nullable();
            });
            $schema->create('orders', function ($table) {
                $table->increments('id');
                $table->string('_key')->nullable();
                $table->string('uuid');
                $table->string('public_id')->nullable();
                $table->string('internal_id')->nullable();
                $table->string('company_uuid')->nullable();
                $table->string('customer_uuid')->nullable();
                $table->string('customer_type')->nullable();
                $table->string('payload_uuid')->nullable();
                $table->string('transaction_uuid')->nullable();
                $table->string('driver_assigned_uuid')->nullable();
                $table->string('tracking_number_uuid')->nullable();
                $table->string('status')->nullable();
                $table->string('type')->nullable();
                $table->text('notes')->nullable();
                $table->text('meta')->nullable();
                $table->text('options')->nullable();
                $table->integer('orchestrator_priority')->nullable();
                $table->timestamp('scheduled_at')->nullable();
                $table->timestamps();
                $table->timestamp('deleted_at')->nullable();
            });
            $schema->create('payloads', function ($table) {
                $table->increments('id');
                $table->string('_key')->nullable();
                $table->string('uuid');
                $table->string('public_id')->nullable();
                $table->string('company_uuid')->nullable();
                $table->string('pickup_uuid')->nullable();
                $table->string('dropoff_uuid')->nullable();
                $table->string('return_uuid')->nullable();
                $table->string('current_waypoint_uuid')->nullable();
                $table->integer('cod_amount')->nullable();
                $table->string('cod_currency')->nullable();
                $table->string('cod_payment_method')->nullable();
                $table->string('payment_method')->nullable();
                $table->text('meta')->nullable();
                $table->timestamps();
                $table->timestamp('deleted_at')->nullable();
            });
            $schema->create('places', function ($table) {
                $table->increments('id');
                $table->string('_key')->nullable();
                $table->string('uuid')->nullable();
                $table->string('public_id')->nullable();
                $table->string('company_uuid')->nullable();
                $table->string('owner_uuid')->nullable();
                $table->string('owner_type')->nullable();
                $table->string('name')->nullable();
                $table->string('street1')->nullable();
                $table->string('street2')->nullable();
                $table->string('city')->nullable();
                $table->string('province')->nullable();
                $table->string('postal_code')->nullable();
                $table->string('neighborhood')->nullable();
                $table->string('district')->nullable();
                $table->string('building')->nullable();
                $table->string('security_access_code')->nullable();
                $table->string('country')->nullable();
                $table->string('phone')->nullable();
                $table->text('location')->nullable();
                $table->text('meta')->nullable();
                $table->timestamps();
                $table->timestamp('deleted_at')->nullable();
            });
            $schema->create('waypoints', function ($table) {
                $table->increments('id');
                $table->string('uuid')->nullable();
                $table->string('payload_uuid')->nullable();
                $table->string('place_uuid')->nullable();
                $table->integer('order')->default(0);
                $table->timestamps();
                $table->timestamp('deleted_at')->nullable();
            });
            $schema->create('entities', function ($table) {
                $table->increments('id');
                $table->string('_key')->nullable();
                $table->string('uuid')->nullable();
                $table->string('public_id')->nullable();
                $table->string('internal_id')->nullable();
                $table->string('company_uuid')->nullable();
                $table->string('payload_uuid')->nullable();
                $table->string('customer_uuid')->nullable();
                $table->string('customer_type')->nullable();
                $table->string('tracking_number_uuid')->nullable();
                $table->string('photo_uuid')->nullable();
                $table->string('name')->nullable();
                $table->text('description')->nullable();
                $table->string('currency')->nullable();
                $table->string('sku')->nullable();
                $table->integer('price')->nullable();
                $table->integer('sale_price')->nullable();
                $table->text('qr_code')->nullable();
                $table->text('barcode')->nullable();
                $table->text('meta')->nullable();
                $table->timestamps();
                $table->timestamp('deleted_at')->nullable();
            });
            $schema->create('contacts', function ($table) {
                $table->increments('id');
                $table->string('_key')->nullable();
                $table->string('uuid');
                $table->string('public_id')->nullable();
                $table->string('company_uuid')->nullable();
                $table->string('user_uuid')->nullable();
                $table->string('name')->nullable();
                $table->string('phone')->nullable();
                $table->string('email')->nullable();
                $table->string('type')->nullable();
                $table->string('slug')->nullable();
                $table->text('meta')->nullable();
                $table->timestamps();
                $table->timestamp('deleted_at')->nullable();
            });
            $schema->create('drivers', function ($table) {
                $table->increments('id');
                $table->string('uuid');
                $table->string('public_id')->nullable();
                $table->string('user_uuid')->nullable();
                $table->timestamps();
                $table->timestamp('deleted_at')->nullable();
            });
            $schema->create('users', function ($table) {
                $table->increments('id');
                $table->string('uuid');
                $table->string('public_id')->nullable();
                $table->string('name')->nullable();
                $table->timestamps();
                $table->timestamp('deleted_at')->nullable();
            });
            $schema->create('transactions', function ($table) {
                $table->increments('id');
                $table->string('_key')->nullable();
                $table->string('uuid')->nullable();
                $table->string('public_id')->nullable();
                $table->string('company_uuid')->nullable();
                $table->string('customer_uuid')->nullable();
                $table->string('customer_type')->nullable();
                $table->string('gateway')->nullable();
                $table->string('gateway_uuid')->nullable();
                $table->string('gateway_transaction_id')->nullable();
                $table->integer('amount')->nullable();
                $table->string('currency')->nullable();
                $table->string('description')->nullable();
                $table->string('type')->nullable();
                $table->string('direction')->nullable();
                $table->string('status')->nullable();
                $table->string('settlement_status')->nullable();
                $table->timestamp('settled_at')->nullable();
                $table->integer('settled_amount')->nullable();
                $table->string('settled_currency')->nullable();
                $table->text('meta')->nullable();
                $table->timestamps();
                $table->timestamp('deleted_at')->nullable();
            });
            $schema->create('checkouts', function ($table) {
                $table->increments('id');
                $table->string('uuid')->nullable();
                $table->string('public_id')->nullable();
                $table->string('order_uuid')->nullable();
                $table->string('gateway_uuid')->nullable();
                $table->string('stripe_payment_intent_id')->nullable();
                $table->text('options')->nullable();
                $table->timestamps();
                $table->timestamp('deleted_at')->nullable();
            });
            $schema->create('gateways', function ($table) {
                $table->increments('id');
                $table->string('uuid')->nullable();
                $table->string('public_id')->nullable();
                $table->string('code')->nullable();
                $table->text('config')->nullable();
                $table->timestamps();
                $table->timestamp('deleted_at')->nullable();
            });
            $schema->create('carts', function ($table) {
                $table->increments('id');
                $table->string('_key')->nullable();
                $table->string('uuid')->nullable();
                $table->string('public_id')->nullable();
                $table->string('company_uuid')->nullable();
                $table->string('user_uuid')->nullable();
                $table->string('checkout_uuid')->nullable();
                $table->string('status')->nullable();
                $table->string('customer_id')->nullable();
                $table->string('unique_identifier')->nullable();
                $table->string('currency')->nullable();
                $table->string('discount_code')->nullable();
                $table->text('items')->nullable();
                $table->text('events')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
                $table->timestamp('deleted_at')->nullable();
            });
            $schema->create('promotion_redemptions', function ($table) {
                $table->increments('id');
                $table->string('uuid')->nullable();
                $table->string('company_uuid')->nullable();
                $table->string('promotion_uuid')->nullable();
                $table->string('promotion_code_uuid')->nullable();
                $table->string('customer_uuid')->nullable();
                $table->string('checkout_uuid')->nullable();
                $table->string('order_uuid')->nullable();
                $table->integer('amount')->default(0);
                $table->string('currency')->nullable();
                $table->string('status')->nullable();
                $table->timestamp('redeemed_at')->nullable();
                $table->timestamps();
                $table->timestamp('deleted_at')->nullable();
            });
            $schema->create('promotions', function ($table) {
                $table->increments('id');
                $table->string('uuid')->nullable();
                $table->string('public_id')->nullable();
                $table->string('company_uuid')->nullable();
                $table->string('created_by_uuid')->nullable();
                $table->string('owner_uuid')->nullable();
                $table->string('owner_type')->nullable();
                $table->string('name')->nullable();
                $table->text('description')->nullable();
                $table->string('status')->nullable();
                $table->string('trigger')->nullable();
                $table->string('type')->nullable();
                $table->decimal('value', 12, 2)->nullable();
                $table->string('currency')->nullable();
                $table->integer('usage_limit')->nullable();
                $table->integer('usage_limit_per_customer')->nullable();
                $table->boolean('stackable')->default(false);
                $table->boolean('is_public')->default(true);
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->text('meta')->nullable();
                $table->timestamps();
                $table->timestamp('deleted_at')->nullable();
            });
            $schema->create('promotion_codes', function ($table) {
                $table->increments('id');
                $table->string('uuid')->nullable();
                $table->string('public_id')->nullable();
                $table->string('company_uuid')->nullable();
                $table->string('promotion_uuid')->nullable();
                $table->string('code');
                $table->string('status')->nullable();
                $table->integer('usage_limit')->nullable();
                $table->string('customer_uuid')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->text('meta')->nullable();
                $table->timestamps();
                $table->timestamp('deleted_at')->nullable();
            });
            $schema->create('companies', function ($table) {
                $table->increments('id');
                $table->string('uuid');
                $table->string('timezone')->nullable();
                $table->timestamps();
                $table->timestamp('deleted_at')->nullable();
            });
            $schema->create('custom_field_values', function ($table) {
                $table->increments('id');
                $table->string('uuid')->nullable();
                $table->string('subject_uuid')->nullable();
                $table->string('subject_type')->nullable();
                $table->string('custom_field_uuid')->nullable();
                $table->text('value')->nullable();
                $table->timestamps();
                $table->timestamp('deleted_at')->nullable();
            });

            // Stock is not tracked unless a test sets it up.
            Inventory::flushEnabled();
        }

        /**
         * A placed storefront order: Tea ×2 and Cake ×1 delivered from the bakery to the
         * customer's home, paid by card, with every override a test needs.
         */
        function orderEditorCoverageOrder(array $order = [], array $meta = [], array $payload = [], ?array $entities = null): Order
        {
            $db  = orderEditorCoverageDb();
            $now = now()->toDateTimeString();

            $db->table('stores')->insert(['uuid' => 'store_uuid', 'public_id' => 'store_public', 'company_uuid' => 'company_uuid', 'key' => 'store_editor_key', 'name' => 'Corner Bakery', 'currency' => 'USD', 'timezone' => 'Asia/Singapore', 'options' => '{}', 'created_at' => $now]);
            $db->table('products')->insert([
                ['uuid' => 'tea_uuid', 'public_id' => 'product_tea', 'company_uuid' => 'company_uuid', 'store_uuid' => 'store_uuid', 'category_uuid' => 'drinks_uuid', 'name' => 'Tea', 'currency' => 'USD', 'price' => 500, 'meta' => '{}', 'created_at' => $now],
                ['uuid' => 'cake_uuid', 'public_id' => 'product_cake', 'company_uuid' => 'company_uuid', 'store_uuid' => 'store_uuid', 'category_uuid' => 'bakes_uuid', 'name' => 'Cake', 'currency' => 'USD', 'price' => 800, 'meta' => '{}', 'created_at' => $now],
                ['uuid' => 'bun_uuid', 'public_id' => 'product_bun', 'company_uuid' => 'company_uuid', 'store_uuid' => 'store_uuid', 'category_uuid' => 'bakes_uuid', 'name' => 'Bun', 'currency' => 'USD', 'price' => 300, 'meta' => '{}', 'created_at' => $now],
                ['uuid' => 'foreign_uuid', 'public_id' => 'product_foreign', 'company_uuid' => 'company_uuid', 'store_uuid' => 'other_store_uuid', 'category_uuid' => null, 'name' => 'Elsewhere', 'currency' => 'USD', 'price' => 100, 'meta' => '{}', 'created_at' => $now],
            ]);
            $db->table('places')->insert([
                ['uuid' => 'pickup_uuid', 'public_id' => 'place_bakery', 'company_uuid' => 'company_uuid', 'name' => 'Corner Bakery', 'street1' => '1 Bakery Lane', 'created_at' => $now],
                ['uuid' => 'home_uuid', 'public_id' => 'place_home', 'company_uuid' => 'company_uuid', 'name' => 'Home', 'street1' => '5 Home Street', 'created_at' => $now],
                ['uuid' => 'office_uuid', 'public_id' => 'place_office', 'company_uuid' => 'company_uuid', 'name' => 'Office', 'street1' => '9 Office Road', 'created_at' => $now],
            ]);
            $db->table('contacts')->insert(['uuid' => 'contact_uuid', 'public_id' => 'contact_public', 'company_uuid' => 'company_uuid', 'name' => 'Ada Lovelace', 'phone' => '+6590000000', 'email' => 'ada@example.com', 'type' => 'customer', 'created_at' => $now]);
            $db->table('payloads')->insert(array_merge(['uuid' => 'payload_uuid', 'public_id' => 'payload_public', 'company_uuid' => 'company_uuid', 'pickup_uuid' => 'pickup_uuid', 'dropoff_uuid' => 'home_uuid', 'created_at' => $now], $payload));

            $entities ??= [
                ['public_id' => 'entity_tea', 'name' => 'Tea', 'meta' => ['product_id' => 'product_tea', 'quantity' => 2, 'subtotal' => 1000, 'variants' => [], 'addons' => []]],
                ['public_id' => 'entity_cake', 'name' => 'Cake', 'meta' => ['product_id' => 'product_cake', 'quantity' => 1, 'subtotal' => 800, 'variants' => [], 'addons' => []]],
            ];
            foreach ($entities as $index => $entity) {
                $db->table('entities')->insert([
                    'uuid'         => $entity['uuid'] ?? $entity['public_id'] . '_uuid',
                    'public_id'    => $entity['public_id'],
                    'company_uuid' => 'company_uuid',
                    'payload_uuid' => 'payload_uuid',
                    'name'         => $entity['name'],
                    'meta'         => json_encode($entity['meta']),
                    'created_at'   => now()->addSeconds($index)->toDateTimeString(),
                ]);
            }

            $db->table('orders')->insert(array_merge([
                'uuid'          => 'order_uuid',
                'public_id'     => 'order_public',
                'company_uuid'  => 'company_uuid',
                'customer_uuid' => 'contact_uuid',
                'customer_type' => Fleetbase\FleetOps\Models\Contact::class,
                'payload_uuid'  => 'payload_uuid',
                'status'        => 'created',
                'meta'          => json_encode(array_merge([
                    'storefront_id' => 'store_public',
                    'subtotal'      => 1800,
                    'delivery_fee'  => 200,
                    'discount'      => 0,
                    'total'         => 2000,
                    'currency'      => 'USD',
                    'gateway'       => 'stripe',
                ], $meta)),
                'created_at' => $now,
            ], array_map(fn ($value) => is_array($value) ? json_encode($value) : $value, $order)));

            return Order::where('uuid', 'order_uuid')->first();
        }

        /**
         * What `order.changed` broadcasts were recorded, or null when the event that ran is not
         * this file's stand-in (Fleet-Ops' own event, or another suite's stand-in).
         */
        function orderEditorCoverageBroadcasts(): ?array
        {
            return property_exists(Fleetbase\FleetOps\Events\OrderChanged::class, 'orderEditorCoverageDispatched')
                ? Fleetbase\FleetOps\Events\OrderChanged::$orderEditorCoverageDispatched
                : null;
        }

        /** Stock records for the order's Tea, sold from the bakery when the order was placed. */
        function orderEditorCoverageStock(int $quantity): void
        {
            $schema = orderEditorCoverageDb()->getSchemaBuilder();
            $schema->create('product_inventories', function ($table) {
                $table->increments('id');
                $table->string('uuid')->nullable();
                $table->string('public_id')->nullable();
                $table->string('company_uuid')->nullable();
                $table->string('product_uuid');
                $table->string('location_uuid');
                $table->string('location_type')->nullable();
                $table->integer('quantity')->default(0);
                $table->integer('reserved')->default(0);
                $table->integer('low_stock_threshold')->nullable();
                $table->boolean('allow_backorder')->default(false);
                $table->text('meta')->nullable();
                $table->timestamps();
                $table->timestamp('deleted_at')->nullable();
            });
            $schema->create('inventory_movements', function ($table) {
                $table->increments('id');
                $table->string('uuid')->nullable();
                $table->string('company_uuid')->nullable();
                $table->string('inventory_uuid');
                $table->string('type');
                $table->integer('quantity');
                $table->integer('quantity_after')->nullable();
                $table->string('reference_uuid')->nullable();
                $table->string('reference_type')->nullable();
                $table->string('actor_uuid')->nullable();
                $table->string('note')->nullable();
                $table->timestamps();
                $table->timestamp('deleted_at')->nullable();
            });
            $schema->create('inventory_reservations', function ($table) {
                $table->increments('id');
                $table->string('uuid')->nullable();
            });
            orderEditorCoverageDb()->table('products')->where('public_id', 'product_tea')->update(['track_inventory' => true]);
            orderEditorCoverageDb()->table('product_inventories')->insert(['uuid' => 'tea_stock_uuid', 'company_uuid' => 'company_uuid', 'product_uuid' => 'tea_uuid', 'location_uuid' => 'bakery_location', 'location_type' => Fleetbase\Storefront\Models\StoreLocation::class, 'quantity' => $quantity]);
            orderEditorCoverageDb()->table('inventory_movements')->insert(['uuid' => 'sale_uuid', 'company_uuid' => 'company_uuid', 'inventory_uuid' => 'tea_stock_uuid', 'type' => 'sale', 'quantity' => -2, 'reference_uuid' => 'order_uuid']);
            Inventory::flushEnabled();
        }

        function orderEditorCoverageEngine(): OrderEditorCoveragePromotionEngine
        {
            $engine = new OrderEditorCoveragePromotionEngine();
            app()->instance(PromotionEngine::class, $engine);

            return $engine;
        }

        /** Notifications sent to customers, kept instead of delivered (or failing when told to). */
        function orderEditorCoverageNotifications(?Throwable $failure = null): object
        {
            $dispatcher = new class($failure) implements Illuminate\Contracts\Notifications\Dispatcher {
                public array $sent = [];

                public function __construct(private ?Throwable $failure)
                {
                }

                public function send($notifiables, $notification)
                {
                    if ($this->failure) {
                        throw $this->failure;
                    }
                    $this->sent[] = [$notifiables, $notification];
                }

                public function sendNow($notifiables, $notification, ?array $channels = null)
                {
                    $this->send($notifiables, $notification);
                }
            };
            app()->instance(Illuminate\Contracts\Notifications\Dispatcher::class, $dispatcher);

            return $dispatcher;
        }

        function orderEditorCoverageSetUp(): void
        {
            orderEditorCoverageSchema();
            // What the service provider registers at boot.
            Fleetbase\FleetOps\Models\Entity::expand('fromStorefrontProduct', Fleetbase\Storefront\Expansions\EntityExpansion::fromStorefrontProduct());
            session(['company' => 'company_uuid', 'user' => null, 'storefront_key' => null, 'storefront_store' => null, 'storefront_currency' => null, 'customer_id' => null]);
            $GLOBALS['orderEditorCoverageReported'] = [];
            if (property_exists(Fleetbase\FleetOps\Events\OrderChanged::class, 'orderEditorCoverageDispatched')) {
                Fleetbase\FleetOps\Events\OrderChanged::$orderEditorCoverageDispatched = [];
            }
            OrderEditorCoverageQuoteController::$response = null;
            OrderEditorCoverageQuoteController::$requests = [];
            app()->bind(ServiceQuoteController::class, fn () => new OrderEditorCoverageQuoteController());
            // The quote request is built the way the router builds it, which asks for the redirector.
            app()->instance('redirect', new stdClass());
            orderEditorCoverageEngine();
            orderEditorCoverageNotifications();
            Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-10-12 10:00:00', 'UTC'));

            // Records an edit creates get their ids from their `creating` hooks, as in the app;
            // the lifecycle side effects (webhooks, tracking numbers, caches) stay off.
            app()->instance('DNS2D', new class {
                public function getBarcodePNG($code, $type): string
                {
                    return $type . ':' . $code;
                }
            });
            Illuminate\Support\Facades\Facade::clearResolvedInstance('DNS2D');
            Model::setEventDispatcher(new OrderEditorCoverageCreatingDispatcher(app()));
            Model::clearBootedModels();
        }

        function orderEditorCoverageTearDown(): void
        {
            foreach ([ServiceQuoteController::class, PromotionEngine::class, Illuminate\Contracts\Notifications\Dispatcher::class, 'redirect', 'DNS2D'] as $abstract) {
                unset(app()[$abstract]);
            }
            Carbon\Carbon::setTestNow();
            Inventory::flushEnabled();
            Model::unsetEventDispatcher();
            Model::clearBootedModels();
        }
    }
}
