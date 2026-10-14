<?php

use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\Storefront\Http\Controllers\ConsoleOrderController;
use Fleetbase\Storefront\Http\Controllers\v1\CheckoutController;
use Fleetbase\Storefront\Http\Controllers\v1\ServiceQuoteController;
use Fleetbase\Storefront\Http\Requests\CaptureOrderRequest;
use Fleetbase\Storefront\Http\Requests\GetServiceQuoteFromCart;
use Fleetbase\Storefront\Promotions\PromotionContext;
use Fleetbase\Storefront\Promotions\PromotionEngine;
use Fleetbase\Storefront\Promotions\PromotionResult;
use Fleetbase\Storefront\Support\Inventory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/*
 * Laravel's session() helper returns the session store when called without a key. The test
 * app's helper returns its values instead, so the console order controller (which releases its
 * storefront session keys with session()->forget()) gets a helper that behaves like Laravel's.
 */
class ConsoleOrderCoverageSessionStore
{
    public function get($key, $default = null)
    {
        return \session($key, $default);
    }

    public function put($key, $value = null): void
    {
        \session(is_array($key) ? $key : [$key => $value]);
    }

    public function has($key): bool
    {
        return \session($key) !== null;
    }

    public function forget($keys): void
    {
        \session(array_fill_keys((array) $keys, null));
    }
}

if (!function_exists('Fleetbase\Storefront\Http\Controllers\session')) {
    eval('namespace Fleetbase\Storefront\Http\Controllers; function session($key = null, $default = null) { return $key === null ? new \ConsoleOrderCoverageSessionStore() : \session($key, $default); }');
}

/** Promotions as the engine would price them, recording what it was asked. */
class ConsoleOrderCoveragePromotions extends PromotionEngine
{
    public array $calls = [];

    public function __construct(public PromotionResult $result)
    {
    }

    public function evaluate(PromotionContext $context, array $codes = []): PromotionResult
    {
        $this->calls[] = ['codes' => $codes, 'pickup' => $context->isPickup, 'delivery_fee' => $context->deliveryFee, 'subtotal' => $context->subtotal()];

        return $this->result;
    }
}

/** The app's delivery quote: answers with a quote, a list, an error, nothing or a failure. */
class ConsoleOrderCoverageQuotes extends ServiceQuoteController
{
    public string $mode    = 'quote';
    public array $requests = [];

    public function fromCart(GetServiceQuoteFromCart $request)
    {
        $this->requests[] = $request->only(['origin', 'destination', 'cart', 'scheduled_at']);
        $quote            = ServiceQuote::where('public_id', 'quote_delivery')->first();

        return match ($this->mode) {
            'quote'      => new Illuminate\Http\Resources\Json\JsonResource($quote),
            'collection' => new Illuminate\Http\Resources\Json\JsonResource(collect([$quote])),
            'empty'      => new Illuminate\Http\Resources\Json\JsonResource(null),
            'error'      => new JsonResponse(['errors' => ['No service rate covers this area.']], 400),
            'throw'      => throw new RuntimeException('The routing service is unavailable.'),
        };
    }
}

/** The app's capture, recording the request the console handed it. */
class ConsoleOrderCoverageCapture extends CheckoutController
{
    public ?CaptureOrderRequest $request = null;

    public function captureOrder(CaptureOrderRequest $request)
    {
        $this->request = $request;

        return new JsonResponse(['order' => 'order_captured', 'token' => $request->input('token')]);
    }
}

/** The presence checks of the capture request, on the test database whatever connection they name. */
class ConsoleOrderCoveragePresence extends Illuminate\Validation\DatabasePresenceVerifier
{
    public function setConnection($connection)
    {
    }
}

function consoleOrderCoverageDb()
{
    return Model::getConnectionResolver()->connection('mysql');
}

