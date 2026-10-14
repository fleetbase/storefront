<?php

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Models\Transaction;
use Fleetbase\Storefront\Http\Controllers\v1\CheckoutController;
use Fleetbase\Storefront\Http\Requests\CaptureOrderRequest;
use Fleetbase\Storefront\Models\Cart;
use Fleetbase\Storefront\Models\Checkout;
use Fleetbase\Storefront\Models\Gateway;
use Fleetbase\Storefront\Support\Inventory;
use Fleetbase\Storefront\Support\Storefront;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;

/** Records what the checkout logs, so warnings and cleanup errors can be asserted. */
class CheckoutCoverageLogSpy
{
    public array $entries = [];

    public function channel(?string $name = null): self
    {
        return $this;
    }

    public function __call(string $level, array $arguments): void
    {
        $this->entries[] = [$level, $arguments[0] ?? null, $arguments[1] ?? []];
    }

    public function messages(string $level): array
    {
        return array_values(array_map(fn ($entry) => $entry[1], array_filter($this->entries, fn ($entry) => $entry[0] === $level)));
    }
}

class CheckoutCoverageQPayStub extends Fleetbase\Storefront\Support\QPay
{
    public static ?string $invoiceKind    = null;
    public static array $invoiceArguments = [];

    public function __construct()
    {
    }

    public function useSandbox()
    {
        return $this;
    }

    public function setAuthToken(?string $accessToken = null): Fleetbase\Storefront\Support\QPay
    {
        return $this;
    }

    public function createSimpleInvoice(int $amount, ?string $invoiceCode = '', ?string $invoiceDescription = '', ?string $invoiceReceiverCode = '', ?string $senderInvoiceNo = '', ?string $callbackUrl = null)
    {
        static::$invoiceKind      = 'simple';
        static::$invoiceArguments = func_get_args();

        return (object) ['invoice_id' => 'invoice_coverage'];
    }

    public function createEbarimtInvoice(?string $invoiceCode = '', ?string $senderInvoiceNo = '', ?string $invoiceReceiverCode = '', array $invoiceReceiverData = [], ?string $invoiceDescription = '', ?string $taxType = '1', ?string $districtCode = '', array $lines = [], ?string $callbackUrl = null)
    {
        static::$invoiceKind      = 'ebarimt';
        static::$invoiceArguments = func_get_args();

        return (object) ['invoice_id' => 'invoice_coverage'];
    }
}

/** The checkout controller with its internal steps exposed and order automation observable. */
class CheckoutCoverageController extends CheckoutController
{
    public static ?Throwable $acceptFailure = null;
    public static int $accepted             = 0;

    protected static function qpayForGateway(Gateway $gateway): Fleetbase\Storefront\Support\QPay
    {
        return new CheckoutCoverageQPayStub();
    }

    protected function autoAcceptOrder(Order $order): void
    {
        if (static::$acceptFailure) {
            throw static::$acceptFailure;
        }
        static::$accepted++;
    }

    protected function autoDispatchOrder(Order $order): void
    {
    }

    public static function recordPayment(Checkout $checkout, object $payment): array
    {
        return static::recordQPayPayment($checkout, $payment);
    }

    public static function paidPayment($paymentCheck, Checkout $checkout): ?object
    {
        return static::paidQPayPayment($paymentCheck, $checkout);
    }

    public static function booking(iterable $cartItems): ?array
    {
        return static::bookingFor($cartItems);
    }

    public static function withBooking(iterable $cartItems, array $orderMeta, array $orderInput): array
    {
        return static::applyBooking($cartItems, $orderMeta, $orderInput);
    }

    public static function reserve(Checkout $checkout, $checkoutOptions, ?Contact $customer)
    {
        return static::reservePromotions($checkout, $checkoutOptions, $customer);
    }

    public static function fullSlot(Checkout $checkout): ?string
    {
        return static::fullBookingSlot($checkout);
    }

    public static function split(int $amount, array $subtotals): array
    {
        return static::splitByShare($amount, $subtotals);
    }

    public static function discard(?Transaction $transaction, array $orders = []): void
    {
        static::discardFailedCapture($transaction, $orders);
    }
}

/** An order whose removal fails, as when the database goes away mid-cleanup. */
class CheckoutCoverageUndeletableOrder extends Order
{
    public function delete()
    {
        throw new RuntimeException('database gone');
    }
}

function checkoutCoverageDb()
{
    return Model::getConnectionResolver()->connection('mysql');
}

/**
 * Creates the tables the checkout touches. Columns are `name` (nullable string) or
 * `name:type` / `name:type=default`.
 */
