<?php

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\Storefront\Http\Controllers\v1\CheckoutController;
use Fleetbase\Storefront\Http\Requests\CaptureOrderRequest;
use Fleetbase\Storefront\Http\Requests\CreateStripeSetupIntentRequest;
use Fleetbase\Storefront\Http\Requests\InitializeCheckoutRequest;
use Fleetbase\Storefront\Models\Cart;
use Fleetbase\Storefront\Models\Checkout;
use Fleetbase\Storefront\Models\Gateway;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CheckoutQPayStub extends Fleetbase\Storefront\Support\QPay
{
    public static mixed $paymentCheckResult = null;
    public static ?Throwable $failure       = null;
    public static bool $sandboxUsed         = false;
    public static bool $authenticated       = false;
    public static ?string $invoiceKind      = null;
    public static array $invoiceArguments   = [];

    public function __construct()
    {
    }

    public function useSandbox()
    {
        static::$sandboxUsed = true;

        return $this;
    }

    public function setAuthToken(?string $accessToken = null): Fleetbase\Storefront\Support\QPay
    {
        static::$authenticated = true;

        return $this;
    }

    public function paymentCheck(string $invoiceId, $options = [])
    {
        if (static::$failure) {
            throw static::$failure;
        }

        return static::$paymentCheckResult;
    }

    public function createSimpleInvoice(int $amount, ?string $invoiceCode = '', ?string $invoiceDescription = '', ?string $invoiceReceiverCode = '', ?string $senderInvoiceNo = '', ?string $callbackUrl = null)
    {
        static::$invoiceKind      = 'simple';
        static::$invoiceArguments = func_get_args();

        return (object) ['invoice_id' => 'invoice_checkout'];
    }

    public function createEbarimtInvoice(?string $invoiceCode = '', ?string $senderInvoiceNo = '', ?string $invoiceReceiverCode = '', array $invoiceReceiverData = [], ?string $invoiceDescription = '', ?string $taxType = '1', ?string $districtCode = '', array $lines = [], ?string $callbackUrl = null)
    {
        static::$invoiceKind      = 'ebarimt';
        static::$invoiceArguments = func_get_args();

        return (object) ['invoice_id' => 'invoice_checkout'];
    }
}

class CheckoutCaptureFailureStub extends CheckoutController
{
    public function captureOrder(CaptureOrderRequest $request)
    {
        throw new RuntimeException('Capture failed after payment confirmation');
    }
}

class CheckoutOrderAutomationStub extends CheckoutController
{
    public static int $accepted   = 0;
    public static int $dispatched = 0;

    protected function autoAcceptOrder(Fleetbase\FleetOps\Models\Order $order): void
    {
        static::$accepted++;
    }

    protected function autoDispatchOrder(Fleetbase\FleetOps\Models\Order $order): void
    {
        static::$dispatched++;
    }
}

class CheckoutIntegratedVendorStub extends CheckoutOrderAutomationStub
{
    public static ?Throwable $vendorFailure = null;

    protected function createIntegratedVendorOrder(ServiceQuote $serviceQuote, Request $request)
    {
        if (static::$vendorFailure) {
            throw static::$vendorFailure;
        }

        return ['provider_order_id' => 'vendor-order-123'];
    }

    public function vendorSafely(ServiceQuote $serviceQuote, Request $request): array
    {
        return $this->createIntegratedVendorOrderSafely($serviceQuote, $request);
    }
}

class CheckoutAutomationControllerProbe extends CheckoutController
{
    public static function checkoutCustomer(?string $customerId)
    {
        return parent::resolveCheckoutCustomer($customerId);
    }

    public function marketplaceCartValidation(Cart $cart)
    {
        return $this->validateMarketplaceCart($cart);
    }

    public function accept(Fleetbase\FleetOps\Models\Order $order): void
    {
        $this->autoAcceptOrder($order);
    }

    public function dispatch(Fleetbase\FleetOps\Models\Order $order): void
    {
        $this->autoDispatchOrder($order);
    }

    public function vendor(ServiceQuote $serviceQuote, Request $request)
    {
        return $this->createIntegratedVendorOrder($serviceQuote, $request);
    }

    public function storeLocationOrigin($origin, Cart $cart)
    {
        return $this->resolveStoreLocationOrigin($origin, $cart);
    }

    public function foodTruck(Cart $cart): ?Fleetbase\Storefront\Models\FoodTruck
    {
        return $this->resolveFoodTruck($cart);
    }

    public function foodTruckOrigin(?Fleetbase\Storefront\Models\FoodTruck $foodTruck): ?array
    {
        return $this->resolveFoodTruckOrigin($foodTruck);
    }

    public function foodTruckOrderData(?Fleetbase\Storefront\Models\FoodTruck $foodTruck, array $meta, array $input): array
    {
        return $this->applyFoodTruckOrderData($foodTruck, $meta, $input);
    }
}