function consoleOrderCoverageTables(array $tables): void
{
    $schema = consoleOrderCoverageDb()->getSchemaBuilder();
    foreach ($tables as $table => $columns) {
        $schema->dropIfExists($table);
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                if (str_starts_with($column, 'int:')) {
                    $blueprint->integer(substr($column, 4))->nullable();
                    continue;
                }
                $blueprint->text($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }
}

/** A store with a location, two products, a customer, a delivery address and a quote. */
function consoleOrderCoverageSchema(): void
{
    consoleOrderCoverageTables([
        'stores'                => ['uuid', 'public_id', 'company_uuid', 'key', 'name', 'currency', 'timezone', 'options', 'logo_uuid', 'backdrop_uuid', 'int:online'],
        'store_locations'       => ['uuid', 'public_id', 'company_uuid', 'store_uuid', 'place_uuid', 'name'],
        'places'                => ['uuid', 'public_id', 'company_uuid', 'name', 'street1', 'location', 'meta'],
        'contacts'              => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'type', 'name', 'email', 'phone', 'meta'],
        'products'              => ['uuid', 'public_id', 'company_uuid', 'store_uuid', 'category_uuid', 'primary_image_uuid', 'name', 'description', 'int:price', 'currency', 'int:sale_price', 'int:is_on_sale', 'int:is_available', 'int:is_bookable', 'int:track_inventory', 'status', 'meta'],
        'files'                 => ['uuid', 'subject_uuid', 'path', 'disk'],
        'carts'                 => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'checkout_uuid', 'status', 'customer_id', 'unique_identifier', 'currency', 'discount_code', 'items', 'events', 'expires_at'],
        'service_quotes'        => ['uuid', 'public_id', 'company_uuid', 'int:amount', 'currency', 'integrated_vendor_uuid', 'meta', 'expired_at'],
        'checkouts'             => ['uuid', 'public_id', 'company_uuid', 'store_uuid', 'network_uuid', 'cart_uuid', 'gateway_uuid', 'service_quote_uuid', 'owner_uuid', 'owner_type', 'int:amount', 'currency', 'int:is_cod', 'int:is_pickup', 'options', 'cart_state', 'token', 'order_uuid', 'stripe_payment_intent_id', 'int:captured'],
        'promotions'            => ['uuid', 'public_id', 'company_uuid', 'status', 'name', 'int:usage_limit', 'int:usage_limit_per_customer', 'int:budget_amount'],
        'promotion_redemptions' => ['uuid', 'company_uuid', 'promotion_uuid', 'promotion_code_uuid', 'customer_uuid', 'checkout_uuid', 'order_uuid', 'int:amount', 'currency', 'status'],
    ]);
    consoleOrderCoverageDb()->getSchemaBuilder()->dropIfExists('inventory_reservations');
    Inventory::flushEnabled();

    $db = consoleOrderCoverageDb();
    $db->table('stores')->insert([
        ['uuid' => 'store_uuid', 'public_id' => 'store_corner', 'company_uuid' => 'company_uuid', 'key' => 'store_key_corner', 'name' => 'Corner Deli', 'currency' => 'USD', 'options' => json_encode(['required_checkout_min' => true, 'required_checkout_min_amount' => 3000]), 'online' => 1],
        ['uuid' => 'foreign_store_uuid', 'public_id' => 'store_foreign', 'company_uuid' => 'other_company_uuid', 'key' => 'store_key_foreign', 'name' => 'Elsewhere', 'currency' => 'USD', 'options' => null, 'online' => 1],
    ]);
    $db->table('places')->insert([
        ['uuid' => 'store_place_uuid', 'public_id' => 'place_store', 'company_uuid' => 'company_uuid', 'name' => 'Corner Deli'],
        ['uuid' => 'home_place_uuid', 'public_id' => 'place_home', 'company_uuid' => 'company_uuid', 'name' => 'Home'],
    ]);
    $db->table('store_locations')->insert([
        ['uuid' => 'location_uuid', 'public_id' => 'store_location_main', 'company_uuid' => 'company_uuid', 'store_uuid' => 'store_uuid', 'place_uuid' => 'store_place_uuid', 'name' => 'Main'],
        ['uuid' => 'kiosk_uuid', 'public_id' => 'store_location_kiosk', 'company_uuid' => 'company_uuid', 'store_uuid' => 'store_uuid', 'place_uuid' => 'missing_place_uuid', 'name' => 'Kiosk'],
    ]);
    $db->table('contacts')->insert(['uuid' => 'customer_uuid', 'public_id' => 'contact_ada', 'company_uuid' => 'company_uuid', 'type' => 'customer', 'name' => 'Ada Buyer']);
    $db->table('products')->insert([
        ['uuid' => 'soup_uuid', 'public_id' => 'product_soup', 'company_uuid' => 'company_uuid', 'store_uuid' => 'store_uuid', 'name' => 'Tomato soup', 'price' => 900, 'currency' => 'USD', 'sale_price' => 0, 'is_on_sale' => 0, 'is_available' => 1, 'is_bookable' => 0, 'track_inventory' => 0, 'status' => 'published', 'meta' => '{}'],
        ['uuid' => 'bread_uuid', 'public_id' => 'product_bread', 'company_uuid' => 'company_uuid', 'store_uuid' => 'store_uuid', 'name' => 'Sourdough', 'price' => 700, 'currency' => 'USD', 'sale_price' => 500, 'is_on_sale' => 1, 'is_available' => 1, 'is_bookable' => 0, 'track_inventory' => 0, 'status' => 'published', 'meta' => '{}'],
    ]);
    $db->table('service_quotes')->insert(['uuid' => 'quote_uuid', 'public_id' => 'quote_delivery', 'company_uuid' => 'company_uuid', 'amount' => 450, 'currency' => 'USD', 'meta' => json_encode(['eta' => '25 min'])]);
}