function checkoutCoverageSchema(): void
{
    $timestamps = ['created_at:timestamp', 'updated_at:timestamp', 'deleted_at:timestamp'];
    $storefront = ['uuid', 'public_id', 'company_uuid', 'backdrop_uuid', 'logo_uuid', 'order_config_uuid', 'key', 'name', 'description:text', 'translations:text', 'website', 'facebook', 'instagram', 'twitter', 'email', 'phone', 'tags:text', 'currency', 'timezone', 'pod_method', 'options:text', 'alertable:text'];
    $tables     = [
        'carts'                 => ['uuid', 'public_id', '_key', 'company_uuid', 'user_uuid', 'checkout_uuid', 'status', 'customer_id', 'unique_identifier', 'currency', 'discount_code', 'items:text', 'events:text', 'expires_at:timestamp'],
        'gateways'              => ['uuid', 'code', 'owner_uuid', 'type', 'config:text', 'sandbox:boolean=0', 'callback_url'],
        'contacts'              => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'type', 'name', 'email', 'phone', 'meta:text'],
        'service_quotes'        => ['uuid', 'public_id', 'amount:integer=0', 'currency', 'integrated_vendor_uuid', 'meta:text', 'expired_at:timestamp'],
        'integrated_vendors'    => ['uuid', 'public_id'],
        'checkouts'             => ['uuid', 'public_id', 'company_uuid', 'store_uuid', 'network_uuid', 'cart_uuid', 'gateway_uuid', 'service_quote_uuid', 'owner_uuid', 'owner_type', 'amount:integer=0', 'currency', 'is_cod:boolean=0', 'is_pickup:boolean=0', 'options:text', 'cart_state:text', 'token', 'order_uuid', 'stripe_payment_intent_id', 'captured:boolean=0'],
        'promotion_redemptions' => ['uuid', 'promotion_uuid', 'checkout_uuid', 'status'],
        'stores'                => $storefront,
        'networks'              => $storefront,
        'companies'             => ['uuid', 'name', 'timezone'],
        'order_configs'         => ['uuid', 'public_id', 'company_uuid', 'key', 'namespace', 'name', 'description:text', 'core_service:boolean=0', 'status', 'version', 'tags:text', 'entities:text', 'meta:text', 'flow:text'],
        'orders'                => ['uuid', 'public_id', '_key', 'internal_id', 'company_uuid', 'payload_uuid', 'customer_uuid', 'customer_type', 'facilitator_uuid', 'facilitator_type', 'transaction_uuid', 'purchase_rate_uuid', 'order_config_uuid', 'driver_assigned_uuid', 'adhoc:boolean=0', 'dispatched:boolean=0', 'dispatched_at:timestamp', 'distance:integer', 'time:integer', 'orchestrator_priority:integer', 'type', 'status', 'meta:text', 'notes:text'],
        'transactions'          => ['uuid', 'public_id', 'company_uuid', 'customer_uuid', 'customer_type', 'gateway_transaction_id', 'gateway', 'gateway_uuid', 'amount:integer=0', 'currency', 'description', 'type', 'status', 'settlement_status', 'settled_at:timestamp', 'settled_amount:integer=0', 'settled_currency', 'meta:text'],
        'transaction_items'     => ['uuid', 'public_id', 'transaction_uuid', 'amount:integer=0', 'currency', 'details:text', 'code', 'meta:text'],
        'payloads'              => ['uuid', 'public_id', '_key', 'company_uuid', 'pickup_uuid', 'dropoff_uuid', 'return_uuid', 'payment_method', 'cod_amount:integer', 'cod_currency', 'cod_payment_method', 'type', 'meta:text'],
        'entities'              => ['uuid', 'public_id', '_key', 'payload_uuid', 'company_uuid', 'customer_uuid', 'customer_type', 'photo_uuid', 'internal_id', 'name', 'description:text', 'currency', 'sku', 'price:integer', 'sale_price:integer', 'meta:text', 'slug', 'qr_code:text', 'barcode:text', 'place_uuid', 'order:integer=0'],
        'products'              => ['uuid', 'public_id', 'company_uuid', 'store_uuid', 'primary_image_uuid', 'name', 'description:text', 'currency', 'sku', 'price:integer=0', 'sale_price:integer', 'meta:text', 'is_bookable:boolean=0', 'is_available:boolean=1', 'track_inventory:boolean=0', 'status'],
        'product_hours'         => ['uuid', 'product_uuid', 'day_of_week', 'start', 'end'],
        'files'                 => ['uuid', 'subject_uuid', 'subject_type', 'url'],
        'comments'              => ['uuid', 'subject_uuid', 'subject_type', 'parent_comment_uuid'],
        'custom_field_values'   => ['uuid', 'subject_uuid', 'subject_type', 'custom_field_uuid', 'value:text'],
        'waypoints'             => ['uuid', 'public_id', '_key', 'payload_uuid', 'place_uuid', 'type', 'order:integer=0'],
        'places'                => ['uuid', 'public_id', 'company_uuid', 'name', 'street1', 'meta:text'],
        'store_locations'       => ['uuid', 'public_id', 'store_uuid', 'place_uuid', 'name'],
        'purchase_rates'        => ['uuid', 'public_id', '_key', 'customer_uuid', 'customer_type', 'company_uuid', 'service_quote_uuid', 'transaction_uuid', 'payload_uuid', 'status', 'meta:text'],
        'product_inventories'   => ['uuid', 'public_id', 'company_uuid', 'product_uuid', 'location_uuid', 'location_type', 'quantity:integer=0', 'reserved:integer=0', 'low_stock_threshold:integer', 'allow_backorder:boolean=0', 'meta:text'],
        'inventory_movements'   => ['uuid', 'company_uuid', 'inventory_uuid', 'type', 'quantity:integer=0', 'quantity_after:integer', 'reference_uuid', 'reference_type', 'actor_uuid', 'note'],
        'inventory_reservations'=> ['uuid', 'company_uuid', 'inventory_uuid', 'checkout_uuid', 'order_uuid', 'cart_item_id', 'quantity:integer=0', 'expires_at:timestamp', 'status'],
    ];

    $schema = checkoutCoverageDb()->getSchemaBuilder();
    foreach ($tables as $name => $columns) {
        $schema->dropIfExists($name);
        $schema->create($name, function ($table) use ($columns, $timestamps) {
            $table->increments('id');
            foreach (array_merge($columns, $timestamps) as $column) {
                [$definition, $default] = array_pad(explode('=', $column, 2), 2, null);
                [$columnName, $type]    = array_pad(explode(':', $definition, 2), 2, 'string');
                $field                  = $table->{$type}($columnName)->nullable();
                if ($default !== null) {
                    $field->default($default);
                }
            }
        });
    }
}