test('authenticated checkout identity cannot be replaced by a submitted customer id', function () {
    createCheckoutBoundarySchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('personal_access_tokens');
    $schema->create('personal_access_tokens', function ($table) {
        $table->increments('id');
        $table->string('tokenable_type');
        $table->string('tokenable_id');
        $table->string('name');
        $table->string('token', 64)->unique();
        $table->text('abilities')->nullable();
        $table->timestamp('last_used_at')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
    });
    $customerUuid = '1d182070-f74d-4cf6-92fb-ab35531d15c6';
    $connection->table('contacts')->insert([
        'uuid'         => $customerUuid,
        'public_id'    => 'contact_authenticated',
        'company_uuid' => 'company_uuid',
        'type'         => 'customer',
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
    $connection->table('personal_access_tokens')->insert([
        'tokenable_type' => Fleetbase\Models\User::class,
        'tokenable_id'   => $customerUuid,
        'name'           => $customerUuid,
        'token'          => hash('sha256', 'authenticated-customer-secret'),
        'abilities'      => '["*"]',
        'created_at'     => now(),
        'updated_at'     => now(),
    ]);
    $connection->table('gateways')->insert([
        'uuid'       => 'gateway_uuid',
        'code'       => 'stripe',
        'owner_uuid' => 'store_owner_uuid',
        'type'       => 'stripe',
        'config'     => json_encode(['secret_key' => 'sk_test_contract']),
        'sandbox'    => true,
    ]);
    $connection->table('carts')->insert([
        'uuid'              => 'cart_uuid',
        'public_id'         => 'cart_public',
        'company_uuid'      => 'company_uuid',
        'unique_identifier' => 'authenticated-cart',
        'currency'          => 'USD',
        'items'             => '[]',
        'events'            => '[]',
        'expires_at'        => now()->addHour(),
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);
    session([
        'company'            => 'company_uuid',
        'storefront_store'   => 'store_owner_uuid',
        'storefront_network' => null,
    ]);
    $boundRequest = Request::create('/checkout');
    $boundRequest->headers->set('Customer-Token', 'authenticated-customer-secret');
    app()->instance('request', $boundRequest);

    $resolved        = CheckoutAutomationControllerProbe::checkoutCustomer('customer_authenticated');
    $resolvedContact = CheckoutAutomationControllerProbe::checkoutCustomer('contact_authenticated');
    $fallback        = CheckoutAutomationControllerProbe::checkoutCustomer(null);
    $mismatch        = CheckoutAutomationControllerProbe::checkoutCustomer('customer_other');
    $controller      = new CheckoutAutomationControllerProbe();
    $before          = $controller->beforeCheckout(InitializeCheckoutRequest::create('/checkout', 'POST', [
        'gateway'  => 'stripe',
        'cart'     => 'cart_public',
        'customer' => 'customer_other',
    ]));
    $setup = $controller->createStripeSetupIntentForCustomer(CreateStripeSetupIntentRequest::create('/checkout/stripe/setup', 'POST', [
        'customer' => 'customer_other',
    ]));
    $update = $controller->updateStripePaymentIntent(Request::create('/checkout/stripe/update', 'POST', [
        'cart'     => 'cart_public',
        'customer' => 'customer_other',
    ]));
    app()->instance('request', Request::create('/'));

    expect($resolved?->public_id)->toBe('contact_authenticated')
        ->and($resolvedContact?->public_id)->toBe('contact_authenticated')
        ->and($fallback?->public_id)->toBe('contact_authenticated')
        ->and($mismatch->getStatusCode())->toBe(403)
        ->and($before->getStatusCode())->toBe(403)
        ->and($setup->getStatusCode())->toBe(403)
        ->and($update->getStatusCode())->toBe(403)
        ->and($update->getData(true))->toBe([
            'error' => 'Customer does not match the authenticated session.',
        ]);
});

test('marketplace checkout validates membership availability locations cart mode and currency before payment', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    foreach (['store_locations', 'products', 'network_stores', 'networks', 'stores'] as $table) {
        $schema->dropIfExists($table);
    }
    $schema->create('stores', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id');
        $table->boolean('online')->default(true);
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('networks', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->text('options')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('network_stores', function ($table) {
        $table->increments('id');
        $table->string('network_uuid');
        $table->string('store_uuid');
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('products', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id');
        $table->string('store_uuid');
        $table->boolean('is_available')->default(true);
        $table->string('status')->nullable();
        $table->string('currency')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('store_locations', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id');
        $table->string('store_uuid');
        $table->timestamp('deleted_at')->nullable();
    });
    $connection->table('stores')->insert([
        ['uuid' => 'store_one_uuid', 'public_id' => 'store_one', 'online' => true],
        ['uuid' => 'store_two_uuid', 'public_id' => 'store_two', 'online' => true],
        ['uuid' => 'store_offline_uuid', 'public_id' => 'store_offline', 'online' => false],
        ['uuid' => 'store_foreign_uuid', 'public_id' => 'store_foreign', 'online' => true],
    ]);
    $connection->table('networks')->insert(['uuid' => 'network_uuid', 'options' => json_encode(['multi_cart_enabled' => false])]);
    $connection->table('network_stores')->insert([
        ['network_uuid' => 'network_uuid', 'store_uuid' => 'store_one_uuid'],
        ['network_uuid' => 'network_uuid', 'store_uuid' => 'store_two_uuid'],
        ['network_uuid' => 'network_uuid', 'store_uuid' => 'store_offline_uuid'],
    ]);
    $connection->table('products')->insert([
        ['uuid' => 'product_one_uuid', 'public_id' => 'product_one', 'store_uuid' => 'store_one_uuid', 'is_available' => true, 'status' => 'published', 'currency' => 'USD'],
        ['uuid' => 'product_two_uuid', 'public_id' => 'product_two', 'store_uuid' => 'store_two_uuid', 'is_available' => true, 'status' => 'published', 'currency' => 'USD'],
        ['uuid' => 'product_eur_uuid', 'public_id' => 'product_eur', 'store_uuid' => 'store_two_uuid', 'is_available' => true, 'status' => 'published', 'currency' => 'EUR'],
        ['uuid' => 'product_draft_uuid', 'public_id' => 'product_draft', 'store_uuid' => 'store_one_uuid', 'is_available' => true, 'status' => 'draft', 'currency' => 'USD'],
    ]);
    $connection->table('store_locations')->insert([
        ['uuid' => 'location_one_uuid', 'public_id' => 'location_one', 'store_uuid' => 'store_one_uuid'],
        ['uuid' => 'location_two_uuid', 'public_id' => 'location_two', 'store_uuid' => 'store_two_uuid'],
        ['uuid' => 'location_offline_uuid', 'public_id' => 'location_offline', 'store_uuid' => 'store_offline_uuid'],
    ]);

    $cartFor = function (array $items): Cart {
        $cart = new Cart();
        $cart->forceFill(['items' => array_map(fn ($item) => (object) $item, $items), 'events' => []]);

        return $cart;
    };
    $item       = fn ($store, $product, $location) => ['store_id' => $store, 'product_id' => $product, 'store_location_id' => $location];
    $controller = new CheckoutAutomationControllerProbe();

    session(['storefront_network' => null]);
    expect($controller->marketplaceCartValidation($cartFor([])))->toBeNull();

    session(['storefront_network' => 'network_uuid']);
    $empty         = $controller->marketplaceCartValidation($cartFor([]));
    $missingStore  = $controller->marketplaceCartValidation($cartFor([$item(null, 'product_one', 'location_one')]));
    $foreignStore  = $controller->marketplaceCartValidation($cartFor([$item('store_foreign', 'product_one', 'location_one')]));
    $offlineStore  = $controller->marketplaceCartValidation($cartFor([$item('store_offline', 'product_one', 'location_offline')]));
    $singleValid   = $controller->marketplaceCartValidation($cartFor([$item('store_one', 'product_one', 'location_one')]));
    $multiDisabled = $controller->marketplaceCartValidation($cartFor([
        $item('store_one', 'product_one', 'location_one'),
        $item('store_two', 'product_two', 'location_two'),
    ]));
    $draftProduct  = $controller->marketplaceCartValidation($cartFor([$item('store_one', 'product_draft', 'location_one')]));
    $wrongLocation = $controller->marketplaceCartValidation($cartFor([$item('store_one', 'product_one', 'location_two')]));

    $connection->table('networks')->where('uuid', 'network_uuid')->update(['options' => json_encode(['multi_cart_enabled' => true])]);
    $mixedCurrency = $controller->marketplaceCartValidation($cartFor([
        $item('store_one', 'product_one', 'location_one'),
        $item('store_two', 'product_eur', 'location_two'),
    ]));
    $multiValid = $controller->marketplaceCartValidation($cartFor([
        $item('store_one', 'product_one', 'location_one'),
        $item('store_two', 'product_two', 'location_two'),
    ]));

    expect($empty->getStatusCode())->toBe(422)
        ->and($missingStore->getStatusCode())->toBe(422)
        ->and($foreignStore->getStatusCode())->toBe(403)
        ->and($offlineStore->getStatusCode())->toBe(422)
        ->and($singleValid)->toBeNull()
        ->and($multiDisabled->getData(true))->toBe(['error' => 'This marketplace only supports one store per cart.'])
        ->and($draftProduct->getData(true))->toBe(['error' => 'A product in this cart is no longer available from its store.'])
        ->and($wrongLocation->getData(true))->toBe(['error' => 'A store location in this cart is no longer valid.'])
        ->and($mixedCurrency->getData(true))->toBe(['error' => 'Marketplace carts cannot combine different currencies.'])
        ->and($multiValid)->toBeNull();
});

class CheckoutIntegratedVendorProbe extends Model
{
    public function api(): object
    {
        return new class {
            public function createOrderFromServiceQuote(ServiceQuote $serviceQuote, Request $request): array
            {
                return ['provider_order_id' => 'provider-probe-order'];
            }
        };
    }
}

class CheckoutAutomationOrderProbe extends Fleetbase\FleetOps\Models\Order
{
    public bool $pickup = true;
    public array $calls = [];

    public function isMeta($key): bool
    {
        return $key === 'is_pickup' && $this->pickup;
    }

    public function firstDispatchWithActivity(): Fleetbase\FleetOps\Models\Order
    {
        $this->calls[] = 'first_dispatch';

        return $this;
    }

    public function setStatus(?string $status, $andSave = true)
    {
        $this->calls[] = 'status:' . $status;

        return $this;
    }

    public function insertActivity(Fleetbase\FleetOps\Flow\Activity $activity, $location = [], $proof = null): string
    {
        $this->calls[] = 'activity:' . $activity->code;

        return 'tracking_status_uuid';
    }

    public function getLastLocation()
    {
        return [];
    }

    public function updateStatus($code = null)
    {
        $this->calls[] = 'update_status:' . $code;

        return $this;
    }
}

class TestableCheckoutController extends CheckoutController
{
    public static ?Fleetbase\FleetOps\Models\Order $statusFallbackOrder = null;
    public static ?Throwable $statusFallbackFailure                     = null;

    protected static function qpayForGateway(Gateway $gateway): Fleetbase\Storefront\Support\QPay
    {
        return new CheckoutQPayStub();
    }

    protected function createOrderFromCheckout($checkout, $transactionDetails, $notes = null)
    {
        if (static::$statusFallbackFailure) {
            if (static::$statusFallbackOrder) {
                $checkout->update([
                    'order_uuid' => static::$statusFallbackOrder->uuid,
                    'captured'   => true,
                ]);
            }

            throw static::$statusFallbackFailure;
        }

        return static::$statusFallbackOrder;
    }
}

function createCheckoutBoundarySchema(): void
{
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();

    foreach (['carts', 'gateways', 'contacts', 'service_quotes', 'integrated_vendors', 'checkouts', 'networks', 'stores', 'orders'] as $table) {
        $schema->dropIfExists($table);
    }

    $schema->create('carts', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('_key')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('checkout_uuid')->nullable();
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
    $schema->create('gateways', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('code')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->string('type')->nullable();
        $table->text('config')->nullable();
        $table->boolean('sandbox')->default(false);
        $table->string('callback_url')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('contacts', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('type')->nullable();
        $table->string('name')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('service_quotes', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->integer('amount')->default(0);
        $table->string('currency')->nullable();
        $table->string('integrated_vendor_uuid')->nullable();
        $table->text('meta')->nullable();
        $table->timestamp('expired_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('integrated_vendors', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('checkouts', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('store_uuid')->nullable();
        $table->string('network_uuid')->nullable();
        $table->string('cart_uuid')->nullable();
        $table->string('gateway_uuid')->nullable();
        $table->string('service_quote_uuid')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->string('owner_type')->nullable();
        $table->integer('amount')->default(0);
        $table->string('currency')->nullable();
        $table->boolean('is_cod')->default(false);
        $table->boolean('is_pickup')->default(false);
        $table->text('options')->nullable();
        $table->text('cart_state')->nullable();
        $table->string('token')->nullable();
        $table->string('order_uuid')->nullable();
        $table->boolean('captured')->default(false);
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('stores', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('backdrop_uuid')->nullable();
        $table->string('logo_uuid')->nullable();
        $table->string('order_config_uuid')->nullable();
        $table->string('key')->nullable();
        $table->string('name')->nullable();
        $table->text('description')->nullable();
        $table->text('translations')->nullable();
        $table->string('website')->nullable();
        $table->string('facebook')->nullable();
        $table->string('instagram')->nullable();
        $table->string('twitter')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->text('tags')->nullable();
        $table->string('currency')->nullable();
        $table->string('timezone')->nullable();
        $table->string('pod_method')->nullable();
        $table->text('options')->nullable();
        $table->text('alertable')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('networks', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('backdrop_uuid')->nullable();
        $table->string('logo_uuid')->nullable();
        $table->string('order_config_uuid')->nullable();
        $table->string('key')->nullable();
        $table->string('name')->nullable();
        $table->text('description')->nullable();
        $table->text('translations')->nullable();
        $table->string('website')->nullable();
        $table->string('facebook')->nullable();
        $table->string('instagram')->nullable();
        $table->string('twitter')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->text('tags')->nullable();
        $table->string('currency')->nullable();
        $table->string('timezone')->nullable();
        $table->string('pod_method')->nullable();
        $table->text('options')->nullable();
        $table->text('alertable')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('orders', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
}

function createCheckoutCaptureExecutionSchema(): void
{
    if (!Str::hasMacro('humanize')) {
        Str::macro('humanize', (new Fleetbase\Expansions\Str())->humanize());
    }
    Illuminate\Container\Container::getInstance()->instance('responsecache', new class {
        public function clear(): void
        {
        }
    });
    Illuminate\Container\Container::getInstance()->instance('DNS2D', new class {
        public function getBarcodePNG($value, $type): string
        {
            return 'encoded-barcode';
        }
    });
    Illuminate\Support\Facades\Facade::clearResolvedInstance('DNS2D');
    Model::setEventDispatcher(new class(Illuminate\Container\Container::getInstance()) extends Illuminate\Events\Dispatcher {
        public function dispatch($event, $payload = [], $halt = false)
        {
            if (is_string($event) && preg_match('/^eloquent\\.(created|updated|deleted|restored|saved):/', $event)) {
                return [];
            }

            return parent::dispatch($event, $payload, $halt);
        }
    });
    Model::clearBootedModels();
    createCheckoutBoundarySchema();
    $schema = Model::getConnectionResolver()->connection('mysql')->getSchemaBuilder();

    foreach (['custom_field_values', 'comments', 'files', 'products', 'transactions', 'transaction_items', 'payloads', 'entities', 'waypoints', 'places', 'store_locations', 'purchase_rates', 'companies'] as $table) {
        $schema->dropIfExists($table);
    }
    $schema->dropIfExists('orders');

    $schema->create('transactions', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('customer_uuid')->nullable();
        $table->string('customer_type')->nullable();
        $table->string('gateway_transaction_id')->nullable();
        $table->string('gateway')->nullable();
        $table->string('gateway_uuid')->nullable();
        $table->integer('amount')->default(0);
        $table->string('currency')->nullable();
        $table->string('description')->nullable();
        $table->string('type')->nullable();
        $table->string('status')->nullable();
        $table->string('settlement_status')->nullable();
        $table->timestamp('settled_at')->nullable();
        $table->integer('settled_amount')->default(0);
        $table->string('settled_currency')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('transaction_items', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('transaction_uuid')->nullable();
        $table->integer('amount')->default(0);
        $table->string('currency')->nullable();
        $table->text('details')->nullable();
        $table->string('code')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('payloads', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('_key')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('pickup_uuid')->nullable();
        $table->string('dropoff_uuid')->nullable();
        $table->string('return_uuid')->nullable();
        $table->string('payment_method')->nullable();
        $table->integer('cod_amount')->nullable();
        $table->string('cod_currency')->nullable();
        $table->string('cod_payment_method')->nullable();
        $table->string('type')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('entities', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('_key')->nullable();
        $table->string('payload_uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('customer_uuid')->nullable();
        $table->string('customer_type')->nullable();
        $table->string('photo_uuid')->nullable();
        $table->string('internal_id')->nullable();
        $table->string('name')->nullable();
        $table->text('description')->nullable();
        $table->string('currency')->nullable();
        $table->string('sku')->nullable();
        $table->integer('price')->nullable();
        $table->integer('sale_price')->nullable();
        $table->text('meta')->nullable();
        $table->string('slug')->nullable();
        $table->text('qr_code')->nullable();
        $table->text('barcode')->nullable();
        $table->string('place_uuid')->nullable();
        $table->integer('order')->default(0);
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('products', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('primary_image_uuid')->nullable();
        $table->string('name')->nullable();
        $table->text('description')->nullable();
        $table->string('currency')->nullable();
        $table->string('sku')->nullable();
        $table->integer('price')->default(0);
        $table->integer('sale_price')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('files', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('url')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('comments', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('parent_comment_uuid')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('custom_field_values', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('custom_field_uuid')->nullable();
        $table->text('value')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Fleetbase\FleetOps\Models\Entity::expand(
        'fromStorefrontProduct',
        Fleetbase\Storefront\Expansions\EntityExpansion::fromStorefrontProduct()
    );
    $schema->create('waypoints', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('_key')->nullable();
        $table->string('payload_uuid')->nullable();
        $table->string('place_uuid')->nullable();
        $table->string('type')->nullable();
        $table->integer('order')->default(0);
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('places', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('street1')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('store_locations', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('store_uuid')->nullable();
        $table->string('place_uuid')->nullable();
        $table->string('name')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('purchase_rates', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('_key')->nullable();
        $table->string('customer_uuid')->nullable();
        $table->string('customer_type')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('service_quote_uuid')->nullable();
        $table->string('transaction_uuid')->nullable();
        $table->string('payload_uuid')->nullable();
        $table->string('status')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
    });
    $schema->create('companies', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('name')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('orders', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('_key')->nullable();
        $table->string('internal_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('payload_uuid')->nullable();
        $table->string('customer_uuid')->nullable();
        $table->string('customer_type')->nullable();
        $table->string('facilitator_uuid')->nullable();
        $table->string('facilitator_type')->nullable();
        $table->string('transaction_uuid')->nullable();
        $table->string('purchase_rate_uuid')->nullable();
        $table->string('order_config_uuid')->nullable();
        $table->string('driver_assigned_uuid')->nullable();
        $table->boolean('adhoc')->default(false);
        $table->boolean('dispatched')->default(false);
        $table->timestamp('dispatched_at')->nullable();
        $table->integer('distance')->nullable();
        $table->integer('time')->nullable();
        $table->integer('orchestrator_priority')->nullable();
        $table->string('type')->nullable();
        $table->string('status')->nullable();
        $table->text('meta')->nullable();
        $table->text('notes')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
}

test('checkout initialization reports a missing configured gateway', function () {
    createCheckoutBoundarySchema();
    session([
        'company'             => 'company_uuid',
        'storefront_store'    => 'store_uuid',
        'storefront_network'  => null,
        'storefront_currency' => 'USD',
    ]);
    $request = InitializeCheckoutRequest::create('/checkouts/before', 'POST', [
        'gateway'  => 'missing_gateway',
        'customer' => 'customer_missing',
        'cart'     => 'browser-cart',
    ]);

    $response = (new CheckoutController())->beforeCheckout($request);

    expect($response->getData(true))->toBe(['error' => 'No gateway configured!']);
});

test('checkout initialization stops before gateway work when marketplace cart validation fails', function () {
    createCheckoutBoundarySchema();
    Model::getConnectionResolver()->connection('mysql')->table('carts')->insert([
        'uuid'              => 'marketplace_cart_uuid',
        'public_id'         => 'cart_marketplace',
        'company_uuid'      => 'company_uuid',
        'unique_identifier' => 'marketplace-browser-cart',
        'currency'          => 'USD',
        'items'             => '[]',
        'events'            => '[]',
    ]);
    session([
        'company'             => 'company_uuid',
        'storefront_store'    => null,
        'storefront_network'  => 'network_uuid',
        'storefront_currency' => 'USD',
    ]);

    $response = (new CheckoutController())->beforeCheckout(
        InitializeCheckoutRequest::create('/checkouts/before', 'POST', [
            'gateway' => 'missing_gateway',
            'cart'    => 'marketplace-browser-cart',
        ])
    );

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true))->toBe(['error' => 'The cart is empty.']);
});

test('checkout automation delegates accepted and pickup-dispatched orders to storefront workflows', function () {
    createCheckoutBoundarySchema();
    $schema = Model::getConnectionResolver()->connection('mysql')->getSchemaBuilder();
    $schema->dropIfExists('order_configs');
    $schema->create('order_configs', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->text('flow')->nullable();
        $table->text('activities')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Model::getConnectionResolver()->connection('mysql')->table('order_configs')->insert([
        'uuid'       => 'order_config_uuid',
        'flow'       => '[]',
        'activities' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    Model::getConnectionResolver()->connection('mysql')->table('stores')->insert([
        'uuid'      => 'store_uuid',
        'public_id' => 'store_abcdefgh',
        'key'       => 'store_key',
        'name'      => 'Automation store',
        'options'   => '{}',
    ]);
    session(['storefront_key' => 'store_key']);
    $customer = new class extends Model {
        public function notify($notification): void
        {
        }
    };
    $order = new CheckoutAutomationOrderProbe();
    $order->forceFill([
        'order_config_uuid' => 'order_config_uuid',
        'meta'              => ['storefront_id' => 'store_abcdefgh'],
    ]);
    $order->setRelation('customer', $customer);
    $controller = new CheckoutAutomationControllerProbe();

    $controller->accept($order);
    $controller->dispatch($order);
    $quote = new ServiceQuote();
    $quote->setRelation('integratedVendor', new CheckoutIntegratedVendorProbe());
    $vendorOrder                                 = $controller->vendor($quote, Request::create('/checkout/vendor', 'POST'));
    $vendorController                            = new CheckoutIntegratedVendorStub();
    CheckoutIntegratedVendorStub::$vendorFailure = null;
    $safeVendorOrder                             = $vendorController->vendorSafely($quote, Request::create('/checkout/vendor', 'POST'));
    CheckoutIntegratedVendorStub::$vendorFailure = new RuntimeException('Vendor checkout unavailable');
    $safeVendorFailure                           = $vendorController->vendorSafely($quote, Request::create('/checkout/vendor', 'POST'));
    CheckoutIntegratedVendorStub::$vendorFailure = null;
    session(['storefront_key' => null]);

    expect($order->calls)->toContain(
        'first_dispatch',
        'status:accepted',
        'activity:accepted',
        'update_status:pickup_ready'
    )->and($vendorOrder)->toBe(['provider_order_id' => 'provider-probe-order'])
        ->and($safeVendorOrder['order'])->toBe(['provider_order_id' => 'vendor-order-123'])
        ->and($safeVendorFailure['error']->getData(true))->toBe(['error' => 'Vendor checkout unavailable']);
});

test('checkout origin resolution honors explicit locations and falls back to the stores first location', function () {
    createCheckoutCaptureExecutionSchema();
    $connection       = Model::getConnectionResolver()->connection('mysql');
    $storefrontSchema = Model::getConnectionResolver()->connection('mysql')->getSchemaBuilder();
    $storefrontSchema->dropIfExists('food_trucks');
    $storefrontSchema->create('food_trucks', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $connection->table('stores')->insert([
        'uuid'      => 'store_uuid',
        'public_id' => 'store_abcdefgh',
        'key'       => 'store_key',
        'name'      => 'Origin store',
        'options'   => '{}',
    ]);
    $connection->table('store_locations')->insert([
        [
            'uuid'       => 'location_one_uuid',
            'public_id'  => 'store_location_abcdefgh',
            'store_uuid' => 'store_uuid',
            'place_uuid' => 'place_one_uuid',
            'name'       => 'First location',
        ],
        [
            'uuid'       => 'location_two_uuid',
            'public_id'  => 'store_location_ijklmnop',
            'store_uuid' => 'store_uuid',
            'place_uuid' => 'place_two_uuid',
            'name'       => 'Second location',
        ],
    ]);
    $explicitCart = new Cart();
    $explicitCart->forceFill([
        'items' => [(object) [
            'store_id'         => 'store_abcdefgh',
            'store_location_id'=> 'store_location_ijklmnop',
        ]],
    ]);
    $fallbackCart = new Cart();
    $fallbackCart->forceFill([
        'items' => [(object) [
            'store_id' => 'store_abcdefgh',
        ]],
    ]);
    $controller    = new CheckoutAutomationControllerProbe();
    $foodTruckCart = new Cart();
    $foodTruckCart->forceFill([
        'items' => [(object) ['food_truck_id' => 'food_truck_missing']],
    ]);
    $foodTruck = new Fleetbase\Storefront\Models\FoodTruck();
    $foodTruck->forceFill([
        'public_id' => 'food_truck_abcdefgh',
        'name'      => 'Mobile Kitchen',
    ]);
    $foodTruck->setRelation('zone', (object) ['name' => 'Central Zone']);
    $foodTruck->setRelation('serviceArea', (object) [
        'name'    => 'Downtown',
        'country' => 'MN',
    ]);
    $vehicle = new Fleetbase\FleetOps\Models\Vehicle();
    $vehicle->forceFill([
        'location' => new Fleetbase\LaravelMysqlSpatial\Types\Point(47.918, 106.917),
    ]);
    $driver = new Fleetbase\FleetOps\Models\Driver();
    $driver->forceFill(['uuid' => 'driver_uuid']);
    $vehicle->setRelation('driver', $driver);
    $foodTruck->setRelation('vehicle', $vehicle);
    [$foodTruckMeta, $foodTruckInput] = $controller->foodTruckOrderData(
        $foodTruck,
        ['checkout_id' => 'checkout_abcdefgh'],
        []
    );

    expect($controller->storeLocationOrigin('existing_origin', $explicitCart))->toBe('existing_origin')
        ->and($controller->storeLocationOrigin(null, $explicitCart))->toBe('place_two_uuid')
        ->and($controller->storeLocationOrigin(null, $fallbackCart))->toBe('place_one_uuid')
        ->and($controller->foodTruck($foodTruckCart))->toBeNull()
        ->and($controller->foodTruckOrigin(null))->toBeNull()
        ->and($controller->foodTruckOrigin($foodTruck))->toMatchArray([
            'name'    => 'Mobile Kitchen',
            'street1' => 'Central Zone',
            'city'    => 'Downtown',
            'country' => 'MN',
        ])
        ->and($foodTruckMeta['food_truck_id'])->toBe('food_truck_abcdefgh')
        ->and($foodTruckInput['driver_assigned_uuid'])->toBe('driver_uuid')
        ->and($controller->foodTruckOrderData(null, ['existing' => true], []))->toBe([
            ['existing' => true],
            [],
        ]);
});

test('checkout initialization rejects configured but unsupported gateway types', function () {
    createCheckoutBoundarySchema();
    session([
        'storefront_store'   => 'store_uuid',
        'storefront_network' => null,
    ]);
    Model::getConnectionResolver()->connection('mysql')->table('gateways')->insert([
        'uuid'       => 'gateway_uuid',
        'code'       => 'manual-bank',
        'owner_uuid' => 'store_uuid',
        'type'       => 'manual-bank',
        'config'     => json_encode([]),
    ]);

    $response = (new CheckoutController())->beforeCheckout(
        InitializeCheckoutRequest::create('/checkouts/before', 'POST', [
            'gateway'  => 'manual-bank',
            'customer' => 'customer_missing',
            'cart'     => 'browser-cart',
            'pickup'   => true,
        ])
    );

    expect($response->getData(true))->toBe(['error' => 'Unable to initialize checkout!']);
});

test('checkout initialization creates a cash checkout from persisted customer cart and quote contracts', function () {
    createCheckoutBoundarySchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('contacts')->insert([
        'uuid'      => 'customer_uuid',
        'public_id' => 'contact_abcdefgh',
        'type'      => 'customer',
    ]);
    $connection->table('carts')->insert([
        'uuid'              => 'cart_uuid',
        'public_id'         => 'cart_abcdefgh',
        'company_uuid'      => 'company_uuid',
        'unique_identifier' => 'browser-cart',
        'currency'          => 'USD',
        'items'             => json_encode([
            [
                'id'       => 'line_one',
                'quantity' => 1,
                'subtotal' => 2000,
            ],
        ]),
        'events' => '[]',
    ]);
    $connection->table('service_quotes')->insert([
        'uuid'      => 'quote_uuid',
        'public_id' => 'quote_abcdefgh',
        'amount'    => 500,
        'meta'      => '{}',
    ]);
    session([
        'company'             => 'company_uuid',
        'storefront_store'    => 'store_uuid',
        'storefront_network'  => null,
        'storefront_currency' => 'USD',
    ]);
    $request = InitializeCheckoutRequest::create('/checkouts/before', 'POST', [
        'gateway'      => 'cash',
        'cash'         => true,
        'customer'     => 'customer_abcdefgh',
        'cart'         => 'browser-cart',
        'serviceQuote' => 'quote_abcdefgh',
        'tip'          => '10%',
        'deliveryTip'  => 100,
    ]);

    $response = (new CheckoutController())->beforeCheckout($request);
    $checkout = Checkout::query()->first();

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true)['token'])->toBe($checkout->token)
        // GET /checkouts/status needs the chkt_* public id as well as the token, and only
        // the QPay path used to return it.
        ->and($response->getData(true)['checkout'])->toBe($checkout->public_id)
        ->and($checkout->owner_uuid)->toBe('customer_uuid')
        ->and($checkout->cart_uuid)->toBe('cart_uuid')
        ->and($checkout->service_quote_uuid)->toBe('quote_uuid')
        ->and($checkout->amount)->toBe(2800)
        ->and($checkout->is_cod)->toBeTrue();
});

test('checkout initialization dispatches configured stripe and qpay gateway types', function () {
    createCheckoutBoundarySchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('contacts')->insert([
        'uuid'      => 'customer_uuid',
        'public_id' => 'contact_abcdefgh',
        'type'      => 'customer',
    ]);
    $connection->table('carts')->insert([
        'uuid'              => 'cart_uuid',
        'public_id'         => 'cart_abcdefgh',
        'company_uuid'      => 'company_uuid',
        'unique_identifier' => 'browser-cart',
        'currency'          => 'USD',
        'items'             => '[]',
        'events'            => '[]',
    ]);
    $connection->table('service_quotes')->insert([
        'uuid'      => 'quote_uuid',
        'public_id' => 'quote_abcdefgh',
        'amount'    => 0,
        'meta'      => '{}',
    ]);
    $connection->table('gateways')->insert([
        [
            'uuid'       => 'stripe_gateway_uuid',
            'code'       => 'stripe',
            'owner_uuid' => 'store_uuid',
            'type'       => 'stripe',
            'config'     => '{}',
        ],
        [
            'uuid'       => 'qpay_gateway_uuid',
            'code'       => 'qpay',
            'owner_uuid' => 'store_uuid',
            'type'       => 'qpay',
            'config'     => '{}',
        ],
    ]);
    session([
        'storefront_store'    => 'store_uuid',
        'storefront_network'  => null,
        'storefront_currency' => 'USD',
    ]);
    $controller = new CheckoutController();
    $input      = [
        'customer'     => 'customer_abcdefgh',
        'cart'         => 'browser-cart',
        'serviceQuote' => 'quote_abcdefgh',
        'pickup'       => true,
    ];

    $stripe = $controller->beforeCheckout(InitializeCheckoutRequest::create(
        '/checkouts/before',
        'POST',
        [...$input, 'gateway' => 'stripe']
    ));
    $qpay = $controller->beforeCheckout(InitializeCheckoutRequest::create(
        '/checkouts/before',
        'POST',
        [...$input, 'gateway' => 'qpay']
    ));

    expect($stripe->getData(true))->toBe(['error' => 'Gateway not configured correctly!'])
        ->and($qpay->getData(true))->toBe(['error' => 'Gateway not configured correctly!']);
});

test('checkout status validates credentials and unknown checkout sessions', function () {
    createCheckoutBoundarySchema();
    $controller = new CheckoutController();

    $missing = $controller->getCheckoutStatus(Request::create('/checkouts/status'));
    $unknown = $controller->getCheckoutStatus(Request::create('/checkouts/status', 'GET', [
        'checkout' => 'checkout_missing',
        'token'    => 'invalid-token',
    ]));

    expect($missing->getStatusCode())->toBe(400)
        ->and($missing->getData(true))->toBe([
            'error' => 'Missing required parameters: checkout and token',
        ])
        ->and($unknown->getStatusCode())->toBe(404)
        ->and($unknown->getData(true))->toBe(['error' => 'Checkout not found']);
});

test('checkout status contains persistence failures behind a stable server error contract', function () {
    createCheckoutBoundarySchema();
    Model::getConnectionResolver()->connection('mysql')->getSchemaBuilder()->drop('checkouts');

    $response = (new CheckoutController())->getCheckoutStatus(Request::create(
        '/checkouts/status',
        'GET',
        [
            'checkout' => 'checkout_abcdefgh',
            'token'    => 'checkout-token',
        ]
    ));

    expect($response->getStatusCode())->toBe(500)
        ->and($response->getData(true))->toMatchArray([
            'error' => 'Failed to retrieve checkout status',
        ])
        ->and($response->getData(true)['message'])->toContain('no such table');
});

test('checkout status reports gateway agnostic pending and completed sessions', function () {
    createCheckoutBoundarySchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('checkouts')->insert([
        [
            'uuid'      => 'checkout_pending_uuid',
            'public_id' => 'checkout_pending',
            'token'     => 'token_pending',
            'captured'  => false,
        ],
        [
            'uuid'      => 'checkout_complete_uuid',
            'public_id' => 'checkout_complete',
            'token'     => 'token_complete',
            'captured'  => true,
        ],
    ]);
    $controller = new CheckoutController();

    $pending = $controller->getCheckoutStatus(Request::create('/checkouts/status', 'GET', [
        'checkout' => 'checkout_pending',
        'token'    => 'token_pending',
    ]));
    $completed = $controller->getCheckoutStatus(Request::create('/checkouts/status', 'GET', [
        'checkout' => 'checkout_complete',
        'token'    => 'token_complete',
    ]));

    expect($pending->getData(true))->toBe([
        'status'   => 'pending',
        'checkout' => 'checkout_pending',
        'payment'  => null,
        'order'    => null,
    ])->and($completed->getData(true))->toBe([
        'status'   => 'completed',
        'checkout' => 'checkout_complete',
        'payment'  => null,
        'order'    => null,
    ]);
});

test('checkout status reports qpay pending paid fallback and provider failure states', function () {
    createCheckoutCaptureExecutionSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('gateways')->insert([
        'uuid'         => 'qpay_gateway_uuid',
        'code'         => 'qpay',
        'owner_uuid'   => 'store_uuid',
        'type'         => 'qpay',
        'sandbox'      => true,
        'callback_url' => 'https://storefront.test/qpay',
        'config'       => json_encode([
            'username' => 'merchant',
            'password' => 'secret',
        ]),
    ]);
    $connection->table('checkouts')->insert([
        'uuid'         => 'checkout_uuid',
        'public_id'    => 'checkout_abcdefgh',
        'gateway_uuid' => 'qpay_gateway_uuid',
        'options'      => json_encode(['qpay_invoice_id' => 'invoice_checkout']),
        'token'        => 'checkout-token',
        'captured'     => false,
    ]);
    $connection->table('orders')->insert([
        'uuid'      => 'status_order_uuid',
        'public_id' => 'order_status',
    ]);
    CheckoutQPayStub::$failure                         = null;
    CheckoutQPayStub::$paymentCheckResult              = (object) ['rows' => []];
    CheckoutQPayStub::$sandboxUsed                     = false;
    TestableCheckoutController::$statusFallbackOrder   = null;
    TestableCheckoutController::$statusFallbackFailure = null;
    $controller                                        = new TestableCheckoutController();
    $request                                           = fn () => Request::create('/checkouts/status', 'GET', [
        'checkout' => 'checkout_abcdefgh',
        'token'    => 'checkout-token',
    ]);

    $pending                              = $controller->getCheckoutStatus($request());
    CheckoutQPayStub::$paymentCheckResult = (object) [
        'rows' => [
            (object) [
                'payment_id'     => 'payment_checkout',
                'payment_status' => 'PAID',
                'payment_amount' => 2500,
                'payment_date'   => '2026-07-27 10:00:00',
                'payment_wallet' => 'QPay',
            ],
        ],
    ];
    session(['storefront_key' => null]);
    TestableCheckoutController::$statusFallbackOrder = Fleetbase\FleetOps\Models\Order::where(
        'uuid',
        'status_order_uuid'
    )->firstOrFail();
    $paid                                              = $controller->getCheckoutStatus($request());
    TestableCheckoutController::$statusFallbackFailure = new RuntimeException('Concurrent status capture');
    $raceRecovered                                     = $controller->getCheckoutStatus($request());
    TestableCheckoutController::$statusFallbackFailure = null;
    $alreadyCompleted                                  = $controller->getCheckoutStatus($request());
    CheckoutQPayStub::$failure                         = new RuntimeException('QPay status unavailable');
    $failure                                           = $controller->getCheckoutStatus($request());
    CheckoutQPayStub::$failure                         = null;
    $pendingData                                       = $pending->getData(true);
    $paidData                                          = $paid->getData(true);
    expect($pendingData['status'])->toBe('pending')
        ->and($pendingData['payment'])->toBeNull()
        ->and($pendingData['order'])->toBeNull()
        ->and($paidData['status'])->toBe('completed')
        ->and($paidData['payment']['payment_id'])->toBe('payment_checkout')
        ->and($paidData['payment']['payment_status'])->toBe('PAID')
        ->and($paidData['payment']['payment_amount'])->toBe(2500)
        ->and($paidData['payment']['payment_wallet'])->toBe('QPay')
        ->and($paidData['order']['id'])->toBe('order_status')
        ->and($raceRecovered->getData(true)['status'])->toBe('completed')
        ->and($alreadyCompleted->getData(true)['status'])->toBe('completed')
        ->and(CheckoutQPayStub::$sandboxUsed)->toBeTrue()
        ->and($failure->getStatusCode())->toBe(500)
        ->and($failure->getData(true))->toBe([
            'error'   => 'Failed to retrieve checkout status',
            'message' => 'QPay status unavailable',
        ]);
});

test('customer lookup safely returns null for unknown customer aliases', function () {
    createCheckoutBoundarySchema();

    expect(Fleetbase\Storefront\Models\Customer::findFromCustomerId('customer_missing'))->toBeNull()
        ->and(Fleetbase\Storefront\Models\Customer::findFromCustomerId('contact_missing'))->toBeNull();
});

test('cash checkout persists calculated totals ownership and cart state without a provider call', function () {
    createCheckoutBoundarySchema();
    session([
        'company'            => 'company_uuid',
        'storefront_store'   => 'store_uuid',
        'storefront_network' => null,
    ]);
    $cart = new Cart();
    $cart->forceFill([
        'uuid'     => 'cart_uuid',
        'currency' => 'USD',
        'items'    => [
            [
                'id'       => 'line_one',
                'quantity' => 1,
                'subtotal' => 1000,
            ],
        ],
        'events' => [],
    ]);
    $customer = new Fleetbase\Storefront\Models\Customer();
    $customer->forceFill(['uuid' => 'customer_uuid']);
    $gateway = Gateway::cash();
    $gateway->forceFill(['uuid' => 'gateway_uuid']);
    $quote = new ServiceQuote();
    $quote->forceFill(['uuid' => 'quote_uuid', 'amount' => 300]);
    $options = (object) [
        'is_pickup'    => false,
        'tip'          => '10%',
        'delivery_tip' => 100,
    ];

    $response = CheckoutController::initializeCashCheckout(
        $customer,
        $gateway,
        $quote,
        $cart,
        $options,
        Request::create('/checkout')
    );
    $checkout = Checkout::query()->first();

    expect($response->getStatusCode())->toBe(200)
        ->and($checkout)->not->toBeNull()
        ->and($checkout->company_uuid)->toBe('company_uuid')
        ->and($checkout->store_uuid)->toBe('store_uuid')
        ->and($checkout->cart_uuid)->toBe('cart_uuid')
        ->and($checkout->owner_uuid)->toBe('customer_uuid')
        ->and($checkout->amount)->toBe(1500)
        ->and($checkout->currency)->toBe('USD')
        ->and($checkout->is_cod)->toBeTrue()
        ->and($checkout->is_pickup)->toBeFalse()
        ->and($checkout->cart_state['subtotal'])->toBe(1000);
});

test('cash checkout infers the owning store from a single public store cart item', function () {
    createCheckoutBoundarySchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('stores')->insert([
        'uuid'         => 'store_uuid',
        'public_id'    => 'store_abcdefgh',
        'company_uuid' => 'company_uuid',
        'key'          => 'store_key',
        'name'         => 'Test store',
        'currency'     => 'USD',
    ]);
    session([
        'company'            => 'company_uuid',
        'storefront_store'   => null,
        'storefront_network' => 'network_uuid',
    ]);
    $cart = new Cart();
    $cart->forceFill([
        'uuid'     => 'cart_uuid',
        'currency' => 'USD',
        'items'    => [
            [
                'id'       => 'line_one',
                'store_id' => 'store_abcdefgh',
                'quantity' => 1,
                'subtotal' => 1000,
            ],
        ],
        'events' => [],
    ]);
    $customer = new Fleetbase\Storefront\Models\Customer();
    $customer->forceFill(['uuid' => 'customer_uuid']);
    $gateway = Gateway::cash();
    $quote   = new ServiceQuote();
    $quote->forceFill(['uuid' => 'quote_uuid', 'amount' => 0]);

    CheckoutController::initializeCashCheckout(
        $customer,
        $gateway,
        $quote,
        $cart,
        (object) ['is_pickup' => true],
        Request::create('/checkout')
    );

    expect(Checkout::query()->value('store_uuid'))->toBe('store_uuid')
        ->and(Checkout::query()->value('network_uuid'))->toBe('network_uuid');
});

test('checkout qpay factory maps persisted gateway credentials into a provider client', function () {
    $gateway = new Gateway();
    $gateway->forceFill([
        'callback_url' => 'https://storefront.test/qpay/callback',
        'config'       => [
            'username' => 'merchant',
            'password' => 'secret',
        ],
    ]);
    $method = new ReflectionMethod(CheckoutController::class, 'qpayForGateway');

    $qpay = $method->invoke(null, $gateway);

    expect($qpay)->toBeInstanceOf(Fleetbase\Storefront\Support\QPay::class);
});

test('checkout after hook accepts the completed checkout request contract', function () {
    $response = (new CheckoutController())->afterCheckout(
        Request::create('/checkout/after', 'POST', ['checkout' => 'checkout_abcdefgh'])
    );

    expect($response)->toBeNull();
});

test('stripe checkout rejects incomplete gateway configuration before provider calls', function () {
    $cart = new Cart();
    $cart->forceFill([
        'uuid'     => 'cart_uuid',
        'currency' => 'USD',
        'items'    => [],
        'events'   => [],
    ]);
    $customer = new Contact();
    $customer->forceFill(['uuid' => 'customer_uuid']);
    $gateway = new Gateway();
    $gateway->forceFill([
        'uuid'   => 'gateway_uuid',
        'type'   => 'stripe',
        'config' => [],
    ]);

    $response = CheckoutController::initializeStripeCheckout(
        $customer,
        $gateway,
        null,
        $cart,
        (object) ['is_pickup' => true],
        Request::create('/checkout')
    );
    $gateway->config = ['secret_key' => '   '];
    $blankResponse   = CheckoutController::initializeStripeCheckout(
        $customer,
        $gateway,
        null,
        $cart,
        (object) ['is_pickup' => true],
        Request::create('/checkout')
    );

    expect($response->getData(true))->toBe(['error' => 'Gateway not configured correctly!'])
        ->and($blankResponse->getData(true))->toBe(['error' => 'Gateway not configured correctly!']);
});

test('stripe checkout creates provider intents and persists its checkout token', function () {
    createCheckoutBoundarySchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    session([
        'company'            => 'company_uuid',
        'storefront_store'   => 'store_uuid',
        'storefront_network' => null,
    ]);
    $cart = new Cart();
    $cart->forceFill([
        'uuid'     => 'cart_uuid',
        'currency' => 'USD',
        'items'    => [
            ['id' => 'line_one', 'quantity' => 1, 'subtotal' => 2000],
        ],
        'events' => [],
    ]);
    $customer = new Fleetbase\Storefront\Models\Customer();
    $customer->forceFill([
        'uuid'      => 'customer_uuid',
        'public_id' => 'contact_public',
        'name'      => 'Ada Buyer',
        'email'     => 'ada@example.test',
        'phone'     => '+97699112233',
        'meta'      => [
            'stripe_id'                => 'cus_checkout',
            'stripe_payment_method_id' => 'pm_checkout',
        ],
    ]);
    $gateway = new Gateway();
    $gateway->forceFill([
        'uuid'   => 'stripe_gateway_uuid',
        'type'   => 'stripe',
        'config' => ['secret_key' => 'sk_test_storefront'],
    ]);
    Stripe\ApiRequestor::setHttpClient(new class implements Stripe\HttpClient\ClientInterface {
        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            if (str_contains($absUrl, '/payment_methods/')) {
                return [json_encode([
                    'id'       => 'pm_checkout',
                    'object'   => 'payment_method',
                    'customer' => 'cus_checkout',
                    'type'     => 'card',
                ]), 200, []];
            }

            if (str_ends_with($absUrl, '/customers')) {
                return [json_encode([
                    'id'     => 'cus_checkout',
                    'object' => 'customer',
                    'name'   => 'Ada Buyer',
                    'email'  => 'ada@example.test',
                ]), 200, []];
            }

            if (str_contains($absUrl, '/ephemeral_keys')) {
                return [json_encode([
                    'id'     => 'ephkey_checkout',
                    'object' => 'ephemeral_key',
                    'secret' => 'eph_secret',
                ]), 200, []];
            }

            return [json_encode([
                'id'            => 'pi_checkout',
                'object'        => 'payment_intent',
                'client_secret' => 'pi_secret',
                'customer'      => 'cus_checkout',
                'status'        => 'requires_payment_method',
            ]), 200, []];
        }
    });

    $response = CheckoutController::initializeStripeCheckout(
        $customer,
        $gateway,
        null,
        $cart,
        (object) ['is_pickup' => true, 'tip' => '10%'],
        Request::create('/checkout')
    );
    $connection->table('contacts')->insert([
        'uuid'      => 'customer_without_stripe_uuid',
        'public_id' => 'contact_without_stripe',
        'type'      => 'customer',
        'name'      => 'New Buyer',
        'email'     => 'new-buyer@example.test',
        'meta'      => '{}',
    ]);
    $customerWithoutStripe = Fleetbase\Storefront\Models\Customer::where(
        'uuid',
        'customer_without_stripe_uuid'
    )->firstOrFail();
    $createdCustomerResponse = CheckoutController::initializeStripeCheckout(
        $customerWithoutStripe,
        $gateway,
        null,
        $cart,
        (object) ['is_pickup' => true],
        Request::create('/checkout')
    );
    Stripe\ApiRequestor::setHttpClient(new Stripe\HttpClient\CurlClient());
    $checkout = Checkout::query()->first();
    $data     = $response->getData(true);

    expect($data['paymentIntent'])->toBe('pi_checkout')
        ->and($data['clientSecret'])->toBe('pi_secret')
        ->and($data['ephemeralKey'])->toBe('eph_secret')
        ->and($data['customerId'])->toBe('cus_checkout')
        ->and($data['token'])->toBe($checkout->token)
        ->and($data['checkout'])->toBe($checkout->public_id)
        ->and($checkout->owner_uuid)->toBe('customer_uuid')
        ->and($checkout->amount)->toBe(2200)
        ->and($checkout->is_pickup)->toBeTrue()
        ->and($createdCustomerResponse->getData(true)['customerId'])->toBe('cus_checkout');
});

test('stripe checkout retries missing customers and contains ephemeral-key and intent failures', function () {
    createCheckoutBoundarySchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('contacts')->insert([
        'uuid'         => 'customer_uuid',
        'public_id'    => 'contact_public',
        'company_uuid' => 'company_uuid',
        'type'         => 'customer',
        'name'         => 'Ada Buyer',
        'email'        => 'ada@example.test',
        'phone'        => '+97699112233',
        'meta'         => json_encode(['stripe_id' => 'cus_stale']),
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
    session([
        'company'          => 'company_uuid',
        'storefront_store' => 'store_uuid',
    ]);
    $cart = new Cart();
    $cart->forceFill([
        'uuid'     => 'cart_uuid',
        'currency' => 'USD',
        'items'    => [],
        'events'   => [],
    ]);
    $gateway = new Gateway();
    $gateway->forceFill([
        'uuid'   => 'stripe_gateway_uuid',
        'type'   => 'stripe',
        'config' => ['secret_key' => 'sk_test_storefront'],
    ]);
    $http = new class implements Stripe\HttpClient\ClientInterface {
        public string $scenario    = 'ephemeral_failure';
        public int $ephemeralCalls = 0;

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            if (str_contains($absUrl, '/ephemeral_keys')) {
                $this->ephemeralCalls++;
                if ($this->scenario === 'ephemeral_failure') {
                    return [json_encode(['error' => ['message' => 'Ephemeral key rejected', 'type' => 'invalid_request_error']]), 400, []];
                }
                if ($this->scenario === 'missing_customer' && $this->ephemeralCalls === 1) {
                    return [json_encode(['error' => ['message' => 'No such customer: cus_stale', 'type' => 'invalid_request_error']]), 400, []];
                }

                return [json_encode(['id' => 'ephkey_retry', 'object' => 'ephemeral_key', 'secret' => 'eph_retry_secret']), 200, []];
            }
            if (str_ends_with($absUrl, '/customers')) {
                return [json_encode(['id' => 'cus_recreated', 'object' => 'customer']), 200, []];
            }
            if ($this->scenario === 'intent_failure') {
                return [json_encode(['error' => ['message' => 'Payment intent rejected', 'type' => 'invalid_request_error']]), 400, []];
            }

            return [json_encode([
                'id'            => 'pi_retry',
                'object'        => 'payment_intent',
                'client_secret' => 'pi_retry_secret',
                'status'        => 'requires_payment_method',
            ]), 200, []];
        }
    };
    Stripe\ApiRequestor::setHttpClient($http);
    $controllerOptions = (object) ['is_pickup' => true];

    $customer         = Fleetbase\Storefront\Models\Customer::where('uuid', 'customer_uuid')->firstOrFail();
    $ephemeralFailure = CheckoutController::initializeStripeCheckout(
        $customer,
        $gateway,
        null,
        $cart,
        $controllerOptions,
        Request::create('/checkout')
    );

    $http->scenario       = 'intent_failure';
    $http->ephemeralCalls = 0;
    $intentFailure        = CheckoutController::initializeStripeCheckout(
        $customer->fresh(),
        $gateway,
        null,
        $cart,
        $controllerOptions,
        Request::create('/checkout')
    );

    $http->scenario       = 'missing_customer';
    $http->ephemeralCalls = 0;
    $retried              = CheckoutController::initializeStripeCheckout(
        $customer->fresh(),
        $gateway,
        null,
        $cart,
        $controllerOptions,
        Request::create('/checkout')
    );
    Stripe\ApiRequestor::setHttpClient(new Stripe\HttpClient\CurlClient());

    expect($ephemeralFailure->getData(true))->toBe(['error' => 'Error from Stripe: Ephemeral key rejected'])
        ->and($intentFailure->getData(true))->toBe(['error' => 'Payment intent rejected'])
        ->and($retried->getData(true)['customerId'])->toBe('cus_recreated')
        ->and($http->ephemeralCalls)->toBe(2);
});

test('qpay checkout rejects incomplete gateway configuration before authentication or invoice calls', function () {
    $cart = new Cart();
    $cart->forceFill([
        'uuid'     => 'cart_uuid',
        'currency' => 'MNT',
        'items'    => [],
        'events'   => [],
    ]);
    $customer = new Contact();
    $customer->forceFill(['uuid' => 'customer_uuid']);
    $gateway = new Gateway();
    $gateway->forceFill([
        'uuid'   => 'gateway_uuid',
        'type'   => 'qpay',
        'config' => [],
    ]);

    $response = CheckoutController::initializeQPayCheckout(
        $customer,
        $gateway,
        null,
        $cart,
        (object) ['is_pickup' => true],
        Request::create('/checkout')
    );

    expect($response->getData(true))->toBe(['error' => 'Gateway not configured correctly!']);
});

test('qpay checkout creates sandbox ebarimt invoices and persists invoice metadata', function () {
    createCheckoutBoundarySchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('stores')->insert([
        'uuid'         => 'store_uuid',
        'public_id'    => 'store_abcdefgh',
        'company_uuid' => 'company_uuid',
        'key'          => 'store_key',
        'name'         => 'QPay store',
        'currency'     => 'MNT',
        'options'      => '{}',
    ]);
    session([
        'company'            => 'company_uuid',
        'storefront_key'     => 'store_key',
        'storefront_store'   => 'store_uuid',
        'storefront_network' => null,
    ]);
    $cart = new Cart();
    $cart->forceFill([
        'uuid'     => 'cart_uuid',
        'currency' => 'MNT',
        'items'    => [
            [
                'id'                  => 'line_one',
                'product_id'          => null,
                'name'                => 'Delivery box',
                'quantity'            => 2,
                'price'               => 1000,
                'subtotal'            => 2000,
                'classification_code' => '2111100',
                'tax_product_code'    => '319',
            ],
        ],
        'events' => [],
    ]);
    $customer = new Fleetbase\Storefront\Models\Customer();
    $customer->forceFill([
        'uuid'  => 'customer_uuid',
        'name'  => 'Ada Buyer',
        'email' => 'ada@example.test',
        'phone' => '+97699112233',
        'meta'  => [],
    ]);
    $gateway = new Gateway();
    $gateway->forceFill([
        'uuid'         => 'qpay_gateway_uuid',
        'type'         => 'qpay',
        'sandbox'      => true,
        'callback_url' => 'https://storefront.test/qpay',
        'config'       => [
            'username' => 'merchant',
            'password' => 'secret',
        ],
    ]);
    CheckoutQPayStub::$sandboxUsed      = false;
    CheckoutQPayStub::$authenticated    = false;
    CheckoutQPayStub::$invoiceKind      = null;
    CheckoutQPayStub::$invoiceArguments = [];

    $response = TestableCheckoutController::initializeQPayCheckout(
        $customer,
        $gateway,
        null,
        $cart,
        (object) [
            'is_pickup'  => true,
            'testPayment'=> 'success',
        ],
        Request::create('/checkout', 'POST', ['ebarimt_registration_no' => '1234567'])
    );
    $checkout = Checkout::query()->first();
    $data     = $response->getData(true);

    expect($data['invoice']['invoice_id'])->toBe('invoice_checkout')
        ->and($data['checkout'])->toBe($checkout->public_id)
        ->and($data['token'])->toBe($checkout->token)
        ->and($data['checkout'])->toBe($checkout->public_id)
        ->and($checkout->amount)->toBe(2000)
        ->and($checkout->getOption('qpay_invoice_id'))->toBe('invoice_checkout')
        ->and($customer->getMeta('ebarimt_registration_no'))->toBe('1234567')
        ->and(CheckoutQPayStub::$sandboxUsed)->toBeTrue()
        ->and(CheckoutQPayStub::$authenticated)->toBeTrue()
        ->and(CheckoutQPayStub::$invoiceKind)->toBe('ebarimt')
        ->and(CheckoutQPayStub::$invoiceArguments[0])->toBe('TEST_INVOICE')
        ->and(CheckoutQPayStub::$invoiceArguments[7])->not->toBeEmpty();

    $gateway->forceFill([
        'uuid'         => 'qpay_gateway_uuid',
        'type'         => 'qpay',
        'sandbox'      => false,
        'callback_url' => 'https://storefront.test/qpay',
        'config'       => [
            'username' => 'merchant',
            'password' => 'secret',
        ],
    ]);
    CheckoutQPayStub::$invoiceKind      = null;
    CheckoutQPayStub::$invoiceArguments = [];
    $simpleResponse                     = TestableCheckoutController::initializeQPayCheckout(
        $customer,
        $gateway,
        null,
        $cart,
        (object) ['is_pickup' => true],
        Request::create('/checkout')
    );

    expect($simpleResponse->getData(true)['invoice']['invoice_id'])->toBe('invoice_checkout')
        ->and(CheckoutQPayStub::$invoiceKind)->toBe('simple')
        ->and(CheckoutQPayStub::$invoiceArguments[0])->toBe(2000);
});

test('stripe setup and payment update endpoints reject missing gateway configuration', function () {
    createCheckoutBoundarySchema();
    session(['storefront_store' => 'store_uuid']);
    $controller = new CheckoutController();

    $setup = $controller->createStripeSetupIntentForCustomer(
        CreateStripeSetupIntentRequest::create(
            '/checkout/stripe-setup',
            'POST',
            ['customer' => 'customer_missing']
        )
    );
    $update = $controller->updateStripePaymentIntent(Request::create(
        '/checkout/stripe-update',
        'PUT'
    ));

    Model::getConnectionResolver()->connection('mysql')->table('gateways')->insert([
        'uuid'       => 'stripe_gateway_uuid',
        'code'       => 'stripe',
        'owner_uuid' => 'store_uuid',
        'type'       => 'stripe',
        'config'     => json_encode(['secret_key' => '   ']),
    ]);
    $blankSetup = $controller->createStripeSetupIntentForCustomer(
        CreateStripeSetupIntentRequest::create(
            '/checkout/stripe-setup',
            'POST',
            ['customer' => 'customer_missing']
        )
    );

    expect($setup->getData(true))->toBe(['error' => 'Stripe not setup.'])
        ->and($update->getData(true))->toBe(['error' => 'No stripe gateway configured!'])
        ->and($blankSetup->getData(true))->toBe(['error' => 'Gateway not configured correctly!']);
});

test('stripe setup intent returns saved payment details and contains provider failures', function () {
    createCheckoutBoundarySchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('gateways')->insert([
        'uuid'       => 'stripe_gateway_uuid',
        'code'       => 'stripe',
        'owner_uuid' => 'store_uuid',
        'type'       => 'stripe',
        'config'     => json_encode(['secret_key' => 'sk_test_storefront']),
    ]);
    $connection->table('contacts')->insert([
        'uuid'         => 'customer_uuid',
        'public_id'    => 'contact_abcdefgh',
        'company_uuid' => 'company_uuid',
        'user_uuid'    => 'user_uuid',
        'type'         => 'customer',
        'name'         => 'Ada Buyer',
        'email'        => 'ada@example.test',
        'meta'         => json_encode([
            'stripe_id'                => 'cus_checkout',
            'stripe_payment_method_id' => 'pm_saved',
        ]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    session(['storefront_store' => 'store_uuid']);
    Stripe\ApiRequestor::setHttpClient(new class implements Stripe\HttpClient\ClientInterface {
        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            if (str_ends_with($absUrl, '/customers')) {
                return [json_encode([
                    'id'     => 'cus_created_for_setup',
                    'object' => 'customer',
                ]), 200, []];
            }

            if (str_contains($absUrl, '/payment_methods/')) {
                return [json_encode([
                    'id'       => 'pm_saved',
                    'object'   => 'payment_method',
                    'customer' => 'cus_checkout',
                    'type'     => 'card',
                    'card'     => [
                        'brand'     => 'visa',
                        'last4'     => '4242',
                        'exp_month' => 12,
                        'exp_year'  => 2030,
                        'country'   => 'US',
                        'funding'   => 'credit',
                    ],
                ]), 200, []];
            }

            return [json_encode([
                'id'            => 'seti_checkout',
                'object'        => 'setup_intent',
                'client_secret' => 'seti_secret',
                'customer'      => 'cus_checkout',
                'status'        => 'requires_payment_method',
            ]), 200, []];
        }
    });
    $controller = new CheckoutController();
    $success    = $controller->createStripeSetupIntentForCustomer(
        CreateStripeSetupIntentRequest::create(
            '/checkout/stripe-setup',
            'POST',
            ['customer' => 'customer_abcdefgh']
        )
    );
    $successData = $success->getData(true);
    expect($successData['setupIntent'])->toBe('seti_checkout')
        ->and($successData['clientSecret'])->toBe('seti_secret')
        ->and($successData['customerId'])->toBe('cus_checkout')
        ->and($successData['defaultPaymentMethod']['paymentMethodId'])->toBe('pm_saved')
        ->and($successData['defaultPaymentMethod']['brand'])->toBe('Visa')
        ->and($successData['defaultPaymentMethod']['last4'])->toBe('4242');
    $connection->table('contacts')->where('uuid', 'customer_uuid')->update(['meta' => '{}']);
    $createdCustomerSetup = $controller->createStripeSetupIntentForCustomer(
        CreateStripeSetupIntentRequest::create(
            '/checkout/stripe-setup',
            'POST',
            ['customer' => 'customer_abcdefgh']
        )
    );
    expect($createdCustomerSetup->getData(true)['customerId'])->toBe('cus_created_for_setup');

    $connection->table('contacts')->where('uuid', 'customer_uuid')->update([
        'meta' => json_encode([
            'stripe_id'                => 'cus_checkout',
            'stripe_payment_method_id' => 'pm_unavailable',
        ]),
    ]);
    Stripe\ApiRequestor::setHttpClient(new class implements Stripe\HttpClient\ClientInterface {
        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            if (str_contains($absUrl, '/payment_methods/')) {
                throw new RuntimeException('Saved payment method unavailable');
            }

            return [json_encode([
                'id'            => 'seti_without_saved_method',
                'object'        => 'setup_intent',
                'client_secret' => 'seti_without_saved_method_secret',
                'customer'      => 'cus_checkout',
                'status'        => 'requires_payment_method',
            ]), 200, []];
        }
    });
    $savedMethodFailure = $controller->createStripeSetupIntentForCustomer(
        CreateStripeSetupIntentRequest::create(
            '/checkout/stripe-setup',
            'POST',
            ['customer' => 'customer_abcdefgh']
        )
    );
    expect($savedMethodFailure->getData(true)['defaultPaymentMethod'])->toBeNull();

    Stripe\ApiRequestor::setHttpClient(new class implements Stripe\HttpClient\ClientInterface {
        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            if (str_contains($absUrl, '/payment_methods/')) {
                return [json_encode([
                    'id'       => 'pm_saved',
                    'object'   => 'payment_method',
                    'customer' => 'cus_checkout',
                    'type'     => 'card',
                ]), 200, []];
            }

            throw new RuntimeException('Stripe setup unavailable');
        }
    });
    $failure = $controller->createStripeSetupIntentForCustomer(
        CreateStripeSetupIntentRequest::create(
            '/checkout/stripe-setup',
            'POST',
            ['customer' => 'customer_abcdefgh']
        )
    );
    Stripe\ApiRequestor::setHttpClient(new Stripe\HttpClient\CurlClient());

    expect($failure->getData(true))->toBe(['error' => 'Stripe setup unavailable']);
});

test('stripe payment updates validate customer identity and provider credentials before network calls', function () {
    createCheckoutBoundarySchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('gateways')->insert([
        'uuid'       => 'stripe_gateway_uuid',
        'code'       => 'stripe',
        'owner_uuid' => 'store_uuid',
        'type'       => 'stripe',
        'config'     => json_encode(['secret_key' => '   ']),
    ]);
    $connection->table('carts')->insert([
        'uuid'              => 'cart_uuid',
        'public_id'         => 'cart_abcdefgh',
        'unique_identifier' => 'browser-cart',
        'currency'          => 'USD',
        'items'             => '[]',
        'events'            => '[]',
    ]);
    $connection->table('contacts')->insert([
        'uuid'      => 'customer_uuid',
        'public_id' => 'contact_abcdefgh',
        'type'      => 'customer',
    ]);
    session([
        'storefront_store'    => 'store_uuid',
        'storefront_network'  => null,
        'storefront_currency' => 'USD',
    ]);
    $controller = new CheckoutController();
    $baseInput  = [
        'cart'          => 'browser-cart',
        'paymentIntent' => 'pi_test',
        'pickup'        => true,
    ];

    $unknownCustomer = $controller->updateStripePaymentIntent(Request::create(
        '/checkout/stripe-update',
        'PUT',
        [...$baseInput, 'customer' => 'customer_missing']
    ));
    $incompleteGateway = $controller->updateStripePaymentIntent(Request::create(
        '/checkout/stripe-update',
        'PUT',
        [...$baseInput, 'customer' => 'customer_abcdefgh']
    ));

    expect($unknownCustomer->getData(true))->toBe(['error' => 'Invalid customer ID provided'])
        ->and($incompleteGateway->getData(true))->toBe(['error' => 'Gateway not configured correctly!']);
});

test('stripe payment updates enforce modifiable states and persist refreshed checkout details', function () {
    createCheckoutBoundarySchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('gateways')->insert([
        'uuid'       => 'stripe_gateway_uuid',
        'code'       => 'stripe',
        'owner_uuid' => 'store_uuid',
        'type'       => 'stripe',
        'config'     => json_encode(['secret_key' => 'sk_test_storefront']),
    ]);
    $connection->table('contacts')->insert([
        'uuid'         => 'customer_uuid',
        'public_id'    => 'contact_abcdefgh',
        'company_uuid' => 'company_uuid',
        'user_uuid'    => 'user_uuid',
        'type'         => 'customer',
        'meta'         => json_encode(['stripe_id' => 'cus_checkout']),
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
    $connection->table('carts')->insert([
        'uuid'              => 'cart_uuid',
        'public_id'         => 'cart_abcdefgh',
        'company_uuid'      => 'company_uuid',
        'unique_identifier' => 'browser-cart',
        'currency'          => 'USD',
        'items'             => json_encode([
            ['id' => 'line_one', 'quantity' => 1, 'subtotal' => 1500],
        ]),
        'events' => '[]',
    ]);
    session([
        'company'             => 'company_uuid',
        'storefront_store'    => 'store_uuid',
        'storefront_network'  => null,
        'storefront_currency' => 'USD',
    ]);
    Stripe\ApiRequestor::setHttpClient(new class implements Stripe\HttpClient\ClientInterface {
        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            if (str_ends_with($absUrl, '/customers')) {
                return [json_encode([
                    'id'     => 'cus_checkout',
                    'object' => 'customer',
                ]), 200, []];
            }

            return [json_encode([
                'id'            => 'pi_checkout',
                'object'        => 'payment_intent',
                'client_secret' => 'pi_secret',
                'customer'      => 'cus_checkout',
                'status'        => 'succeeded',
            ]), 200, []];
        }
    });
    $controller = new CheckoutController();
    $input      = [
        'customer'      => 'customer_abcdefgh',
        'cart'          => 'browser-cart',
        'paymentIntent' => 'pi_checkout',
        'pickup'        => true,
        'tip'           => '10%',
    ];
    $connection->table('contacts')->where('uuid', 'customer_uuid')->update(['meta' => '{}']);
    $immutable = $controller->updateStripePaymentIntent(Request::create(
        '/checkout/stripe-update',
        'PUT',
        $input
    ));

    expect($immutable->getData(true))->toBe(['error' => 'PaymentIntent cannot be updated at this stage.']);

    Stripe\ApiRequestor::setHttpClient(new class implements Stripe\HttpClient\ClientInterface {
        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            if (str_contains($absUrl, '/ephemeral_keys')) {
                return [json_encode([
                    'id'     => 'ephkey_checkout',
                    'object' => 'ephemeral_key',
                    'secret' => 'eph_secret',
                ]), 200, []];
            }

            if (strtolower($method) === 'post') {
                return [json_encode([
                    'id'             => 'pi_checkout',
                    'object'         => 'payment_intent',
                    'client_secret'  => 'pi_updated_secret',
                    'customer'       => 'cus_checkout',
                    'payment_method' => 'pm_new',
                    'status'         => 'requires_confirmation',
                ]), 200, []];
            }

            return [json_encode([
                'id'            => 'pi_checkout',
                'object'        => 'payment_intent',
                'client_secret' => 'pi_secret',
                'customer'      => 'cus_checkout',
                'status'        => 'requires_payment_method',
            ]), 200, []];
        }
    });
    $updated = $controller->updateStripePaymentIntent(Request::create(
        '/checkout/stripe-update',
        'PUT',
        $input
    ));
    Stripe\ApiRequestor::setHttpClient(new Stripe\HttpClient\CurlClient());
    $checkout    = Checkout::query()->first();
    $meta        = json_decode($connection->table('contacts')->where('uuid', 'customer_uuid')->value('meta'), true);
    $updatedData = $updated->getData(true);

    expect($updatedData['paymentIntent'])->toBe('pi_checkout')
        ->and($updatedData['clientSecret'])->toBe('pi_updated_secret')
        ->and($updatedData['ephemeralKey'])->toBe('eph_secret')
        ->and($updatedData['customerId'])->toBe('cus_checkout')
        ->and($updatedData['token'])->toBe($checkout->token)
        ->and($updatedData['checkout'])->toBe($checkout->public_id)
        ->and($checkout->amount)->toBe(1650)
        ->and($checkout->is_pickup)->toBeTrue()
        ->and($meta['stripe_payment_method_id'])->toBe('pm_new');
});

test('stripe payment updates contain retrieve update and ephemeral-key provider failures', function () {
    createCheckoutBoundarySchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('gateways')->insert([
        'uuid'       => 'stripe_gateway_uuid',
        'code'       => 'stripe',
        'owner_uuid' => 'store_uuid',
        'type'       => 'stripe',
        'config'     => json_encode(['secret_key' => 'sk_test_storefront']),
    ]);
    $connection->table('contacts')->insert([
        'uuid'         => 'customer_uuid',
        'public_id'    => 'contact_abcdefgh',
        'type'         => 'customer',
        'meta'         => json_encode(['stripe_id' => 'cus_checkout']),
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
    $connection->table('carts')->insert([
        'uuid'              => 'cart_uuid',
        'public_id'         => 'cart_abcdefgh',
        'unique_identifier' => 'browser-cart',
        'currency'          => 'USD',
        'items'             => '[]',
        'events'            => '[]',
    ]);
    session([
        'storefront_store'    => 'store_uuid',
        'storefront_network'  => null,
        'storefront_currency' => 'USD',
    ]);
    $client = new class implements Stripe\HttpClient\ClientInterface {
        public string $mode = 'retrieve';

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            if ($this->mode === 'retrieve') {
                throw new RuntimeException('retrieve unavailable');
            }

            if (str_contains($absUrl, '/ephemeral_keys')) {
                if ($this->mode === 'ephemeral') {
                    throw new RuntimeException('ephemeral unavailable');
                }

                return [json_encode([
                    'id' => 'ephkey_checkout', 'object' => 'ephemeral_key', 'secret' => 'eph_secret',
                ]), 200, []];
            }

            if (strtolower($method) === 'post') {
                if ($this->mode === 'update') {
                    throw new RuntimeException('update unavailable');
                }

                return [json_encode([
                    'id'             => 'pi_checkout',
                    'object'         => 'payment_intent',
                    'client_secret'  => 'pi_secret',
                    'payment_method' => null,
                    'status'         => 'requires_confirmation',
                ]), 200, []];
            }

            return [json_encode([
                'id'            => 'pi_checkout',
                'object'        => 'payment_intent',
                'client_secret' => 'pi_secret',
                'status'        => 'requires_payment_method',
            ]), 200, []];
        }
    };
    Stripe\ApiRequestor::setHttpClient($client);
    $controller = new CheckoutController();
    $request    = fn () => Request::create('/checkout/stripe-update', 'PUT', [
        'customer'      => 'customer_abcdefgh',
        'cart'          => 'browser-cart',
        'paymentIntent' => 'pi_checkout',
        'pickup'        => true,
    ]);

    $retrieveFailure  = $controller->updateStripePaymentIntent($request());
    $client->mode     = 'update';
    $updateFailure    = $controller->updateStripePaymentIntent($request());
    $client->mode     = 'ephemeral';
    $ephemeralFailure = $controller->updateStripePaymentIntent($request());
    Stripe\ApiRequestor::setHttpClient(new Stripe\HttpClient\CurlClient());

    expect($retrieveFailure->getData(true))->toBe([
        'error' => 'Failed to retrieve PaymentIntent: retrieve unavailable',
    ])->and($updateFailure->getData(true))->toBe([
        'error' => 'Failed to update PaymentIntent: update unavailable',
    ])->and($ephemeralFailure->getData(true))->toBe([
        'error' => 'Failed to create ephemeral key: ephemeral unavailable',
    ]);
});

test('stripe authentication failures return a stable non secret gateway error for every checkout operation', function () {
    createCheckoutBoundarySchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('gateways')->insert([
        'uuid'       => 'stripe_gateway_uuid',
        'code'       => 'stripe',
        'owner_uuid' => 'store_uuid',
        'type'       => 'stripe',
        'config'     => json_encode(['secret_key' => 'sk_test_storefront']),
    ]);
    $connection->table('contacts')->insert([
        'uuid'         => 'customer_uuid',
        'public_id'    => 'contact_abcdefgh',
        'company_uuid' => 'company_uuid',
        'type'         => 'customer',
        'name'         => 'Ada Buyer',
        'email'        => 'ada@example.test',
        'phone'        => '+97699112233',
        'meta'         => '{}',
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
    $connection->table('carts')->insert([
        'uuid'              => 'cart_uuid',
        'public_id'         => 'cart_abcdefgh',
        'unique_identifier' => 'browser-cart',
        'currency'          => 'USD',
        'items'             => '[]',
        'events'            => '[]',
    ]);
    session([
        'company'             => 'company_uuid',
        'storefront_store'    => 'store_uuid',
        'storefront_network'  => null,
        'storefront_currency' => 'USD',
    ]);
    $client = new class implements Stripe\HttpClient\ClientInterface {
        public string $mode        = 'create_customer';
        public int $ephemeralCalls = 0;

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            $isCustomer       = str_ends_with($absUrl, '/customers');
            $isEphemeral      = str_contains($absUrl, '/ephemeral_keys');
            $isSetupIntent    = str_contains($absUrl, '/setup_intents');
            $isPaymentIntent  = str_contains($absUrl, '/payment_intents/');
            $isPaymentCreate  = str_ends_with($absUrl, '/payment_intents');
            $isPost           = strtolower($method) === 'post';

            if ($isEphemeral) {
                $this->ephemeralCalls++;
            }

            if (
                ($this->mode === 'create_customer' && $isCustomer)
                || ($this->mode === 'ephemeral' && $isEphemeral)
                || ($this->mode === 'payment_create' && $isPaymentCreate)
                || ($this->mode === 'recreate_customer' && $isCustomer)
                || ($this->mode === 'setup_customer' && $isCustomer)
                || ($this->mode === 'setup_intent' && $isSetupIntent)
                || ($this->mode === 'update_customer' && $isCustomer)
                || ($this->mode === 'payment_retrieve' && $isPaymentIntent && !$isPost)
                || ($this->mode === 'payment_update' && $isPaymentIntent && $isPost)
                || ($this->mode === 'update_ephemeral' && $isEphemeral)
            ) {
                throw new Stripe\Exception\AuthenticationException('sk_secret_should_never_leak');
            }

            if ($this->mode === 'recreate_customer' && $isEphemeral && $this->ephemeralCalls === 1) {
                return [json_encode([
                    'error' => ['message' => 'No such customer: cus_stale', 'type' => 'invalid_request_error'],
                ]), 400, []];
            }

            if ($isCustomer) {
                return [json_encode(['id' => 'cus_checkout', 'object' => 'customer']), 200, []];
            }
            if ($isEphemeral) {
                return [json_encode([
                    'id' => 'ephkey_checkout', 'object' => 'ephemeral_key', 'secret' => 'eph_secret',
                ]), 200, []];
            }
            if ($isSetupIntent) {
                return [json_encode([
                    'id' => 'seti_checkout', 'object' => 'setup_intent', 'client_secret' => 'seti_secret',
                ]), 200, []];
            }

            return [json_encode([
                'id'             => 'pi_checkout',
                'object'         => 'payment_intent',
                'client_secret'  => 'pi_secret',
                'payment_method' => null,
                'status'         => 'requires_payment_method',
            ]), 200, []];
        }
    };
    Stripe\ApiRequestor::setHttpClient($client);
    $gateway    = Gateway::query()->firstOrFail();
    $cart       = Cart::where('unique_identifier', 'browser-cart')->firstOrFail();
    $options    = (object) ['is_pickup' => true];
    $initialize = function (string $mode, array $meta) use ($client, $connection, $gateway, $cart, $options) {
        $connection->table('contacts')->where('uuid', 'customer_uuid')->update(['meta' => json_encode($meta)]);
        $client->mode           = $mode;
        $client->ephemeralCalls = 0;

        return CheckoutController::initializeStripeCheckout(
            Fleetbase\Storefront\Models\Customer::where('uuid', 'customer_uuid')->firstOrFail(),
            $gateway,
            null,
            $cart,
            $options,
            Request::create('/checkout')
        );
    };
    $controller = new CheckoutController();
    $setup      = function (string $mode, array $meta) use ($client, $connection, $controller) {
        $connection->table('contacts')->where('uuid', 'customer_uuid')->update(['meta' => json_encode($meta)]);
        $client->mode = $mode;

        return $controller->createStripeSetupIntentForCustomer(
            CreateStripeSetupIntentRequest::create(
                '/checkout/stripe-setup',
                'POST',
                ['customer' => 'customer_abcdefgh']
            )
        );
    };
    $update = function (string $mode, array $meta) use ($client, $connection, $controller) {
        $connection->table('contacts')->where('uuid', 'customer_uuid')->update(['meta' => json_encode($meta)]);
        $client->mode = $mode;

        return $controller->updateStripePaymentIntent(Request::create('/checkout/stripe-update', 'PUT', [
            'customer'      => 'customer_abcdefgh',
            'cart'          => 'browser-cart',
            'paymentIntent' => 'pi_checkout',
            'pickup'        => true,
        ]));
    };

    $responses = [
        $initialize('create_customer', []),
        $initialize('ephemeral', ['stripe_id' => 'cus_checkout']),
        $initialize('payment_create', ['stripe_id' => 'cus_checkout']),
        $initialize('recreate_customer', ['stripe_id' => 'cus_stale']),
        $setup('setup_customer', []),
        $setup('setup_intent', ['stripe_id' => 'cus_checkout']),
        $update('update_customer', []),
        $update('payment_retrieve', ['stripe_id' => 'cus_checkout']),
        $update('payment_update', ['stripe_id' => 'cus_checkout']),
        $update('update_ephemeral', ['stripe_id' => 'cus_checkout']),
    ];
    Stripe\ApiRequestor::setHttpClient(new Stripe\HttpClient\CurlClient());

    foreach ($responses as $response) {
        expect($response->getData(true))->toBe([
            'error' => 'Stripe gateway authentication failed. Verify the configured secret key.',
        ])->and(json_encode($response->getData(true)))->not->toContain('sk_secret_should_never_leak');
    }
});

test('qpay callback reports missing checkout identifiers sessions and gateways', function () {
    createCheckoutBoundarySchema();
    $controller = new CheckoutController();

    $missingId = $controller->captureQPayCallback(Request::create('/checkout/qpay', 'POST'));
    $unknown   = $controller->captureQPayCallback(Request::create('/checkout/qpay', 'POST', [
        'checkout' => 'checkout_missing',
    ]));
    Model::getConnectionResolver()->connection('mysql')->table('checkouts')->insert([
        'public_id'   => 'checkout_public',
        'gateway_uuid'=> 'gateway_missing',
    ]);
    $missingGateway = $controller->captureQPayCallback(Request::create('/checkout/qpay', 'POST', [
        'checkout' => 'checkout_public',
    ]));

    expect($missingId->getData(true))->toBe([
        'error'    => 'CHECKOUT_ID_MISSING',
        'checkout' => null,
        'payment'  => null,
    ])->and($unknown->getData(true))->toBe([
        'error'    => 'CHECKOUT_SESSION_NOT_FOUND',
        'checkout' => null,
        'payment'  => null,
    ])->and($missingGateway->getData(true))->toBe([
        'error'    => 'GATEWAY_NOT_CONFIGURED',
        'checkout' => 'checkout_public',
        'payment'  => null,
    ]);
});

test('qpay callback handles invoice payment sandbox and provider failure states deterministically', function () {
    createCheckoutBoundarySchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('gateways')->insert([
        'uuid'         => 'qpay_gateway_uuid',
        'code'         => 'qpay',
        'owner_uuid'   => 'store_uuid',
        'type'         => 'qpay',
        'sandbox'      => true,
        'callback_url' => 'https://storefront.test/qpay',
        'config'       => json_encode([
            'username' => 'merchant',
            'password' => 'secret',
        ]),
    ]);
    $connection->table('orders')->insert([
        'uuid'      => 'order_uuid',
        'public_id' => 'order_abcdefgh',
    ]);
    $connection->table('checkouts')->insert([
        'uuid'         => 'checkout_uuid',
        'public_id'    => 'checkout_abcdefgh',
        'gateway_uuid' => 'qpay_gateway_uuid',
        'order_uuid'   => 'order_uuid',
        'amount'       => 2500,
        'currency'     => 'MNT',
        'options'      => '{}',
        'token'        => 'checkout-token',
        'captured'     => true,
    ]);
    CheckoutQPayStub::$paymentCheckResult                            = null;
    CheckoutQPayStub::$failure                                       = null;
    CheckoutQPayStub::$sandboxUsed                                   = false;
    CheckoutQPayStub::$authenticated                                 = false;
    Fleetbase\Support\SocketCluster\SocketClusterService::$published = [];
    $controller                                                      = new TestableCheckoutController();

    $missingInvoice = $controller->captureQPayCallback(Request::create('/checkout/qpay', 'POST', [
        'checkout' => 'checkout_abcdefgh',
        'respond'  => true,
    ]));
    $checkout = Checkout::where('uuid', 'checkout_uuid')->firstOrFail();
    $checkout->updateOption('qpay_invoice_id', 'invoice_checkout');
    CheckoutQPayStub::$paymentCheckResult = (object) ['count' => 0, 'rows' => []];
    $notFound                             = $controller->captureQPayCallback(Request::create('/checkout/qpay', 'POST', [
        'checkout' => 'checkout_abcdefgh',
        'respond'  => true,
    ]));
    CheckoutQPayStub::$paymentCheckResult = (object) [
        'count' => 1,
        'rows'  => [
            (object) [
                'payment_id'     => 'payment_checkout',
                'payment_status' => 'PAID',
                'payment_amount' => 2500,
                'payment_wallet' => 'QPay',
            ],
        ],
    ];
    $paid = $controller->captureQPayCallback(Request::create('/checkout/qpay', 'POST', [
        'checkout' => 'checkout_abcdefgh',
        'respond'  => true,
    ]));
    CheckoutQPayStub::$failure = new RuntimeException('QPay unavailable');
    $providerFailure           = $controller->captureQPayCallback(Request::create('/checkout/qpay', 'POST', [
        'checkout' => 'checkout_abcdefgh',
        'respond'  => true,
    ]));
    $silentProviderFailure = $controller->captureQPayCallback(Request::create('/checkout/qpay', 'POST', [
        'checkout' => 'checkout_abcdefgh',
    ]));
    CheckoutQPayStub::$failure = null;
    $sandboxSuccess            = $controller->captureQPayCallback(Request::create('/checkout/qpay', 'POST', [
        'checkout' => 'checkout_abcdefgh',
        'respond'  => true,
        'test'     => 'success',
    ]));
    $sandboxError = $controller->captureQPayCallback(Request::create('/checkout/qpay', 'POST', [
        'checkout' => 'checkout_abcdefgh',
        'respond'  => true,
        'test'     => 'error',
    ]));

    expect($missingInvoice->getData(true))->toBe([
        'error'    => 'MISSING_INVOICE_ID',
        'checkout' => 'checkout_abcdefgh',
        'payment'  => null,
    ])->and($notFound->getData(true))->toBe([
        'error'    => 'PAYMENT_NOTFOUND',
        'checkout' => 'checkout_abcdefgh',
        'payment'  => null,
    ])->and($paid->getData(true)['payment']['payment_id'])->toBe('payment_checkout')
        ->and($providerFailure->getData(true))->toBe(['error' => 'QPay unavailable'])
        ->and($silentProviderFailure->getData(true))->toBe([])
        ->and($sandboxSuccess->getData(true)['payment'])->not->toBeNull()
        ->and($sandboxError->getData(true)['error']['error'])->toBe('PAYMENT_NOT_PAID')
        ->and(CheckoutQPayStub::$sandboxUsed)->toBeTrue()
        ->and(CheckoutQPayStub::$authenticated)->toBeTrue()
        ->and(Fleetbase\Support\SocketCluster\SocketClusterService::$published)->toHaveCount(3);
});

test('single and multiple order capture reject invalid checkout tokens safely', function () {
    createCheckoutBoundarySchema();
    $controller = new CheckoutController();
    $request    = CaptureOrderRequest::create('/checkout/capture', 'POST', [
        'token'              => 'checkout_invalid',
        'transactionDetails' => 'not-an-array',
    ]);

    $single   = $controller->captureOrder($request);
    $multiple = $controller->captureMultipleOrders($request);

    expect($single->getData(true))->toBe(['error' => 'Checkout session not found.'])
        ->and($multiple->getData(true))->toBe(['error' => 'Checkout session not found.']);
});

test('single and multiple captures return integrated-vendor provider failures before local order persistence', function () {
    $run = function (bool $multiple): array {
        createCheckoutBoundarySchema();
        $connection = Model::getConnectionResolver()->connection('mysql');
        $connection->table('integrated_vendors')->insert([
            'uuid'      => 'integrated_vendor_uuid',
            'public_id' => 'integrated_vendor_abcdefgh',
        ]);
        $connection->table('contacts')->insert([
            'uuid'      => 'customer_uuid',
            'public_id' => 'contact_abcdefgh',
            'type'      => 'customer',
        ]);
        $connection->table('service_quotes')->insert([
            'uuid'                   => 'quote_uuid',
            'public_id'              => 'quote_abcdefgh',
            'integrated_vendor_uuid' => 'integrated_vendor_uuid',
            'amount'                 => 300,
            'currency'               => 'USD',
            'meta'                   => json_encode([
                'origin'      => $multiple ? ['place_one', 'place_two'] : ['place_one'],
                'destination' => 'place_destination',
            ]),
        ]);
        $connection->table('carts')->insert([
            'uuid'              => 'cart_uuid',
            'public_id'         => 'cart_abcdefgh',
            'unique_identifier' => 'vendor-cart',
            'currency'          => 'USD',
            'items'             => json_encode($multiple ? [
                ['store_id' => 'store_one', 'subtotal' => 100],
                ['store_id' => 'store_two', 'subtotal' => 200],
            ] : []),
            'events' => '[]',
        ]);
        if ($multiple) {
            $connection->table('networks')->insert([
                'uuid'      => 'network_uuid',
                'public_id' => 'network_abcdefgh',
                'key'       => 'network_key',
                'name'      => 'Vendor network',
                'currency'  => 'USD',
                'options'   => '{}',
            ]);
            session([
                'storefront_key'     => 'network_key',
                'storefront_store'   => null,
                'storefront_network' => 'network_uuid',
            ]);
        } else {
            $connection->table('stores')->insert([
                'uuid'      => 'store_uuid',
                'public_id' => 'store_abcdefgh',
                'key'       => 'store_key',
                'name'      => 'Vendor store',
                'currency'  => 'USD',
                'options'   => '{}',
            ]);
            session([
                'storefront_key'     => 'store_key',
                'storefront_store'   => 'store_uuid',
                'storefront_network' => null,
            ]);
        }
        $connection->table('checkouts')->insert([
            'uuid'               => 'checkout_uuid',
            'public_id'          => 'checkout_abcdefgh',
            'network_uuid'       => $multiple ? 'network_uuid' : null,
            'store_uuid'         => $multiple ? null : 'store_uuid',
            'cart_uuid'          => 'cart_uuid',
            'service_quote_uuid' => 'quote_uuid',
            'owner_uuid'         => 'customer_uuid',
            'owner_type'         => Contact::class,
            'currency'           => 'USD',
            'is_cod'             => true,
            'options'            => '{}',
            'token'              => 'vendor-checkout-token',
        ]);
        CheckoutIntegratedVendorStub::$vendorFailure = new RuntimeException('Integrated vendor rejected order');
        $controller                                  = new CheckoutIntegratedVendorStub();
        $request                                     = CaptureOrderRequest::create('/checkout/capture', 'POST', [
            'token' => 'vendor-checkout-token',
        ]);
        $response = $multiple
            ? $controller->captureMultipleOrders($request)
            : $controller->captureOrder($request);
        CheckoutIntegratedVendorStub::$vendorFailure = null;

        return $response->getData(true);
    };

    expect($run(false))->toBe(['error' => 'Integrated vendor rejected order'])
        ->and($run(true))->toBe(['error' => 'Integrated vendor rejected order']);
});

test('single order capture persists the cash transaction payload order and checkout contract', function () {
    createCheckoutCaptureExecutionSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('networks')->insert([
        'uuid'              => 'network_uuid',
        'public_id'         => 'network_abcdefgh',
        'company_uuid'      => 'company_uuid',
        'order_config_uuid' => 'network_order_config_uuid',
        'key'               => 'network_key',
        'name'              => 'Checkout network',
        'currency'          => 'USD',
        'options'           => '{}',
        'alertable'         => '{}',
    ]);
    $connection->table('stores')->insert([
        'uuid'              => 'store_uuid',
        'public_id'         => 'store_abcdefgh',
        'company_uuid'      => 'company_uuid',
        'order_config_uuid' => 'order_config_uuid',
        'key'               => 'store_key',
        'name'              => 'Test store',
        'currency'          => 'USD',
        'options'           => json_encode([
            'auto_accept_orders' => true,
            'auto_dispatch'      => true,
        ]),
        'alertable'         => '{}',
    ]);
    $connection->table('contacts')->insert([
        'uuid'         => 'customer_uuid',
        'public_id'    => 'contact_abcdefgh',
        'company_uuid' => 'company_uuid',
        'type'         => 'customer',
        'name'         => 'Checkout customer',
    ]);
    $connection->table('integrated_vendors')->insert([
        'uuid'      => 'integrated_vendor_uuid',
        'public_id' => 'integrated_vendor_abcdefgh',
        'created_at'=> now(),
        'updated_at'=> now(),
    ]);
    $connection->table('products')->insert([
        'uuid'        => 'product_uuid',
        'public_id'   => 'product_coffee',
        'name'        => 'Coffee',
        'description' => 'Fresh coffee',
        'currency'    => 'USD',
        'sku'         => 'COFFEE-1',
        'price'       => 1000,
    ]);
    $connection->table('carts')->insert([
        'uuid'              => 'cart_uuid',
        'public_id'         => 'cart_abcdefgh',
        'unique_identifier' => 'browser-cart',
        'currency'          => 'USD',
        'items'             => json_encode([
            [
                'product_id' => 'product_coffee',
                'store_id'   => 'store_abcdefgh',
                'name'       => 'Coffee',
                'variants'   => [],
                'addons'     => [],
                'quantity'   => 1,
                'price'      => 1000,
                'subtotal'   => 1000,
            ],
        ]),
        'events'            => '[]',
    ]);
    $connection->table('places')->insert([
        [
            'uuid'         => 'origin_uuid',
            'public_id'    => 'place_abcdefgh',
            'company_uuid' => 'company_uuid',
            'name'         => 'Store pickup',
        ],
        [
            'uuid'         => 'destination_uuid',
            'public_id'    => 'place_ijklmnop',
            'company_uuid' => 'company_uuid',
            'name'         => 'Customer destination',
        ],
    ]);
    $connection->table('store_locations')->insert([
        'uuid'       => 'store_location_uuid',
        'public_id'  => 'store_location_abcdefgh',
        'store_uuid' => 'store_uuid',
        'place_uuid' => 'origin_uuid',
        'name'       => 'Main location',
    ]);
    $connection->table('service_quotes')->insert([
        'uuid'                   => 'quote_uuid',
        'public_id'              => 'quote_abcdefgh',
        'amount'                 => 300,
        'currency'               => 'USD',
        'integrated_vendor_uuid' => 'integrated_vendor_uuid',
        'meta'                   => json_encode([
            'origin'      => ['place_abcdefgh'],
            'destination' => 'place_ijklmnop',
        ]),
    ]);
    $connection->table('checkouts')->insert([
        'uuid'               => 'checkout_uuid',
        'public_id'          => 'checkout_abcdefgh',
        'company_uuid'       => 'company_uuid',
        'store_uuid'         => 'store_uuid',
        'network_uuid'       => 'network_uuid',
        'cart_uuid'          => 'cart_uuid',
        'service_quote_uuid' => 'quote_uuid',
        'owner_uuid'         => 'customer_uuid',
        'owner_type'         => Contact::class,
        'amount'             => 375,
        'currency'           => 'USD',
        'is_cod'             => true,
        'is_pickup'          => false,
        'options'            => json_encode(['is_pickup' => false, 'tip' => 25, 'delivery_tip' => 50]),
        'token'              => 'checkout-token',
        'captured'           => false,
    ]);
    session([
        'storefront_key'     => 'network_key',
        'storefront_store'   => null,
        'storefront_network' => 'network_uuid',
        'company'            => 'company_uuid',
    ]);

    $checkoutModel                           = Checkout::where('uuid', 'checkout_uuid')->firstOrFail();
    $captureMethod                           = new ReflectionMethod(CheckoutController::class, 'createOrderFromCheckout');
    CheckoutOrderAutomationStub::$accepted   = 0;
    CheckoutOrderAutomationStub::$dispatched = 0;
    $resource                                = $captureMethod->invoke(
        new CheckoutIntegratedVendorStub(),
        $checkoutModel,
        ['transaction_id' => 'cash_receipt_123'],
        'Leave at reception'
    );
    Model::unsetEventDispatcher();

    $transaction = $connection->table('transactions')->where('gateway_transaction_id', 'cash_receipt_123')->first();
    $payload     = $connection->table('payloads')->first();
    $order       = $connection->table('orders')->first();
    $checkout    = $connection->table('checkouts')->where('uuid', 'checkout_uuid')->first();
    $cart        = $connection->table('carts')->where('uuid', 'cart_uuid')->first();
    expect($resource)->toBeInstanceOf(Fleetbase\FleetOps\Models\Order::class)
        ->and($transaction->gateway_transaction_id)->toBe('cash_receipt_123')
        ->and($transaction->gateway)->toBe('cash')
        ->and($transaction->status)->toBe('voided')
        ->and($payload->payment_method)->toBe('cash')
        ->and($payload->cod_amount)->toBe(1375)
        ->and($payload->cod_currency)->toBe('USD')
        ->and($order->payload_uuid)->toBe($payload->uuid)
        ->and($order->notes)->toBe('Leave at reception')
        ->and($checkout->order_uuid)->toBe($order->uuid)
        ->and((bool) $checkout->captured)->toBeTrue()
        ->and($cart->checkout_uuid)->toBe('checkout_uuid')
        ->and(CheckoutOrderAutomationStub::$accepted)->toBe(1)
        ->and(CheckoutOrderAutomationStub::$dispatched)->toBe(1);
    expect($connection->table('transaction_items')->orderBy('id')->pluck('code')->all())
        ->toBe(['product', 'delivery_fee', 'tip', 'delivery_tip']);
});

test('multiple order capture is idempotent after a master order has been created', function () {
    createCheckoutBoundarySchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('orders')->insert([
        'uuid'      => 'master_order_uuid',
        'public_id' => 'order_master',
    ]);
    $connection->table('carts')->insert([
        'uuid'              => 'cart_uuid',
        'public_id'         => 'cart_abcdefgh',
        'unique_identifier' => 'browser-cart',
        'currency'          => 'USD',
        'items'             => json_encode([
            ['id' => 'line_one', 'store_id' => 'store_one', 'quantity' => 1, 'subtotal' => 1000],
            ['id' => 'line_two', 'store_id' => 'store_two', 'quantity' => 1, 'subtotal' => 500],
        ]),
        'events' => '[]',
    ]);
    $connection->table('service_quotes')->insert([
        'uuid'      => 'quote_uuid',
        'public_id' => 'quote_abcdefgh',
        'amount'    => 300,
        'meta'      => json_encode([
            'origin'      => ['place_origin', 'place_waypoint'],
            'destination' => 'place_destination',
        ]),
    ]);
    $connection->table('checkouts')->insert([
        'uuid'               => 'checkout_uuid',
        'public_id'          => 'checkout_abcdefgh',
        'token'              => 'checkout-token',
        'cart_uuid'          => 'cart_uuid',
        'service_quote_uuid' => 'quote_uuid',
        'order_uuid'         => 'master_order_uuid',
        'currency'           => 'USD',
        'amount'             => 1500,
        'is_cod'             => true,
        'is_pickup'          => true,
        'options'            => json_encode(['is_pickup' => true]),
        'captured'           => true,
    ]);
    session(['storefront_key' => null]);

    $resource = (new CheckoutController())->captureMultipleOrders(
        CaptureOrderRequest::create('/checkout/capture-multiple', 'POST', [
            'token'              => 'checkout-token',
            'transactionDetails' => 'malformed-details',
        ])
    );
    $connection->table('checkouts')->where('uuid', 'checkout_uuid')->update([
        'order_uuid' => null,
        'captured'   => false,
    ]);
    $missingNetwork = (new CheckoutController())->captureMultipleOrders(
        CaptureOrderRequest::create('/checkout/capture-multiple', 'POST', [
            'token' => 'checkout-token',
        ])
    );

    expect($resource)->toBeInstanceOf(Fleetbase\FleetOps\Http\Resources\v1\Order::class)
        ->and($resource->resource->uuid)->toBe('master_order_uuid')
        ->and($connection->table('orders')->count())->toBe(1)
        ->and($missingNetwork->getData(true))->toBe([
            'error' => 'No network in request to capture order!',
        ]);
});

test('multiple order capture creates child and master logistics orders for a network checkout', function () {
    createCheckoutCaptureExecutionSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('companies')->insert([
        'uuid' => 'company_uuid',
        'name' => 'Delivery Company',
    ]);
    $connection->table('networks')->insert([
        'uuid'              => 'network_uuid',
        'public_id'         => 'network_abcdefgh',
        'company_uuid'      => 'company_uuid',
        'order_config_uuid' => 'network_order_config_uuid',
        'key'               => 'network_key',
        'name'              => 'Delivery network',
        'currency'          => 'USD',
        'options'           => '{}',
        'alertable'         => '{}',
    ]);
    $connection->table('stores')->insert([
        [
            'uuid'              => 'store_uuid',
            'public_id'         => 'store_abcdefgh',
            'company_uuid'      => 'store_company_uuid',
            'order_config_uuid' => 'store_order_config_uuid',
            'key'               => 'store_key',
            'name'              => 'Network store',
            'currency'          => 'USD',
            'options'           => json_encode([
                'auto_accept_orders' => true,
                'auto_dispatch'      => true,
            ]),
            'alertable'         => '{}',
        ],
        [
            'uuid'              => 'store_two_uuid',
            'public_id'         => 'store_ijklmnop',
            'company_uuid'      => 'store_two_company_uuid',
            'order_config_uuid' => 'store_two_order_config_uuid',
            'key'               => 'store_two_key',
            'name'              => 'Second network store',
            'currency'          => 'USD',
            'options'           => json_encode([
                'auto_accept_orders' => true,
                'auto_dispatch'      => true,
            ]),
            'alertable'         => '{}',
        ],
    ]);
    $connection->table('contacts')->insert([
        'uuid'         => 'customer_uuid',
        'public_id'    => 'contact_abcdefgh',
        'company_uuid' => 'company_uuid',
        'type'         => 'customer',
        'name'         => 'Checkout customer',
    ]);
    $connection->table('integrated_vendors')->insert([
        'uuid'      => 'integrated_vendor_uuid',
        'public_id' => 'integrated_vendor_abcdefgh',
        'created_at'=> now(),
        'updated_at'=> now(),
    ]);
    $connection->table('products')->insert([
        'uuid'        => 'product_uuid',
        'public_id'   => 'product_coffee',
        'name'        => 'Coffee',
        'description' => 'Fresh coffee',
        'currency'    => 'USD',
        'sku'         => 'COFFEE-1',
        'price'       => 1000,
    ]);
    $connection->table('places')->insert([
        [
            'uuid'         => 'origin_uuid',
            'public_id'    => 'place_abcdefgh',
            'company_uuid' => 'company_uuid',
            'name'         => 'Store pickup',
        ],
        [
            'uuid'         => 'destination_uuid',
            'public_id'    => 'place_ijklmnop',
            'company_uuid' => 'company_uuid',
            'name'         => 'Customer dropoff',
        ],
        [
            'uuid'         => 'origin_two_uuid',
            'public_id'    => 'place_qrstuvwx',
            'company_uuid' => 'company_uuid',
            'name'         => 'Second store pickup',
        ],
    ]);
    $connection->table('store_locations')->insert([
        [
            'uuid'       => 'store_location_uuid',
            'public_id'  => 'store_location_abcdefgh',
            'store_uuid' => 'store_uuid',
            'place_uuid' => 'origin_uuid',
            'name'       => 'Main location',
        ],
        [
            'uuid'       => 'store_location_two_uuid',
            'public_id'  => 'store_location_ijklmnop',
            'store_uuid' => 'store_two_uuid',
            'place_uuid' => 'origin_two_uuid',
            'name'       => 'Second location',
        ],
    ]);
    $connection->table('carts')->insert([
        'uuid'              => 'cart_uuid',
        'public_id'         => 'cart_abcdefgh',
        'unique_identifier' => 'browser-cart',
        'currency'          => 'USD',
        'items'             => json_encode([
            [
                'product_id'       => 'product_coffee',
                'store_id'         => 'store_abcdefgh',
                'store_location_id'=> 'store_location_abcdefgh',
                'name'             => 'Coffee',
                'variants'         => [],
                'addons'           => [],
                'quantity'         => 1,
                'price'            => 1000,
                'subtotal'         => 1000,
            ],
            [
                'product_id'       => 'product_coffee',
                'store_id'         => 'store_ijklmnop',
                'store_location_id'=> 'store_location_ijklmnop',
                'name'             => 'Coffee',
                'variants'         => [],
                'addons'           => [],
                'quantity'         => 1,
                'price'            => 500,
                'subtotal'         => 500,
            ],
        ]),
        'events'            => '[]',
    ]);
    $connection->table('service_quotes')->insert([
        'uuid'                   => 'quote_uuid',
        'public_id'              => 'quote_abcdefgh',
        'amount'                 => 300,
        'currency'               => 'USD',
        'integrated_vendor_uuid' => 'integrated_vendor_uuid',
        'meta'                   => json_encode([
            'origin'      => ['place_abcdefgh', 'place_qrstuvwx'],
            'destination' => 'place_ijklmnop',
        ]),
    ]);
    $connection->table('checkouts')->insert([
        'uuid'               => 'checkout_uuid',
        'public_id'          => 'checkout_abcdefgh',
        'company_uuid'       => 'company_uuid',
        'network_uuid'       => 'network_uuid',
        'cart_uuid'          => 'cart_uuid',
        'service_quote_uuid' => 'quote_uuid',
        'owner_uuid'         => 'customer_uuid',
        'owner_type'         => Contact::class,
        'amount'             => 300,
        'currency'           => 'USD',
        'is_cod'             => true,
        'is_pickup'          => false,
        'options'            => json_encode(['tip' => 25, 'delivery_tip' => 50]),
        'token'              => 'checkout-token',
        'captured'           => false,
    ]);
    session([
        'storefront_key'     => 'network_key',
        'storefront_store'   => null,
        'storefront_network' => 'network_uuid',
        'company'            => 'company_uuid',
    ]);

    CheckoutOrderAutomationStub::$accepted   = 0;
    CheckoutOrderAutomationStub::$dispatched = 0;
    $resource                                = (new CheckoutIntegratedVendorStub())->captureOrder(
        CaptureOrderRequest::create('/checkout/capture', 'POST', [
            'token'              => 'checkout-token',
            'transactionDetails' => ['transaction_id' => 'cash_network_receipt'],
            'notes'              => 'Network checkout',
        ])
    );
    Model::unsetEventDispatcher();

    $orders   = $connection->table('orders')->orderBy('id')->get();
    $checkout = $connection->table('checkouts')->where('uuid', 'checkout_uuid')->first();
    $child    = $orders->first(fn ($order) => data_get(json_decode($order->meta, true), 'is_master_order') === false);
    $master   = $orders->first(fn ($order) => data_get(json_decode($order->meta, true), 'is_master_order') === true);

    expect($resource)->toBeInstanceOf(Fleetbase\FleetOps\Http\Resources\v1\Order::class)
        ->and($orders)->toHaveCount(3)
        ->and($child)->not->toBeNull()
        ->and(json_decode($child->meta, true))->toMatchArray([
            'storefront_id'   => 'store_abcdefgh',
        ])
        ->and($master)->not->toBeNull()
        ->and(json_decode($master->meta, true))->toMatchArray([
            'storefront_network' => 'Delivery network',
        ])
        ->and((bool) $master->dispatched)->toBeTrue()
        ->and($checkout->order_uuid)->toBe($master->uuid)
        ->and((bool) $checkout->captured)->toBeTrue()
        ->and($connection->table('purchase_rates')->count())->toBe(3)
        ->and(CheckoutOrderAutomationStub::$accepted)->toBe(2)
        ->and(CheckoutOrderAutomationStub::$dispatched)->toBe(2)
        ->and($connection->table('transaction_items')->orderBy('id')->pluck('code')->all())
        ->toBe(['product', 'product', 'delivery_fee', 'tip', 'delivery_tip']);
});

test('single order capture reports missing storefront and expired cart boundaries', function () {
    createCheckoutBoundarySchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('checkouts')->insert([
        'uuid'     => 'checkout_uuid',
        'public_id'=> 'checkout_public',
        'token'    => 'checkout_token',
        'is_cod'   => true,
    ]);
    $controller = new CheckoutController();
    $request    = CaptureOrderRequest::create('/checkout/capture', 'POST', [
        'token' => 'checkout_token',
    ]);

    session(['storefront_key' => null]);
    $missingStorefront = $controller->captureOrder($request);

    $connection->table('stores')->insert([
        'uuid'         => 'store_uuid',
        'public_id'    => 'store_public',
        'company_uuid' => 'company_uuid',
        'key'          => 'store_key',
        'name'         => 'Test store',
        'currency'     => 'USD',
    ]);
    session(['storefront_key' => 'store_key']);
    $expiredCart = $controller->captureOrder($request);

    expect($missingStorefront->getData(true))->toBe(['error' => 'No storefront in request to capture order!'])
        ->and($expiredCart->getData(true))->toBe(['error' => 'Cart expired']);
});

test('single order capture is idempotent when checkout already references a completed order', function () {
    createCheckoutBoundarySchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('stores')->insert([
        'uuid'         => 'store_uuid',
        'public_id'    => 'store_abcdefgh',
        'company_uuid' => 'company_uuid',
        'key'          => 'store_key',
        'name'         => 'Test store',
        'currency'     => 'USD',
        'options'      => '{}',
    ]);
    $connection->table('orders')->insert([
        'uuid'      => 'order_uuid',
        'public_id' => 'order_abcdefgh',
    ]);
    $connection->table('checkouts')->insert([
        'uuid'       => 'checkout_uuid',
        'public_id'  => 'checkout_abcdefgh',
        'token'      => 'checkout-token',
        'order_uuid' => 'order_uuid',
        'captured'   => true,
        'is_cod'     => true,
    ]);
    session([
        'storefront_key'   => 'store_key',
        'storefront_store' => 'store_uuid',
        'company'          => 'company_uuid',
    ]);

    $resource = (new CheckoutController())->captureOrder(
        CaptureOrderRequest::create('/checkout/capture', 'POST', [
            'token'              => 'checkout-token',
            'transactionDetails' => 'malformed-details',
        ])
    );

    expect($resource)->toBeInstanceOf(Fleetbase\FleetOps\Http\Resources\v1\Order::class)
        ->and($resource->resource->uuid)->toBe('order_uuid')
        ->and($connection->table('orders')->count())->toBe(1);
});

test('single-store network capture rejects carts whose storefront can no longer be resolved', function () {
    createCheckoutBoundarySchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('networks')->insert([
        'uuid'         => 'network_uuid',
        'public_id'    => 'network_abcdefgh',
        'company_uuid' => 'company_uuid',
        'key'          => 'network_key',
        'name'         => 'Delivery network',
        'currency'     => 'USD',
        'options'      => '{}',
    ]);
    $connection->table('carts')->insert([
        'uuid'              => 'cart_uuid',
        'public_id'         => 'cart_abcdefgh',
        'unique_identifier' => 'browser-cart',
        'currency'          => 'USD',
        'items'             => json_encode([
            [
                'id'                => 'line_one',
                'store_id'          => 'store_missing',
                'store_location_id' => null,
                'quantity'          => 1,
                'subtotal'          => 1000,
            ],
        ]),
        'events' => '[]',
    ]);
    $connection->table('checkouts')->insert([
        'uuid'        => 'checkout_uuid',
        'public_id'   => 'checkout_abcdefgh',
        'token'       => 'checkout-token',
        'cart_uuid'   => 'cart_uuid',
        'currency'    => 'USD',
        'amount'      => 1000,
        'is_cod'      => true,
        'is_pickup'   => true,
        'options'     => json_encode(['is_pickup' => true]),
        'captured'    => false,
    ]);
    session([
        'storefront_key'     => 'network_key',
        'storefront_store'   => null,
        'storefront_network' => 'network_uuid',
        'company'            => 'company_uuid',
    ]);

    $response = (new CheckoutController())->captureOrder(
        CaptureOrderRequest::create('/checkout/capture', 'POST', [
            'token' => 'checkout-token',
        ])
    );

    expect($response->getData(true))->toBe([
        'error' => 'No storefront in request to capture order!',
    ])->and($connection->table('orders')->count())->toBe(0);
});

test('checkout cart item processing creates a logistics entity with commerce metadata', function () {
    $fleetbase     = Model::getConnectionResolver()->connection('mysql');
    $productSchema = $fleetbase->getSchemaBuilder();
    $entitySchema  = $fleetbase->getSchemaBuilder();
    $productSchema->dropIfExists('products');
    $entitySchema->dropIfExists('files');
    $entitySchema->dropIfExists('entities');
    $productSchema->create('products', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('primary_image_uuid')->nullable();
        $table->string('name')->nullable();
        $table->text('description')->nullable();
        $table->string('currency')->nullable();
        $table->string('sku')->nullable();
        $table->integer('price')->default(0);
        $table->integer('sale_price')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $entitySchema->create('files', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('url')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $entitySchema->create('entities', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('_key')->nullable();
        $table->string('payload_uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('customer_uuid')->nullable();
        $table->string('customer_type')->nullable();
        $table->string('photo_uuid')->nullable();
        $table->string('internal_id')->nullable();
        $table->string('name')->nullable();
        $table->text('description')->nullable();
        $table->string('currency')->nullable();
        $table->string('sku')->nullable();
        $table->integer('price')->nullable();
        $table->integer('sale_price')->nullable();
        $table->text('meta')->nullable();
        $table->string('slug')->nullable();
        $table->text('qr_code')->nullable();
        $table->text('barcode')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $productSchema->getConnection()->table('products')->insert([
        'uuid'        => (string) Str::uuid(),
        'public_id'   => 'product_coffee',
        'name'        => 'Coffee',
        'description' => 'Fresh coffee',
        'currency'    => 'USD',
        'sku'         => 'COFFEE-1',
        'price'       => 500,
        'sale_price'  => 450,
    ]);
    session(['company' => 'company_uuid']);
    Fleetbase\FleetOps\Models\Entity::expand(
        'fromStorefrontProduct',
        Fleetbase\Storefront\Expansions\EntityExpansion::fromStorefrontProduct()
    );

    $item = (object) [
        'product_id'  => 'product_coffee',
        'variants'    => [['name' => 'Large']],
        'addons'      => [['name' => 'Oat milk']],
        'subtotal'    => 900,
        'quantity'    => 2,
        'scheduled_at'=> '2026-07-27 18:00:00',
    ];
    $payload  = (object) ['uuid' => 'payload_uuid'];
    $customer = (object) ['uuid' => 'customer_uuid'];
    $method   = new ReflectionMethod(CheckoutController::class, 'processCartItem');
    $method->invoke(new CheckoutController(), $item, $payload, $customer);

    $entity = $fleetbase->table('entities')->first();

    expect($entity->payload_uuid)->toBe('payload_uuid')
        ->and($entity->company_uuid)->toBe('company_uuid')
        ->and($entity->customer_uuid)->toBe('customer_uuid')
        ->and($entity->internal_id)->toBe('product_coffee')
        ->and($entity->name)->toBe('Coffee')
        ->and(json_decode($entity->meta, true))->toMatchArray([
            'product_id'   => 'product_coffee',
            'variants'     => [['name' => 'Large']],
            'addons'       => [['name' => 'Oat milk']],
            'subtotal'     => 900,
            'quantity'     => 2,
            'scheduled_at' => '2026-07-27 18:00:00',
        ]);
});

test('checkout order creation is idempotent when the checkout already owns an order', function () {
    createCheckoutBoundarySchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('orders')->insert([
        'uuid'      => 'order_uuid',
        'public_id' => 'order_public',
    ]);
    $connection->table('checkouts')->insert([
        'uuid'       => 'checkout_uuid',
        'public_id'  => 'checkout_public',
        'token'      => 'checkout_token',
        'order_uuid' => 'order_uuid',
        'captured'   => true,
    ]);
    $checkout = Checkout::where('uuid', 'checkout_uuid')->firstOrFail();
    $method   = new ReflectionMethod(CheckoutController::class, 'createOrderFromCheckout');

    $order = $method->invoke(
        new CheckoutController(),
        $checkout,
        ['transaction_id' => 'provider_transaction']
    );

    expect($order)->toBeInstanceOf(Fleetbase\FleetOps\Models\Order::class)
        ->and($order->uuid)->toBe('order_uuid')
        ->and($order->public_id)->toBe('order_public')
        ->and(Checkout::where('uuid', 'checkout_uuid')->value('order_uuid'))->toBe('order_uuid');
});

test('checkout order fallback releases its lock when capture cannot create an order', function () {
    createCheckoutBoundarySchema();
    Model::getConnectionResolver()->connection('mysql')->table('checkouts')->insert([
        'uuid'      => 'checkout_uuid',
        'public_id' => 'checkout_public',
        'token'     => 'checkout_token',
        'captured'  => false,
    ]);
    session(['storefront_key' => null]);
    $checkout   = Checkout::where('uuid', 'checkout_uuid')->firstOrFail();
    $controller = new CheckoutController();
    $method     = new ReflectionMethod(CheckoutController::class, 'createOrderFromCheckout');

    $first  = $method->invoke($controller, $checkout, ['transaction_id' => 'provider_transaction']);
    $second = $method->invoke($controller, $checkout->fresh(), ['transaction_id' => 'provider_transaction']);

    expect($first)->toBeNull()
        ->and($second)->toBeNull()
        ->and(Checkout::where('uuid', 'checkout_uuid')->value('order_uuid'))->toBeNull();
});

test('checkout order fallback contains malformed provider transaction details', function () {
    createCheckoutBoundarySchema();
    Model::getConnectionResolver()->connection('mysql')->table('checkouts')->insert([
        'uuid'      => 'checkout_uuid',
        'public_id' => 'checkout_public',
        'token'     => 'checkout_token',
    ]);
    $checkout = Checkout::where('uuid', 'checkout_uuid')->firstOrFail();
    $method   = new ReflectionMethod(CheckoutController::class, 'createOrderFromCheckout');

    $result = $method->invoke(new CheckoutController(), $checkout, 'malformed-provider-payload');

    expect($result)->toBeNull()
        ->and(Checkout::where('uuid', 'checkout_uuid')->value('order_uuid'))->toBeNull();
});

test('checkout order fallback contains capture exceptions and releases its lock', function () {
    createCheckoutBoundarySchema();
    Model::getConnectionResolver()->connection('mysql')->table('checkouts')->insert([
        'uuid'      => 'checkout_uuid',
        'public_id' => 'checkout_public',
        'token'     => 'checkout_token',
    ]);
    $checkout = Checkout::where('uuid', 'checkout_uuid')->firstOrFail();
    $method   = new ReflectionMethod(CheckoutController::class, 'createOrderFromCheckout');

    $result = $method->invoke(
        new CheckoutCaptureFailureStub(),
        $checkout,
        ['transaction_id' => 'provider_transaction']
    );

    expect($result)->toBeNull()
        ->and($checkout->fresh()->order_uuid)->toBeNull();
});

test('checkout order creation returns safely when another process owns the checkout lock', function () {
    createCheckoutBoundarySchema();
    Model::getConnectionResolver()->connection('mysql')->table('checkouts')->insert([
        'uuid'      => 'checkout_uuid',
        'public_id' => 'checkout_public',
        'token'     => 'checkout_token',
    ]);
    $previousCache = app('cache');
    app()->instance('cache', new class {
        public function lock($key, $seconds): object
        {
            return new class {
                public function get(): bool
                {
                    return false;
                }
            };
        }
    });
    Illuminate\Support\Facades\Facade::clearResolvedInstance('cache');
    $checkout = Checkout::where('uuid', 'checkout_uuid')->firstOrFail();
    $method   = new ReflectionMethod(CheckoutController::class, 'createOrderFromCheckout');

    $result = $method->invoke(
        new CheckoutController(),
        $checkout,
        ['transaction_id' => 'provider_transaction']
    );
    app()->instance('cache', $previousCache);
    Illuminate\Support\Facades\Facade::clearResolvedInstance('cache');

    expect($result)->toBeNull()
        ->and($checkout->fresh()->order_uuid)->toBeNull();
});

test('checkout order creation returns the order completed while waiting for its lock', function () {
    createCheckoutBoundarySchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('orders')->insert([
        'uuid'      => 'concurrent_order_uuid',
        'public_id' => 'order_concurrent',
    ]);
    $connection->table('checkouts')->insert([
        'uuid'      => 'checkout_uuid',
        'public_id' => 'checkout_public',
        'token'     => 'checkout_token',
    ]);
    $previousCache = app('cache');
    app()->instance('cache', new class($connection) {
        public function __construct(private $connection)
        {
        }

        public function lock($key, $seconds): object
        {
            return new class($this->connection) {
                public function __construct(private $connection)
                {
                }

                public function get(): bool
                {
                    $this->connection->table('checkouts')->where('uuid', 'checkout_uuid')->update([
                        'order_uuid' => 'concurrent_order_uuid',
                        'captured'   => true,
                    ]);

                    return false;
                }
            };
        }
    });
    Illuminate\Support\Facades\Facade::clearResolvedInstance('cache');
    $checkout = Checkout::where('uuid', 'checkout_uuid')->firstOrFail();
    $method   = new ReflectionMethod(CheckoutController::class, 'createOrderFromCheckout');

    $result = $method->invoke(
        new CheckoutController(),
        $checkout,
        ['transaction_id' => 'provider_transaction']
    );
    app()->instance('cache', $previousCache);
    Illuminate\Support\Facades\Facade::clearResolvedInstance('cache');

    expect($result)->toBeInstanceOf(Fleetbase\FleetOps\Models\Order::class)
        ->and($result->uuid)->toBe('concurrent_order_uuid');
});