/** Model events give carts and checkouts their ids and tokens; the cache invalidation after a write is left out. */
function consoleOrderCoverageModelEvents(): void
{
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
}

function consoleOrderCoverageBind(?PromotionResult $promotions = null): object
{
    $fakes = (object) [
        'promotions' => new ConsoleOrderCoveragePromotions($promotions ?? new PromotionResult()),
        'quotes'     => new ConsoleOrderCoverageQuotes(),
        'capture'    => new ConsoleOrderCoverageCapture(),
    ];
    app()->instance(PromotionEngine::class, $fakes->promotions);
    app()->instance(ServiceQuoteController::class, $fakes->quotes);
    app()->instance(CheckoutController::class, $fakes->capture);

    return $fakes;
}

function consoleOrderCoverageRequest(array $input): Request
{
    return Request::create('/storefront/console-orders', 'POST', $input);
}

function consoleOrderCoverageSessionReleased(): bool
{
    foreach (['storefront_key', 'storefront_store', 'storefront_store_public_id', 'storefront_currency', 'customer_id'] as $key) {
        if (session($key) !== null) {
            return false;
        }
    }

    return true;
}

beforeEach(function () {
    $this->consoleOrderCoverageState = [
        'dispatcher' => Model::getEventDispatcher(),
        'validator'  => app()->bound('validator') ? app('validator') : null,
    ];
    consoleOrderCoverageSchema();
    consoleOrderCoverageModelEvents();
    app()->instance('redirect', new stdClass());
    $validator = new Illuminate\Validation\Factory(new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader(), 'en'), app());
    $validator->setPresenceVerifier(new ConsoleOrderCoveragePresence(Model::getConnectionResolver()));
    app()->instance('validator', $validator);
    session(['company' => 'company_uuid', 'user' => 'staff_uuid', 'storefront_network' => null, 'storefront_key' => null, 'storefront_store' => null, 'customer_id' => null]);
});

afterEach(function () {
    $state = $this->consoleOrderCoverageState;
    $state['dispatcher'] ? Model::setEventDispatcher($state['dispatcher']) : Model::unsetEventDispatcher();
    Model::clearBootedModels();
    if ($state['validator']) {
        app()->instance('validator', $state['validator']);
    }
    foreach ([PromotionEngine::class, ServiceQuoteController::class, CheckoutController::class, 'redirect'] as $abstract) {
        app()->offsetUnset($abstract);
    }
    session(['user' => null]);
});