/** What capturing an order needs from the framework: uuids from model events, cache and barcodes. */
function checkoutCoverageCaptureRuntime(): void
{
    if (!Str::hasMacro('humanize')) {
        Str::macro('humanize', (new Fleetbase\Expansions\Str())->humanize());
    }
    app()->instance('responsecache', new class {
        public function clear(): void
        {
        }
    });
    app()->instance('DNS2D', new class {
        public function getBarcodePNG($value, $type): string
        {
            return 'encoded-barcode';
        }
    });
    Facade::clearResolvedInstance('DNS2D');
    Model::setEventDispatcher(new class(app()) extends Illuminate\Events\Dispatcher {
        public function dispatch($event, $payload = [], $halt = false)
        {
            if (is_string($event) && preg_match('/^eloquent\\.(created|updated|deleted|restored|saved):/', $event)) {
                return [];
            }

            return parent::dispatch($event, $payload, $halt);
        }
    });
    Model::clearBootedModels();
    Fleetbase\FleetOps\Models\Entity::expand('fromStorefrontProduct', Fleetbase\Storefront\Expansions\EntityExpansion::fromStorefrontProduct());
}

/**
 * A cash checkout of a network with two stores, one line from each (1000 and 500), collected
 * from the given places and delivered to `place_dropoff`.
 */
