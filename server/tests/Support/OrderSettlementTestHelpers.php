<?php

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Storefront\Models\Gateway;
use Fleetbase\Storefront\Support\OrderSettlement;
use Fleetbase\Storefront\Support\QPay;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Illuminate\Database\Eloquent\Model;

/*
 * Shared by the order settlement, order payment, balance sweep and customer order tests: the
 * tables a settlement touches, a Stripe API double, a QPay client on canned responses, and a
 * settlement whose QPay client can be swapped for that one.
 */

if (!function_exists('report')) {
    // Laravel's foundation helper, which this suite runs without: settlement reports what it
    // swallows (an announcement, a receipt, a link it could not retire) and carries on.
    function report($exception): void
    {
    }
}

if (!class_exists(Fleetbase\FleetOps\Events\OrderChanged::class)) {
    // The `order.changed` event of newer Fleet-Ops releases, which settlement announces payment
    // changes with when it exists. Records what it was asked to dispatch.
    eval('namespace Fleetbase\FleetOps\Events; class OrderChanged { public const SETTLEMENT_COVERAGE_STUB = true; public static array $dispatched = []; public static function dispatchFor(...$arguments): void { static::$dispatched[] = $arguments; } }');
}

if (!class_exists('SettlementCoverageProbe')) {
    /** A settlement whose QPay client is a canned one, so no request leaves the test. */
    class SettlementCoverageProbe extends OrderSettlement
    {
        public static ?QPay $qpay = null;

        protected static function qpay(Gateway $gateway): QPay
        {
            return static::$qpay ?? parent::qpay($gateway);
        }

        public static function realQPay(Gateway $gateway): QPay
        {
            return parent::qpay($gateway);
        }
    }

    /** Stands in for the Stripe API: answers by method and exact path, records every request. */
    class SettlementCoverageStripeClient implements Stripe\HttpClient\ClientInterface
    {
        public array $requests = [];

        /** @param array<string, array{0: int, 1: array}> $routes "METHOD /v1/path" => [status, body] */
        public function __construct(public array $routes = [])
        {
        }

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            $method = strtoupper($method);
            $path   = (string) parse_url($absUrl, PHP_URL_PATH);
            parse_str((string) parse_url($absUrl, PHP_URL_QUERY), $query);
            $this->requests[] = ['method' => $method, 'path' => $path, 'params' => $params ?: $query];

            foreach ($this->routes as $route => [$status, $body]) {
                [$routeMethod, $routePath] = explode(' ', $route, 2);
                if ($routeMethod === $method && $path === $routePath) {
                    return [json_encode($body), $status, []];
                }
            }

            return [json_encode(['error' => ['message' => 'No route for ' . $method . ' ' . $path, 'type' => 'api_error']]), 500, []];
        }

        public function paths(): array
        {
            return array_map(fn ($request) => $request['method'] . ' ' . $request['path'], $this->requests);
        }
    }

    /** The customer an order belongs to, collecting the notifications it is sent. */
    class SettlementCoverageCustomer
    {
        public array $notifications = [];

        public function __construct(public bool $fail = false)
        {
        }

        public function notify($notification): void
        {
            if ($this->fail) {
                throw new RuntimeException('Mail is down');
            }

            $this->notifications[] = $notification;
        }
    }
}