test('a console quote needs a store of the company and at least one product', function () {
    $fakes      = consoleOrderCoverageBind();
    $controller = new ConsoleOrderController();

    $noStore      = $controller->quote(consoleOrderCoverageRequest(['items' => [['product' => 'product_soup']]]));
    $foreignStore = $controller->quote(consoleOrderCoverageRequest(['store' => 'store_foreign', 'items' => [['product' => 'product_soup']]]));
    $noItems      = $controller->quote(consoleOrderCoverageRequest(['store' => 'store_corner', 'customer' => 'customer_ada', 'items' => []]));

    expect($noStore->getStatusCode())->toBe(404)
        ->and($noStore->getData(true))->toBe(['error' => 'Store not found.'])
        ->and($foreignStore->getStatusCode())->toBe(404)
        ->and($noItems->getStatusCode())->toBe(422)
        ->and($noItems->getData(true))->toBe(['error' => 'Add at least one product to the order.'])
        ->and(consoleOrderCoverageSessionReleased())->toBeTrue()
        ->and($fakes->promotions->calls)->toBe([]);
});

test('a console pickup quote prices the cart, its promotions, tips and the store minimum', function () {
    $fakes = consoleOrderCoverageBind(new PromotionResult(
        [['promotion_id' => 'promotion_ten', 'name' => 'Ten off', 'type' => 'fixed_amount', 'code' => 'SAVE10', 'amount' => 300, 'delivery_amount' => 0]],
        [['code' => 'STACK', 'reason' => PromotionEngine::REASON_NOT_COMBINABLE], ['code' => 'OLD', 'reason' => PromotionEngine::REASON_NOT_ACTIVE]]
    ));

    $quote = (new ConsoleOrderController())->quote(consoleOrderCoverageRequest([
        'store'        => 'store_uuid',
        'customer'     => 'customer_ada',
        'is_pickup'    => true,
        'tip'          => 200,
        'delivery_tip' => 150,
        'promo_codes'  => 'SAVE10, STACK ,SAVE10,,OLD',
        'items'        => [
            ['product' => 'product_soup', 'quantity' => 2, 'variants' => [['additional_cost' => 100], null]],
            ['product'  => 'product_bread', 'quantity' => 0, 'addons' => [['price' => 50]]],
            ['quantity' => 3],
        ],
    ]))->getData(true);
    $cart = Fleetbase\Storefront\Models\Cart::where('public_id', $quote['cart'])->first();

    expect($quote['totals'])->toBe(['subtotal' => 2550, 'delivery_fee' => 0, 'discount' => 300, 'tip' => 200, 'delivery_tip' => 0, 'total' => 2450, 'items' => 3])
        ->and($quote['service_quote'])->toBeNull()
        ->and($quote['quote_error'])->toBeNull()
        ->and($quote['currency'])->toBe('USD')
        ->and($quote['promotions']['applied'][0])->toMatchArray(['promotion' => 'promotion_ten', 'code' => 'SAVE10', 'amount' => 300])
        // a code that only could not be combined does not block the order
        ->and($quote['promotions']['rejected'])->toBe([['code' => 'OLD', 'reason' => PromotionEngine::REASON_NOT_ACTIVE]])
        ->and($quote['minimum'])->toBe(['required' => true, 'amount' => 3000, 'met' => false])
        ->and($quote['eta'])->toBeNull()
        ->and($fakes->promotions->calls[0])->toBe(['codes' => ['SAVE10', 'STACK', 'OLD'], 'pickup' => true, 'delivery_fee' => 0, 'subtotal' => 2550])
        ->and($cart->unique_identifier)->toStartWith('console_')
        ->and($cart->getPromotionCodes())->toBe(['SAVE10', 'STACK', 'OLD'])
        ->and(array_column($cart->items, 'product_id'))->toBe(['product_soup', 'product_bread'])
        ->and($fakes->quotes->requests)->toBe([])
        ->and(consoleOrderCoverageSessionReleased())->toBeTrue();
});