function checkoutCoverageNetworkCheckout(array $networkOptions, array $checkoutOptions, array $origins): void
{
    $db = checkoutCoverageDb();
    $db->table('companies')->insert(['uuid' => 'company_uuid', 'name' => 'Delivery Company']);
    $db->table('networks')->insert(['uuid' => 'network_uuid', 'public_id' => 'network_coverage', 'company_uuid' => 'company_uuid', 'key' => 'network_coverage_key', 'name' => 'Coverage network', 'currency' => 'USD', 'options' => json_encode($networkOptions), 'alertable' => '{}']);
    $storeOptions = json_encode(['auto_accept_orders' => true, 'auto_dispatch' => true]);
    $db->table('stores')->insert([
        ['uuid' => 'store_one_uuid', 'public_id' => 'store_one', 'company_uuid' => 'store_one_company', 'key' => 'store_one_key', 'name' => 'First store', 'currency' => 'USD', 'options' => $storeOptions, 'alertable' => '{}'],
        ['uuid' => 'store_two_uuid', 'public_id' => 'store_two', 'company_uuid' => 'store_two_company', 'key' => 'store_two_key', 'name' => 'Second store', 'currency' => 'USD', 'options' => $storeOptions, 'alertable' => '{}'],
    ]);
    $db->table('contacts')->insert(['uuid' => 'customer_uuid', 'public_id' => 'contact_coverage', 'company_uuid' => 'company_uuid', 'type' => 'customer', 'name' => 'Checkout customer']);
    $db->table('products')->insert(['uuid' => 'product_uuid', 'public_id' => 'product_coffee', 'name' => 'Coffee', 'currency' => 'USD', 'sku' => 'COFFEE-1', 'price' => 1000]);
    // Store pickups are found by place uuid, which is a real UUID (see Storefront::getStoreFromLocation()).
    $db->table('places')->insert([
        ['uuid' => '11111111-1111-4111-8111-111111111111', 'public_id' => 'place_storeone', 'company_uuid' => 'company_uuid', 'name' => 'First pickup'],
        ['uuid' => '22222222-2222-4222-8222-222222222222', 'public_id' => 'place_storetwo', 'company_uuid' => 'company_uuid', 'name' => 'Second pickup'],
        ['uuid' => '33333333-3333-4333-8333-333333333333', 'public_id' => 'place_nostore', 'company_uuid' => 'company_uuid', 'name' => 'Closed store'],
        ['uuid' => 'dropoff_uuid', 'public_id' => 'place_dropoff', 'company_uuid' => 'company_uuid', 'name' => 'Customer dropoff'],
    ]);
    $db->table('store_locations')->insert([
        ['uuid' => 'store_location_one_uuid', 'public_id' => 'store_location_one', 'store_uuid' => 'store_one_uuid', 'place_uuid' => '11111111-1111-4111-8111-111111111111', 'name' => 'First location'],
        ['uuid' => 'store_location_two_uuid', 'public_id' => 'store_location_two', 'store_uuid' => 'store_two_uuid', 'place_uuid' => '22222222-2222-4222-8222-222222222222', 'name' => 'Second location'],
    ]);
    $line = fn (string $store, int $subtotal) => ['product_id' => 'product_coffee', 'store_id' => $store, 'store_location_id' => 'store_location_' . Str::after($store, 'store_'), 'name' => 'Coffee', 'variants' => [], 'addons' => [], 'quantity' => 1, 'price' => $subtotal, 'subtotal' => $subtotal];
    $db->table('carts')->insert(['uuid' => 'cart_uuid', 'public_id' => 'cart_coverage', 'unique_identifier' => 'browser-cart', 'currency' => 'USD', 'items' => json_encode([$line('store_one', 1000), $line('store_two', 500)]), 'events' => '[]']);
    $db->table('service_quotes')->insert(['uuid' => 'quote_uuid', 'public_id' => 'quote_coverage', 'amount' => 300, 'currency' => 'USD', 'meta' => json_encode(['origin' => $origins, 'destination' => 'place_dropoff'])]);
    $db->table('checkouts')->insert(['uuid' => 'checkout_uuid', 'public_id' => 'checkout_coverage', 'company_uuid' => 'company_uuid', 'network_uuid' => 'network_uuid', 'cart_uuid' => 'cart_uuid', 'service_quote_uuid' => 'quote_uuid', 'owner_uuid' => 'customer_uuid', 'owner_type' => Contact::class, 'amount' => 300, 'currency' => 'USD', 'is_cod' => true, 'is_pickup' => false, 'options' => json_encode($checkoutOptions), 'token' => 'checkout-coverage-token', 'captured' => false]);

    session(['storefront_key' => 'network_coverage_key', 'storefront_store' => null, 'storefront_network' => 'network_uuid', 'company' => 'company_uuid']);
}

function checkoutCoverageCapture(): mixed
{
    return (new CheckoutCoverageController())->captureMultipleOrders(CaptureOrderRequest::create('/checkout/capture', 'POST', [
        'token'              => 'checkout-coverage-token',
        'transactionDetails' => ['transaction_id' => 'cash_coverage_receipt'],
    ]));
}

function checkoutCoverageCheckout(array $attributes = []): Checkout
{
    $checkout = new Checkout();
    $checkout->forceFill(array_merge(['uuid' => 'checkout_uuid', 'public_id' => 'checkout_coverage', 'currency' => 'MNT', 'options' => []], $attributes));

    return $checkout;
}

beforeEach(function () {
    checkoutCoverageSchema();
    Inventory::flushEnabled();
    $this->previousLog = app('log');
    $this->log         = new CheckoutCoverageLogSpy();
    app()->instance('log', $this->log);
    Facade::clearResolvedInstance('log');
    CheckoutCoverageController::$acceptFailure = null;
    CheckoutCoverageController::$accepted      = 0;
});

afterEach(function () {
    app()->instance('log', $this->previousLog);
    Facade::clearResolvedInstance('log');
    Model::unsetEventDispatcher();
    Model::clearBootedModels();
    Inventory::flushEnabled();
    Carbon::setTestNow();
    session(['storefront_key' => null, 'storefront_store' => null, 'storefront_network' => null]);
});