if (!function_exists('settlementCoverageDb')) {
    function settlementCoverageDb()
    {
        return Model::getConnectionResolver()->connection('mysql');
    }

    /**
     * The tables an order's payment and its settlement read and write, dropped and made again.
     */
    function settlementCoverageSchema(): void
    {
        $schema = settlementCoverageDb()->getSchemaBuilder();
        foreach (['orders', 'payloads', 'entities', 'places', 'waypoints', 'transactions', 'checkouts', 'gateways', 'stores', 'promotions', 'promotion_codes'] as $table) {
            $schema->dropIfExists($table);
        }

        $schema->create('orders', function ($table) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('public_id')->nullable();
            $table->string('company_uuid')->nullable();
            $table->string('customer_uuid')->nullable();
            $table->string('customer_type')->nullable();
            $table->string('payload_uuid')->nullable();
            $table->string('transaction_uuid')->nullable();
            $table->string('tracking_number_uuid')->nullable();
            $table->string('order_config_uuid')->nullable();
            $table->string('status')->nullable();
            $table->integer('orchestrator_priority')->nullable();
            $table->text('meta')->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('payloads', function ($table) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('pickup_uuid')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('cod_payment_method')->nullable();
            $table->integer('cod_amount')->nullable();
            $table->string('cod_currency')->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
        });
        // A payload always loads its entities and waypoints with it.
        $schema->create('entities', function ($table) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('public_id')->nullable();
            $table->string('payload_uuid')->nullable();
            $table->string('photo_uuid')->nullable();
            $table->string('name')->nullable();
            $table->text('meta')->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('places', function ($table) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('public_id')->nullable();
            $table->string('name')->nullable();
            $table->string('street1')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('waypoints', function ($table) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('payload_uuid')->nullable();
            $table->string('place_uuid')->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('transactions', function ($table) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('public_id')->nullable();
            $table->string('company_uuid')->nullable();
            $table->string('customer_uuid')->nullable();
            $table->string('customer_type')->nullable();
            $table->string('gateway')->nullable();
            $table->string('gateway_uuid')->nullable();
            $table->string('gateway_transaction_id')->nullable();
            $table->integer('amount')->default(0);
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
            $table->string('company_uuid')->nullable();
            $table->string('owner_uuid')->nullable();
            $table->string('name')->nullable();
            $table->string('code')->nullable();
            $table->string('type')->nullable();
            $table->boolean('sandbox')->default(false);
            $table->text('config')->nullable();
            $table->text('meta')->nullable();
            $table->string('callback_url')->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('stores', function ($table) {
            $table->increments('id');
            foreach (['uuid', 'public_id', 'company_uuid', 'backdrop_uuid', 'logo_uuid', 'order_config_uuid', 'name', 'website', 'facebook', 'instagram', 'twitter', 'email', 'phone', 'currency', 'timezone', 'pod_method'] as $column) {
                $table->string($column)->nullable();
            }
            foreach (['description', 'translations', 'tags', 'options'] as $column) {
                $table->text($column)->nullable();
            }
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

        settlementCoverageDb()->table('stores')->insert([
            'uuid'         => 'settle_store_uuid',
            'public_id'    => 'store_settle',
            'company_uuid' => 'settle_company_uuid',
            'name'         => 'Corner Bakery',
            'currency'     => 'MNT',
        ]);
    }

    /**
     * A placed order, saved, as checkout leaves it: paid 5000 USD by Stripe at store_settle.
     */
    function settlementCoverageOrder(array $meta = [], array $attributes = [], ?object $customer = null): Order
    {
        $order = new Order();
        $order->forceFill(array_merge([
            'uuid'          => 'settle_order_uuid',
            'public_id'     => 'order_settle',
            'company_uuid'  => 'settle_company_uuid',
            'customer_uuid' => 'settle_customer_uuid',
            'status'        => 'created',
            'meta'          => array_merge(['total' => 5000, 'currency' => 'USD', 'gateway' => 'stripe', 'storefront_id' => 'store_settle'], $meta),
        ], $attributes));
        $order->save();
        $order->setRelation('customer', $customer);

        return $order;
    }

    /** The order's meta as it was written to the database. */
    function settlementCoverageStoredMeta(string $uuid = 'settle_order_uuid'): array
    {
        return json_decode((string) settlementCoverageDb()->table('orders')->where('uuid', $uuid)->value('meta'), true) ?: [];
    }

    function settlementCoverageGateway(string $type, array $config = [], bool $sandbox = false, string $uuid = 'settle_gateway_uuid'): Gateway
    {
        settlementCoverageDb()->table('gateways')->insert([
            'uuid'    => $uuid,
            'code'    => $type,
            'type'    => $type,
            'name'    => ucfirst($type),
            'sandbox' => $sandbox,
            'config'  => json_encode($config),
        ]);

        return Gateway::where('uuid', $uuid)->first();
    }

    function settlementCoverageCheckout(array $attributes = []): void
    {
        settlementCoverageDb()->table('checkouts')->insert(array_merge([
            'uuid'         => 'settle_checkout_uuid',
            'public_id'    => 'checkout_settle',
            'order_uuid'   => 'settle_order_uuid',
            'gateway_uuid' => 'settle_gateway_uuid',
        ], $attributes));
    }

    function settlementCoverageStripe(array $routes): SettlementCoverageStripeClient
    {
        $client = new SettlementCoverageStripeClient($routes);
        Stripe\ApiRequestor::setHttpClient($client);

        return $client;
    }

    function settlementCoverageRestoreStripe(): void
    {
        Stripe\ApiRequestor::setHttpClient(new Stripe\HttpClient\CurlClient());
    }

    /** A real QPay client answering from canned responses, already holding a token. */
    function settlementCoverageQPay(array $responses, array &$history): QPay
    {
        $handler = HandlerStack::create(new MockHandler($responses));
        $handler->push(Middleware::history($history));

        $qpay = new QPay('settle-merchant', 'settle-secret');
        $qpay->updateRequestOption('handler', $handler);
        $qpay->setAuthToken('settle-token');

        return $qpay;
    }

    /** What a request sent QPay, decoded. */
    function settlementCoverageQPayBody(array $entry): array
    {
        return json_decode((string) $entry['request']->getBody(), true) ?: [];
    }

    /** Whether `order.changed` is this suite's recording stand-in (not another file's). */
    function settlementCoverageRecordsOrderChanged(): bool
    {
        return defined(Fleetbase\FleetOps\Events\OrderChanged::class . '::SETTLEMENT_COVERAGE_STUB');
    }
}