test('a console delivery quote quotes from the store location to the customer address', function () {
    $fakes = consoleOrderCoverageBind();
    $input = [
        'store'           => 'store_corner',
        'customer'        => 'contact_ada',
        'pickup_location' => 'store_location_main',
        'dropoff'         => 'place_home',
        'scheduled_at'    => null,
        'delivery_tip'    => 100,
        'promo_code'      => ['WELCOME'],
        'items'           => [['product' => 'product_soup', 'quantity' => 4]],
    ];

    $quoted = (new ConsoleOrderController())->quote(consoleOrderCoverageRequest($input))->getData(true);

    expect($quoted['service_quote'])->toBe('quote_delivery')
        ->and($quoted['quote_error'])->toBeNull()
        ->and($quoted['eta'])->toBe('25 min')
        ->and($quoted['totals'])->toMatchArray(['subtotal' => 3600, 'delivery_fee' => 450, 'delivery_tip' => 100, 'total' => 4150])
        ->and($quoted['minimum']['met'])->toBeTrue()
        ->and($fakes->quotes->requests[0])->toMatchArray(['origin' => 'place_store', 'destination' => 'place_home', 'cart' => $quoted['cart']])
        ->and($fakes->promotions->calls[0])->toMatchArray(['codes' => ['WELCOME'], 'pickup' => false, 'delivery_fee' => 450]);

    // A quote served as a list is read from its first rate.
    $fakes->quotes->mode = 'collection';
    expect((new ConsoleOrderController())->quote(consoleOrderCoverageRequest($input))->getData(true)['service_quote'])->toBe('quote_delivery');
});

test('a console delivery quote explains why there is no delivery fee', function (array $input, string $mode, string $error) {
    $fakes               = consoleOrderCoverageBind();
    $fakes->quotes->mode = $mode;

    $quoted = (new ConsoleOrderController())->quote(consoleOrderCoverageRequest([
        'store' => 'store_corner',
        'items' => [['product' => 'product_soup']],
        ...$input,
    ]))->getData(true);

    expect($quoted['quote_error'])->toBe($error)
        ->and($quoted['service_quote'])->toBeNull()
        ->and($quoted['totals']['delivery_fee'])->toBe(0)
        ->and($quoted['eta'])->toBeNull();
})->with([
    'no store location'          => [['dropoff' => 'place_home'], 'quote', 'Pick the store location the order leaves from.'],
    'a location without a place' => [['pickup_location' => 'kiosk_uuid', 'dropoff' => 'place_home'], 'quote', 'Pick the store location the order leaves from.'],
    'no drop-off'                => [['pickup_location' => 'location_uuid'], 'quote', 'Pick where the order is delivered to.'],
    'an unknown drop-off'        => [['pickup_location' => 'location_uuid', 'dropoff' => 'place_missing'], 'quote', 'Pick where the order is delivered to.'],
    'the quote failed'           => [['pickup_location' => 'location_uuid', 'dropoff' => 'home_place_uuid'], 'throw', 'The routing service is unavailable.'],
    'no rate covers it'          => [['pickup_location' => 'location_uuid', 'dropoff' => 'place_home'], 'error', 'No service rate covers this area.'],
    'no quote came back'         => [['pickup_location' => 'location_uuid', 'dropoff' => 'place_home'], 'empty', 'No delivery rate covers this address.'],
]);

test('placing a console order needs a store and a customer', function () {
    consoleOrderCoverageBind();
    $controller = new ConsoleOrderController();

    $noStore    = $controller->place(consoleOrderCoverageRequest(['customer' => 'contact_ada']));
    $noCustomer = $controller->place(consoleOrderCoverageRequest(['store' => 'store_corner']));
    $unknown    = $controller->place(consoleOrderCoverageRequest(['store' => 'store_corner', 'customer' => 'customer_nobody']));

    expect($noStore->getStatusCode())->toBe(404)
        ->and($noCustomer->getStatusCode())->toBe(422)
        ->and($noCustomer->getData(true))->toBe(['error' => 'Pick a customer for the order.'])
        ->and($unknown->getData(true))->toBe(['error' => 'Pick a customer for the order.']);
});