test('a live qpay e-barimt invoice without a district code is sent and reported', function () {
    checkoutCoverageDb()->table('stores')->insert(['uuid' => 'store_uuid', 'public_id' => 'store_qpay', 'company_uuid' => 'company_uuid', 'key' => 'store_qpay_key', 'name' => 'QPay store', 'currency' => 'MNT', 'options' => '{}']);
    session(['company' => 'company_uuid', 'storefront_key' => 'store_qpay_key', 'storefront_store' => 'store_uuid', 'storefront_network' => null]);
    $cart = new Cart();
    $cart->forceFill(['uuid' => 'cart_uuid', 'currency' => 'MNT', 'events' => [], 'items' => [
        ['id' => 'line_one', 'product_id' => null, 'name' => 'Delivery box', 'quantity' => 2, 'price' => 1000, 'subtotal' => 2000, 'classification_code' => '2111100', 'tax_product_code' => '319'],
    ]]);
    $customer = new Fleetbase\Storefront\Models\Customer();
    $customer->forceFill(['uuid' => 'customer_uuid', 'public_id' => 'customer_coverage', 'name' => 'Ada Buyer', 'meta' => []]);
    $gateway = new Gateway();
    $gateway->forceFill(['uuid' => 'qpay_gateway_uuid', 'public_id' => 'gateway_qpay', 'type' => 'qpay', 'sandbox' => false, 'callback_url' => 'https://storefront.test/qpay', 'config' => [
        'username'           => 'merchant',
        'password'           => 'secret',
        'ebarimt_invoice_id' => 'MERCHANT_EB_INVOICE',
    ]]);
    CheckoutCoverageQPayStub::$invoiceKind = null;

    $response = CheckoutCoverageController::initializeQPayCheckout($customer, $gateway, null, $cart, (object) ['is_pickup' => true], Request::create('/checkout', 'POST'));

    expect($response->getData(true)['invoice']['invoice_id'])->toBe('invoice_coverage')
        ->and(CheckoutCoverageQPayStub::$invoiceKind)->toBe('ebarimt')
        ->and(CheckoutCoverageQPayStub::$invoiceArguments[0])->toBe('MERCHANT_EB_INVOICE')
        ->and(CheckoutCoverageQPayStub::$invoiceArguments[6])->toBeNull()
        ->and($this->log->messages('warning'))->toBe(['[QPAY]: e-barimt invoice without a district code; set district_code in the QPay gateway settings']);
});

test('a qpay payment is recorded on the checkout once', function () {
    $recorded = ['payment_id' => 'payment_first', 'payment_status' => 'PAID', 'payment_amount' => 1000];
    $checkout = checkoutCoverageCheckout(['options' => ['qpay_payment' => $recorded]]);

    expect(CheckoutCoverageController::recordPayment($checkout, (object) ['payment_id' => 'payment_second', 'payment_amount' => 1000]))->toBe($recorded)
        ->and($checkout->getOption('qpay_payment'))->toBe($recorded);
});

test('a qpay payment pays the checkout only when it covers the invoice amount', function () {
    $checkout = checkoutCoverageCheckout(['options' => ['qpay_invoice_amount' => 2000]]);
    $paidRow  = ['payment_id' => 'payment_1', 'payment_status' => 'PAID', 'payment_amount' => 1500];

    $covered   = CheckoutCoverageController::paidPayment(['paid_amount' => 2000, 'rows' => [$paidRow]], $checkout);
    $rounded   = CheckoutCoverageController::paidPayment(['paid_amount' => 1999.995, 'rows' => [$paidRow]], $checkout);
    $summed    = CheckoutCoverageController::paidPayment(['rows' => [['payment_status' => 'NEW', 'payment_amount' => 9000], $paidRow, ['payment_id' => 'payment_2', 'payment_status' => 'PAID', 'payment_amount' => 500]]], $checkout);
    $short     = CheckoutCoverageController::paidPayment(['rows' => [$paidRow]], $checkout);
    $shortPaid = CheckoutCoverageController::paidPayment(['paid_amount' => 100, 'rows' => [$paidRow]], $checkout);

    expect($covered->payment_id)->toBe('payment_1')
        ->and($rounded->payment_id)->toBe('payment_1')
        // Without paid_amount the PAID rows are added up (the NEW row does not count).
        ->and($summed->payment_id)->toBe('payment_1')
        ->and($short)->toBeNull()
        ->and($shortPaid)->toBeNull()
        ->and($this->log->messages('warning'))->toBe([
            '[QPAY]: payment is less than the invoice amount; no order created',
            '[QPAY]: payment is less than the invoice amount; no order created',
        ])
        ->and($this->log->entries[0][2])->toBe(['checkout' => 'checkout_coverage', 'paid_amount' => 1500.0, 'expected' => 2000])
        ->and($this->log->entries[1][2]['paid_amount'])->toBe(100);
});

test('a cart with a booked service makes a booking at its earliest time', function () {
    checkoutCoverageDb()->table('products')->insert([
        ['uuid' => 'massage_uuid', 'public_id' => 'product_massage', 'name' => 'Massage', 'is_bookable' => true, 'meta' => json_encode(['service' => ['duration_minutes' => 90]])],
        ['uuid' => 'oil_uuid', 'public_id' => 'product_oil', 'name' => 'Oil', 'is_bookable' => false, 'meta' => '{}'],
    ]);

    $booking = CheckoutCoverageController::booking([
        (object) ['product_id' => 'product_massage', 'scheduled_at' => '2026-10-12T11:00:00+08:00'],
        (object) ['product_id' => 'product_massage', 'scheduled_at' => '2026-10-12T09:00:00+08:00'],
        (object) ['product_id' => 'product_oil'],
    ]);
    $onlyService = CheckoutCoverageController::booking([(object) ['product_id' => 'product_massage', 'scheduled_at' => '2026-10-12T09:00:00+08:00']]);
    // A time on a product that is not set up as a service is still a booking, of the default length.
    $notAService = CheckoutCoverageController::booking([(object) ['product_id' => 'product_oil', 'scheduled_at' => '2026-10-12T09:00:00+08:00']]);
    $unreadable  = CheckoutCoverageController::booking([(object) ['product_id' => 'product_massage', 'scheduled_at' => 'next tuesday-ish at noonish']]);

    expect($booking)->toBe([
        'is_booking'        => true,
        'booking_at'        => '2026-10-12T09:00:00+08:00',
        'booking'           => ['start' => '2026-10-12T09:00:00+08:00', 'end' => '2026-10-12T10:30:00+08:00', 'duration_minutes' => 90],
        'booking_has_items' => true,
    ])
        ->and($onlyService['booking_has_items'])->toBeFalse()
        ->and($notAService['booking'])->toBe(['start' => '2026-10-12T09:00:00+08:00', 'end' => '2026-10-12T10:00:00+08:00', 'duration_minutes' => 60])
        ->and($unreadable)->toBe(['is_booking' => true, 'booking_at' => null, 'booking' => null, 'booking_has_items' => false])
        ->and(CheckoutCoverageController::booking([(object) ['product_id' => 'product_oil']]))->toBeNull()
        ->and(CheckoutCoverageController::booking([]))->toBeNull();
});

test('a booking order takes the company\'s booking flow when there is one', function () {
    $companyUuid = '99999999-9999-4999-8999-999999999999';
    checkoutCoverageDb()->table('products')->insert(['uuid' => 'massage_uuid', 'public_id' => 'product_massage', 'name' => 'Massage', 'is_bookable' => true]);
    checkoutCoverageDb()->table('order_configs')->insert(['uuid' => 'booking_config_uuid', 'company_uuid' => $companyUuid, 'key' => Storefront::BOOKING_CONFIG_KEY, 'namespace' => Storefront::BOOKING_CONFIG_NS, 'name' => 'Storefront Booking', 'flow' => '{}']);
    $items = [(object) ['product_id' => 'product_massage', 'scheduled_at' => '2026-10-12T09:00:00+08:00']];

    [$meta, $input] = CheckoutCoverageController::withBooking($items, ['subtotal' => 5000], ['company_uuid' => $companyUuid, 'order_config_uuid' => 'delivery_config_uuid']);

    $previous = session('company');
    session(['company' => null]);
    try {
        [$noCompanyMeta, $noCompanyInput] = CheckoutCoverageController::withBooking($items, [], ['order_config_uuid' => 'delivery_config_uuid']);
    } finally {
        session(['company' => $previous]);
    }

    expect($input['order_config_uuid'])->toBe('booking_config_uuid')
        ->and($meta)->toMatchArray(['subtotal' => 5000, 'is_booking' => true, 'booking_at' => '2026-10-12T09:00:00+08:00'])
        ->and($noCompanyInput['order_config_uuid'])->toBe('delivery_config_uuid')
        ->and($noCompanyMeta['is_booking'])->toBeTrue();
});