test('placing a console order refuses an empty cart, an address without a rate and a blocking promotion', function () {
    $fakes      = consoleOrderCoverageBind();
    $controller = new ConsoleOrderController();
    $empty      = Fleetbase\Storefront\Models\Cart::newCart('console_empty');

    $emptyCart = $controller->place(consoleOrderCoverageRequest(['store' => 'store_corner', 'customer' => 'customer_ada', 'cart' => $empty->public_id]));

    $fakes->quotes->mode = 'error';
    $noRate              = $controller->place(consoleOrderCoverageRequest(['store' => 'store_corner', 'customer' => 'customer_ada', 'pickup_location' => 'location_uuid', 'dropoff' => 'place_home', 'items' => [['product' => 'product_soup']]]));
    $fakes->quotes->mode = 'empty';
    $noQuote             = $controller->place(consoleOrderCoverageRequest(['store' => 'store_corner', 'customer' => 'customer_ada', 'pickup_location' => 'location_uuid', 'dropoff' => 'place_home', 'items' => [['product' => 'product_soup']]]));

    $fakes->promotions->result = new PromotionResult([], [['code' => 'EXPIRED', 'reason' => PromotionEngine::REASON_NOT_ACTIVE]]);
    $blocked                   = $controller->place(consoleOrderCoverageRequest(['store' => 'store_corner', 'customer' => 'customer_ada', 'is_pickup' => true, 'promo_codes' => ['EXPIRED'], 'items' => [['product' => 'product_soup']]]));

    expect($emptyCart->getStatusCode())->toBe(422)
        ->and($emptyCart->getData(true))->toBe(['error' => 'Add at least one product to the order.'])
        ->and($noRate->getData(true))->toBe(['error' => 'No service rate covers this area.'])
        ->and($noQuote->getData(true))->toBe(['error' => 'No delivery rate covers this address.'])
        ->and($blocked->getStatusCode())->toBe(422)
        ->and($blocked->getData(true))->toBe(['error' => 'Promotion code "EXPIRED" cannot be applied (not_active).'])
        ->and(consoleOrderCoverageDb()->table('checkouts')->count())->toBe(0)
        ->and(consoleOrderCoverageSessionReleased())->toBeTrue();
});

test('placing a console order returns the checkout error when a promotion ran out meanwhile', function () {
    consoleOrderCoverageBind(new PromotionResult([['promotion_uuid' => 'promotion_gone_uuid', 'name' => 'Summer deal', 'code' => 'SUMMER', 'amount' => 200]]));

    $refused = (new ConsoleOrderController())->place(consoleOrderCoverageRequest(['store' => 'store_corner', 'customer' => 'customer_ada', 'is_pickup' => true, 'promo_codes' => 'SUMMER', 'items' => [['product' => 'product_soup']]]));

    expect($refused->getStatusCode())->toBe(400)
        ->and($refused->getData(true))->toBe(['error' => 'The promotion "Summer deal" is no longer available. Please review your cart and try again.'])
        // the checkout is discarded
        ->and(consoleOrderCoverageDb()->table('checkouts')->whereNull('deleted_at')->count())->toBe(0)
        ->and(consoleOrderCoverageSessionReleased())->toBeTrue();
});

test('placing a console order refuses when its checkout is gone before the capture', function () {
    $fakes = consoleOrderCoverageBind();
    // Another request discards the checkout as soon as it is written.
    consoleOrderCoverageDb()->statement("CREATE TRIGGER console_order_checkout_gone AFTER INSERT ON checkouts BEGIN UPDATE checkouts SET deleted_at = '2026-10-14 10:00:00' WHERE id = NEW.id; END");

    $refused = (new ConsoleOrderController())->place(consoleOrderCoverageRequest(['store' => 'store_corner', 'customer' => 'customer_ada', 'is_pickup' => true, 'items' => [['product' => 'product_soup']]]));

    expect($refused->getStatusCode())->toBe(422)
        ->and($refused->getData(true))->toBe(['error' => 'Unable to start the checkout.'])
        ->and($fakes->capture->request)->toBeNull();
});