test('a checkout for a slot that filled up meanwhile is ended', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-11 20:00:00', 'Asia/Singapore'));
    $db = checkoutCoverageDb();
    $db->table('stores')->insert(['uuid' => 'store_uuid', 'public_id' => 'store_spa', 'timezone' => 'Asia/Singapore']);
    $db->table('products')->insert([
        ['uuid' => 'massage_uuid', 'public_id' => 'product_massage', 'store_uuid' => 'store_uuid', 'name' => 'Massage', 'is_bookable' => true, 'meta' => json_encode(['service' => ['capacity' => 1]])],
        ['uuid' => 'oil_uuid', 'public_id' => 'product_oil', 'store_uuid' => 'store_uuid', 'name' => 'Oil', 'is_bookable' => false, 'meta' => '{}'],
    ]);
    $db->table('product_hours')->insert(['product_uuid' => 'massage_uuid', 'day_of_week' => 'monday', 'start' => '09:00:00', 'end' => '12:00:00']);
    // Someone else booked the 10:00 slot.
    $db->table('entities')->insert(['uuid' => 'entity_uuid', 'payload_uuid' => 'payload_other', 'meta' => json_encode(['product_id' => 'product_massage', 'scheduled_at' => '2026-10-12T10:00:00+08:00']), 'created_at' => '2026-10-10 00:00:00']);
    $db->table('orders')->insert(['uuid' => 'order_other', 'payload_uuid' => 'payload_other', 'status' => 'created']);
    $db->table('carts')->insert([
        ['uuid' => 'cart_full', 'items' => json_encode([
            ['id' => 'line_oil', 'product_id' => 'product_oil', 'scheduled_at' => '2026-10-12T10:00:00+08:00'],
            ['id' => 'line_unreadable', 'product_id' => 'product_massage', 'scheduled_at' => 'whenever suits'],
            ['id' => 'line_massage', 'product_id' => 'product_massage', 'scheduled_at' => '2026-10-12T10:00:00+08:00'],
        ])],
        ['uuid' => 'cart_free', 'items' => json_encode([['id' => 'line_massage', 'product_id' => 'product_massage', 'scheduled_at' => '2026-10-12T11:00:00+08:00']])],
        ['uuid' => 'cart_plain', 'items' => json_encode([['id' => 'line_oil', 'product_id' => 'product_oil']])],
    ]);
    $db->table('checkouts')->insert(['uuid' => 'checkout_uuid', 'public_id' => 'checkout_coverage', 'cart_uuid' => 'cart_full']);
    $checkout = Checkout::where('uuid', 'checkout_uuid')->first();

    expect(CheckoutCoverageController::fullSlot(checkoutCoverageCheckout(['cart_uuid' => 'cart_free'])))->toBeNull()
        ->and(CheckoutCoverageController::fullSlot(checkoutCoverageCheckout(['cart_uuid' => 'cart_plain'])))->toBeNull()
        ->and(CheckoutCoverageController::fullSlot(checkoutCoverageCheckout(['cart_uuid' => null])))->toBeNull()
        ->and(CheckoutCoverageController::fullSlot($checkout))->toBe('Massage');

    $response = CheckoutCoverageController::reserve($checkout, (object) [], null);

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true))->toBe([
            'errors' => ['The time you picked for Massage was just booked. Please pick another time.'],
            'code'   => 'booking_slot_unavailable',
        ])
        ->and($db->table('checkouts')->where('uuid', 'checkout_uuid')->whereNull('deleted_at')->exists())->toBeFalse();
});

test('a checkout for items that sold out meanwhile is ended and names them', function () {
    $db = checkoutCoverageDb();
    $db->table('products')->insert(['uuid' => 'tea_uuid', 'public_id' => 'product_tea', 'company_uuid' => 'company_uuid', 'name' => 'Tea', 'track_inventory' => true]);
    $db->table('product_inventories')->insert(['uuid' => 'stock_uuid', 'company_uuid' => 'company_uuid', 'product_uuid' => 'tea_uuid', 'location_uuid' => 'location_uuid', 'quantity' => 1]);
    $db->table('carts')->insert(['uuid' => 'cart_uuid', 'items' => json_encode([['id' => 'line_tea', 'product_id' => 'product_tea', 'name' => 'Tea', 'quantity' => 3]])]);
    $db->table('checkouts')->insert(['uuid' => 'checkout_uuid', 'public_id' => 'checkout_coverage', 'cart_uuid' => 'cart_uuid']);
    $checkout = Checkout::where('uuid', 'checkout_uuid')->first();

    $response = CheckoutCoverageController::reserve($checkout, (object) [], null);

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true))->toBe([
            'errors' => ['Some items in your cart are no longer available.'],
            'code'   => 'cart_items_unavailable',
            'items'  => [['id' => 'line_tea', 'product_id' => 'product_tea', 'name' => 'Tea', 'available' => 1]],
        ])
        ->and($db->table('checkouts')->where('uuid', 'checkout_uuid')->whereNull('deleted_at')->exists())->toBeFalse()
        ->and($db->table('product_inventories')->value('reserved'))->toBe(0);
});

test('an amount is split across stores by their share, in whole cents that add up', function () {
    expect(CheckoutCoverageController::split(100, ['store_a' => 1000, 'store_b' => 500]))->toBe(['store_a' => 67, 'store_b' => 33])
        ->and(CheckoutCoverageController::split(100, ['store_a' => 1, 'store_b' => 1, 'store_c' => 1]))->toBe(['store_a' => 34, 'store_b' => 33, 'store_c' => 33])
        ->and(CheckoutCoverageController::split(90, ['store_a' => 1, 'store_b' => 2]))->toBe(['store_a' => 30, 'store_b' => 60])
        ->and(CheckoutCoverageController::split(0, ['store_a' => 1000]))->toBe(['store_a' => 0])
        ->and(CheckoutCoverageController::split(50, ['store_a' => 0, 'store_b' => 0]))->toBe(['store_a' => 0, 'store_b' => 0]);
});

test('a failed capture removes the store orders it made, and a cleanup failure is logged', function () {
    $db = checkoutCoverageDb();
    $db->table('orders')->insert(['uuid' => 'order_one_uuid', 'public_id' => 'order_one']);
    $db->table('transactions')->insert(['uuid' => 'transaction_uuid', 'public_id' => 'transaction_coverage', 'company_uuid' => 'company_uuid']);
    $db->table('transaction_items')->insert(['uuid' => 'item_uuid', 'transaction_uuid' => 'transaction_uuid']);
    $order       = Order::where('uuid', 'order_one_uuid')->first();
    $transaction = Transaction::where('uuid', 'transaction_uuid')->first();

    CheckoutCoverageController::discard($transaction, [$order, 'not an order']);

    expect($db->table('orders')->whereNull('deleted_at')->count())->toBe(0)
        ->and($db->table('transactions')->whereNull('deleted_at')->count())->toBe(0)
        ->and($db->table('transaction_items')->whereNull('deleted_at')->count())->toBe(0)
        ->and($this->log->messages('error'))->toBe([]);

    CheckoutCoverageController::discard($transaction, [new CheckoutCoverageUndeletableOrder()]);

    expect($this->log->messages('error'))->toBe(['[Storefront] Unable to discard a failed order capture.'])
        ->and($this->log->entries[0][2])->toBe(['transaction' => 'transaction_coverage', 'error' => 'database gone']);
});

test('a network order splits the store tip across its stores by their share', function () {
    checkoutCoverageSchema();
    checkoutCoverageCaptureRuntime();
    checkoutCoverageNetworkCheckout(['split_tips_across_stores' => true], ['tip' => 100], ['place_storeone', 'place_storetwo']);

    $resource = checkoutCoverageCapture();
    Model::unsetEventDispatcher();

    $metas    = checkoutCoverageDb()->table('orders')->orderBy('id')->pluck('meta')->map(fn ($meta) => json_decode($meta, true));
    $children = $metas->filter(fn ($meta) => $meta['is_master_order'] === false)->keyBy('storefront_id');
    $master   = $metas->first(fn ($meta) => $meta['is_master_order'] === true);

    expect($resource)->toBeInstanceOf(Fleetbase\FleetOps\Http\Resources\v1\Order::class)
        ->and($children)->toHaveCount(2)
        ->and($children['store_one'])->toMatchArray(['tip' => 67, 'tip_recipient' => 'store', 'subtotal' => 1000])
        ->and($children['store_two'])->toMatchArray(['tip' => 33, 'tip_recipient' => 'store', 'subtotal' => 500])
        ->and($master)->toMatchArray(['tip' => 100, 'tip_recipient' => 'stores', 'store_names' => ['First store', 'Second store']])
        ->and(CheckoutCoverageController::$accepted)->toBe(2);
});

test('a network order with a pickup whose store cannot be found is not placed', function () {
    checkoutCoverageSchema();
    checkoutCoverageCaptureRuntime();
    checkoutCoverageNetworkCheckout([], [], ['place_storeone', 'place_nostore']);

    $response = checkoutCoverageCapture();
    Model::unsetEventDispatcher();

    expect($response->getData(true))->toBe(['error' => 'A store in this order could not be found, so it was not placed. Your payment has not been used.'])
        ->and(checkoutCoverageDb()->table('transactions')->count())->toBe(0)
        ->and(checkoutCoverageDb()->table('orders')->count())->toBe(0)
        ->and((bool) checkoutCoverageDb()->table('checkouts')->value('captured'))->toBeFalse();
});

test('a network capture that fails part way undoes its payment record and store orders', function () {
    checkoutCoverageSchema();
    checkoutCoverageCaptureRuntime();
    checkoutCoverageNetworkCheckout([], ['tip' => 100], ['place_storeone', 'place_storetwo']);
    CheckoutCoverageController::$acceptFailure = new RuntimeException('order flow unavailable');

    expect(fn () => checkoutCoverageCapture())->toThrow(RuntimeException::class, 'order flow unavailable');
    Model::unsetEventDispatcher();

    $db = checkoutCoverageDb();
    expect($db->table('orders')->count())->toBe(1)
        ->and($db->table('orders')->whereNull('deleted_at')->count())->toBe(0)
        ->and($db->table('transactions')->whereNull('deleted_at')->count())->toBe(0)
        ->and($db->table('transaction_items')->count())->toBeGreaterThan(0)
        ->and($db->table('transaction_items')->whereNull('deleted_at')->count())->toBe(0)
        ->and($db->table('checkouts')->value('order_uuid'))->toBeNull()
        ->and($this->log->messages('error'))->toBe([]);
});