test('placing a console pickup order runs a cash checkout and captures it as paid', function () {
    $fakes = consoleOrderCoverageBind(new PromotionResult([['promotion_uuid' => 'promotion_uuid', 'name' => 'Ten off', 'code' => 'SAVE10', 'amount' => 300]]));
    consoleOrderCoverageDb()->table('promotions')->insert(['uuid' => 'promotion_uuid', 'public_id' => 'promotion_ten', 'company_uuid' => 'company_uuid', 'status' => 'active', 'name' => 'Ten off']);

    $placed = (new ConsoleOrderController())->place(consoleOrderCoverageRequest([
        'store'          => 'store_corner',
        'customer'       => 'customer_ada',
        'is_pickup'      => true,
        'tip'            => 200,
        'delivery_tip'   => 300,
        'promo_codes'    => ['SAVE10'],
        'payment'        => 'paid',
        'payment_method' => 'card_terminal',
        'notes'          => 'Extra napkins',
        'items'          => [['product' => 'product_soup', 'quantity' => 2]],
    ]));
    $checkout = Fleetbase\Storefront\Models\Checkout::first();
    $capture  = $fakes->capture->request;

    expect($placed->getData(true))->toBe(['order' => 'order_captured', 'token' => $checkout->token])
        ->and($checkout->owner_uuid)->toBe('customer_uuid')
        ->and($checkout->store_uuid)->toBe('store_uuid')
        ->and((bool) $checkout->is_pickup)->toBeTrue()
        ->and((bool) $checkout->is_cod)->toBeTrue()
        // 1800 for the soup, 200 tip, no delivery tip on a pickup, 300 off
        ->and((int) $checkout->amount)->toBe(1700)
        ->and(data_get($checkout->options, 'placed_via'))->toBe('console')
        ->and(data_get($checkout->options, 'delivery_tip'))->toBeFalse()
        ->and(data_get($checkout->options, 'promotions.discount'))->toBe(300)
        ->and(consoleOrderCoverageDb()->table('promotion_redemptions')->value('checkout_uuid'))->toBe($checkout->uuid)
        ->and($capture->input('token'))->toBe($checkout->token)
        ->and($capture->input('notes'))->toBe('Extra napkins')
        ->and($capture->input('transactionDetails'))->toBe(['payment_status' => 'paid', 'payment_method' => 'card_terminal', 'placed_via' => 'console', 'placed_by' => 'staff_uuid'])
        ->and(Fleetbase\Storefront\Models\Cart::where('uuid', $checkout->cart_uuid)->value('customer_id'))->toBe('customer_uuid')
        ->and(consoleOrderCoverageSessionReleased())->toBeTrue();
});

test('placing a console delivery order uses the priced quote, or quotes again, and records cash to collect', function () {
    $fakes = consoleOrderCoverageBind();

    $quoted = (new ConsoleOrderController())->place(consoleOrderCoverageRequest([
        'store'         => 'store_corner',
        'customer'      => 'customer_ada',
        'service_quote' => 'quote_delivery',
        'delivery_tip'  => 100,
        'items'         => [['product' => 'product_bread', 'quantity' => 2]],
    ]));
    $first = Fleetbase\Storefront\Models\Checkout::first();

    expect($quoted->getData(true)['token'])->toBe($first->token)
        ->and($first->service_quote_uuid)->toBe('quote_uuid')
        // 1000 for the bread, 450 delivery and a 100 delivery tip
        ->and((int) $first->amount)->toBe(1550)
        ->and($fakes->quotes->requests)->toBe([])
        ->and($fakes->capture->request->input('transactionDetails'))->toMatchArray(['payment_status' => 'unpaid', 'payment_method' => 'cash']);

    $requoted = (new ConsoleOrderController())->place(consoleOrderCoverageRequest([
        'store'           => 'store_corner',
        'customer'        => 'customer_ada',
        'service_quote'   => 'quote_expired',
        'pickup_location' => 'store_location_main',
        'dropoff'         => 'place_home',
        'items'           => [['product' => 'product_soup']],
    ]));

    expect($requoted->getStatusCode())->toBe(200)
        ->and($fakes->quotes->requests)->toHaveCount(1)
        ->and(Fleetbase\Storefront\Models\Checkout::where('token', $requoted->getData(true)['token'])->value('service_quote_uuid'))->toBe('quote_uuid');
});
