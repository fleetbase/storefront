<?php

require_once __DIR__ . '/../../Support/PromotionTestHelpers.php';

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\Storefront\Console\Commands\ReleasePromotionReservations;
use Fleetbase\Storefront\Http\Controllers\v1\CheckoutController;
use Fleetbase\Storefront\Models\Cart;
use Fleetbase\Storefront\Models\Checkout;
use Fleetbase\Storefront\Models\Promotion;
use Fleetbase\Storefront\Models\PromotionRedemption;
use Fleetbase\Storefront\Promotions\PromotionContext;
use Fleetbase\Storefront\Promotions\PromotionRedemptions;
use Fleetbase\Storefront\Promotions\PromotionResult;
use Fleetbase\Storefront\Promotions\PromotionUnavailableException;
use Fleetbase\Storefront\Support\QPay;
use Illuminate\Http\Request;

function promotionCheckout(array $attributes = []): Checkout
{
    $checkout = new Checkout();
    $checkout->forceFill(array_merge(['uuid' => 'checkout_uuid', 'public_id' => 'chkt_1', 'currency' => 'USD'], $attributes));

    return $checkout;
}

function appliedResult(Promotion $promotion, int $amount = 700, int $delivery = 0, ?string $codeUuid = null): PromotionResult
{
    return new PromotionResult([[
        'promotion_uuid'  => $promotion->uuid,
        'promotion_id'    => $promotion->public_id,
        'name'            => $promotion->name,
        'type'            => $promotion->type,
        'code'            => $codeUuid ? 'CODE' : null,
        'code_uuid'       => $codeUuid,
        'amount'          => $amount,
        'delivery_amount' => $delivery,
        'line_amounts'    => ['line_1' => $amount],
        'store_amounts'   => ['store_a' => $amount],
    ]]);
}

function invokeCheckout(string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod(CheckoutController::class, $method))->invoke(null, ...$arguments);
}

function promotionCart(array $items, ?string $codes = null): Cart
{
    $cart = new Cart();
    $cart->forceFill(['uuid' => 'cart_uuid', 'currency' => 'USD', 'items' => $items, 'discount_code' => $codes]);

    return $cart;
}

beforeEach(function () {
    createPromotionSchema();
    seedPromotionStores();
    promotionDb()->table('products')->insert([
        ['uuid' => 'product_1_uuid', 'public_id' => 'product_1', 'store_uuid' => 'store_a_uuid', 'category_uuid' => 'category_food'],
    ]);
    session(['storefront_key' => 'store_key_a', 'company' => 'company_uuid']);
});

test('carts become promotion contexts with product categories and store uuids', function () {
    $cart = promotionCart([
        (object) ['id' => 'line_1', 'product_id' => 'product_1', 'store_id' => 'store_a', 'quantity' => 2, 'subtotal' => '6,000'],
        (object) ['id' => 'line_2', 'product_id' => 'deleted_product', 'store_id' => 'store_b', 'quantity' => 0, 'subtotal' => 1000],
    ]);

    $context = PromotionContext::fromCart($cart, null, null, true, 500);

    expect($context->subtotal())->toBe(7000)
        ->and($context->deliveryFee)->toBe(0)
        ->and($context->isPickup)->toBeTrue()
        ->and($context->currency)->toBe('USD')
        ->and($context->storeUuids())->toBe(['store_a_uuid', 'store_b_uuid'])
        ->and($context->lines[0]->categoryUuid)->toBe('category_food')
        ->and($context->lines[1]->quantity)->toBe(1)
        ->and($context->lines[1]->productUuid)->toBeNull();
});

test('checkout amounts subtract stored item and delivery discounts without going negative', function () {
    $quote = tap(new ServiceQuote())->forceFill(['amount' => 500]);
    $cart  = promotionCart([(object) ['subtotal' => 10000, 'quantity' => 1]]);

    expect(invokeCheckout('calculateCheckoutAmount', $cart, $quote, ['promotions' => ['discount_subtotal' => 1000, 'discount_delivery' => 500]]))->toBe(9000)
        ->and(invokeCheckout('calculateCheckoutAmount', $cart, $quote, ['tip' => '10%', 'promotions' => ['discount_subtotal' => 1000]]))->toBe(10500)
        ->and(invokeCheckout('calculateCheckoutAmount', $cart, null, ['is_pickup' => true, 'promotions' => ['discount_subtotal' => 99999, 'discount_delivery' => 500]]))->toBe(0)
        ->and(invokeCheckout('calculateCheckoutAmount', $cart, $quote, (object) ['promotions' => (object) ['discount_subtotal' => 0, 'discount_delivery' => 9999]]))->toBe(10000);
});

test('checkout pricing stores applied promotions and fails for codes that cannot be used', function () {
    makePromotion(['value' => 10]);
    $coded = makePromotion(['trigger' => Promotion::TRIGGER_CODE, 'value' => 5]);
    makePromotionCode($coded, 'SMALL');
    $cart = promotionCart([(object) ['id' => 'line_1', 'product_id' => 'product_1', 'store_id' => 'store_a', 'quantity' => 1, 'subtotal' => 10000]]);

    $options = (object) ['is_pickup' => false];
    $quote   = tap(new ServiceQuote())->forceFill(['amount' => 500]);
    $ok      = invokeCheckout('applyPromotions', $cart, $quote, $options, null, Request::create('/', 'POST', ['promo_code' => 'small']));

    expect($ok)->toBeNull()
        ->and($options->promotions['discount'])->toBe(1000)
        ->and($options->promotions['rejected'])->toBe([['code' => 'SMALL', 'reason' => 'not_combinable']]);

    $invalid = invokeCheckout('applyPromotions', promotionCart($cart->items, 'NOPE'), null, (object) ['is_pickup' => true], null, Request::create('/', 'POST'));

    expect($invalid->getStatusCode())->toBe(400)
        ->and($invalid->getData(true)['error'])->toBe('Promotion code "NOPE" cannot be applied (invalid_code).');

    $none = (object) ['is_pickup' => true];
    promotionDb()->table('promotions')->delete();

    expect(invokeCheckout('applyPromotions', $cart, null, $none, null, Request::create('/', 'POST')))->toBeNull()
        ->and(property_exists($none, 'promotions'))->toBeFalse();
});

test('checkout promotion codes merge request and cart codes', function () {
    $cart = promotionCart([], 'CART1');

    expect(invokeCheckout('promotionCodesFor', $cart, Request::create('/', 'POST', ['promo_codes' => ['A', 'CART1']])))->toBe(['A', 'CART1'])
        ->and(invokeCheckout('promotionCodesFor', $cart, Request::create('/', 'POST', ['promo_codes' => 'B,C'])))->toBe(['B', 'C', 'CART1'])
        ->and(invokeCheckout('promotionCodesFor', $cart, Request::create('/', 'POST', ['discount_code' => 'D'])))->toBe(['D', 'CART1'])
        ->and(invokeCheckout('promotionCodesFor', promotionCart([]), Request::create('/', 'POST')))->toBe([]);
});

test('checkouts reserve their promotions and are discarded when a promotion ran out', function () {
    $schema = promotionDb()->getSchemaBuilder();
    $schema->dropIfExists('checkouts');
    $schema->create('checkouts', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $promotion = makePromotion(['usage_limit' => 1]);
    promotionDb()->table('checkouts')->insert(['uuid' => 'checkout_uuid', 'public_id' => 'chkt_1']);
    $options = (object) ['promotions' => appliedResult($promotion)->toArray()];

    expect(invokeCheckout('reservePromotions', promotionCheckout(), $options, tap(new Contact())->forceFill(['uuid' => 'customer_uuid'])))->toBeNull()
        ->and(PromotionRedemption::query()->first()->only(['promotion_uuid', 'customer_uuid', 'checkout_uuid', 'amount', 'status']))->toBe([
            'promotion_uuid' => $promotion->uuid,
            'customer_uuid'  => 'customer_uuid',
            'checkout_uuid'  => 'checkout_uuid',
            'amount'         => 700,
            'status'         => 'reserved',
        ]);

    $second = promotionCheckout(['uuid' => 'checkout_2']);
    promotionDb()->table('checkouts')->insert(['uuid' => 'checkout_2', 'public_id' => 'chkt_2']);
    $second->exists = true;
    $error          = invokeCheckout('reservePromotions', $second, $options, null);

    expect($error->getData(true)['error'])->toContain('is no longer available')
        ->and(promotionDb()->table('checkouts')->where('uuid', 'checkout_2')->value('deleted_at'))->not->toBeNull()
        ->and(invokeCheckout('reservePromotions', promotionCheckout(), (object) [], null))->toBeNull();
});

test('reservations respect budgets, per customer limits and code limits under lock', function ($attributes, $codeLimit, $setup) {
    $promotion = makePromotion($attributes);
    $code      = makePromotionCode($promotion, 'CODE', ['usage_limit' => $codeLimit]);
    $setup($promotion);

    expect(fn () => PromotionRedemptions::reserve(promotionCheckout(), appliedResult($promotion, 700, 0, $code->uuid), 'customer_uuid'))
        ->toThrow(PromotionUnavailableException::class);
})->with([
    'budget'       => [['budget_amount' => 1000], null, fn ($promotion) => promotionDb()->table('promotion_redemptions')->insert(['uuid' => 'r', 'promotion_uuid' => $promotion->uuid, 'amount' => 400, 'status' => 'redeemed'])],
    'per customer' => [['usage_limit_per_customer' => 1], null, fn ($promotion) => promotionDb()->table('promotion_redemptions')->insert(['uuid' => 'r', 'promotion_uuid' => $promotion->uuid, 'customer_uuid' => 'customer_uuid', 'status' => 'redeemed'])],
    'code limit'   => [[], 1, fn ($promotion) => promotionDb()->table('promotion_redemptions')->insert(['uuid' => 'r', 'promotion_uuid' => $promotion->uuid, 'promotion_code_uuid' => 'code_uuid_code', 'status' => 'redeemed'])],
    'deleted'      => [[], null, fn ($promotion) => promotionDb()->table('promotions')->where('uuid', $promotion->uuid)->delete()],
]);

test('reservations succeed while capacity remains', function () {
    $promotion = makePromotion(['budget_amount' => 2000, 'usage_limit' => 5, 'usage_limit_per_customer' => 2]);
    $code      = makePromotionCode($promotion, 'CODE', ['usage_limit' => 3]);

    PromotionRedemptions::reserve(promotionCheckout(), appliedResult($promotion, 700, 300, $code->uuid), 'customer_uuid');
    PromotionRedemptions::reserve(promotionCheckout(), new PromotionResult(), 'customer_uuid');

    expect(PromotionRedemption::query()->pluck('amount')->all())->toBe([1000]);
});

test('captured orders redeem reservations, or record the use when the reservation is gone', function () {
    $promotion = makePromotion();
    $checkout  = promotionCheckout(['options' => ['promotions' => appliedResult($promotion, 700, 200)->toArray()]]);
    $order     = tap(new Order())->forceFill(['uuid' => 'order_uuid', 'company_uuid' => 'company_uuid', 'customer_uuid' => 'customer_uuid']);

    PromotionRedemptions::reserve($checkout, appliedResult($promotion, 700, 200), 'customer_uuid');
    promotionDb()->table('promotion_redemptions')->update(['uuid' => 'reserved_uuid']);
    PromotionRedemptions::redeem($checkout, $order);

    expect(PromotionRedemption::query()->get(['status', 'order_uuid', 'amount'])->toArray())->toBe([
        ['status' => 'redeemed', 'order_uuid' => 'order_uuid', 'amount' => 900],
    ]);

    promotionDb()->table('promotion_redemptions')->delete();
    PromotionRedemptions::redeem($checkout, $order);
    PromotionRedemptions::redeem(promotionCheckout(), $order);

    expect(PromotionRedemption::query()->get(['status', 'order_uuid', 'customer_uuid', 'amount'])->toArray())->toBe([
        ['status' => 'redeemed', 'order_uuid' => 'order_uuid', 'customer_uuid' => 'customer_uuid', 'amount' => 900],
    ]);
});

test("a checkout's own reservations can be released before it is reserved again", function () {
    $promotion = makePromotion();
    promotionDb()->table('promotion_redemptions')->insert([
        ['uuid' => 'mine', 'promotion_uuid' => $promotion->uuid, 'checkout_uuid' => 'checkout_uuid', 'status' => 'reserved', 'created_at' => now()],
        ['uuid' => 'used', 'promotion_uuid' => $promotion->uuid, 'checkout_uuid' => 'checkout_uuid', 'status' => 'redeemed', 'created_at' => now()],
        ['uuid' => 'theirs', 'promotion_uuid' => $promotion->uuid, 'checkout_uuid' => 'other_checkout_uuid', 'status' => 'reserved', 'created_at' => now()],
    ]);

    expect(PromotionRedemptions::releaseFor(promotionCheckout()))->toBe(1)
        ->and(promotionDb()->table('promotion_redemptions')->orderBy('uuid')->pluck('status', 'uuid')->all())->toBe([
            'mine'   => 'released',
            'theirs' => 'reserved',
            'used'   => 'redeemed',
        ]);
});

test('stale reservations are released by the scheduled command', function () {
    $promotion = makePromotion();
    promotionDb()->table('promotion_redemptions')->insert([
        ['uuid' => 'old', 'promotion_uuid' => $promotion->uuid, 'status' => 'reserved', 'created_at' => now()->subHours(2)],
        ['uuid' => 'new', 'promotion_uuid' => $promotion->uuid, 'status' => 'reserved', 'created_at' => now()],
        ['uuid' => 'done', 'promotion_uuid' => $promotion->uuid, 'status' => 'redeemed', 'created_at' => now()->subHours(2)],
    ]);
    $command = new class extends ReleasePromotionReservations {
        public array $lines = [];

        public function info($string, $verbosity = null)
        {
            $this->lines[] = $string;
        }
    };

    expect($command->handle())->toBe(0)
        ->and($command->lines)->toBe(['Released 1 stale promotion reservation(s).'])
        ->and(promotionDb()->table('promotion_redemptions')->orderBy('uuid')->pluck('status', 'uuid')->all())->toBe(['done' => 'redeemed', 'new' => 'reserved', 'old' => 'released']);
});

test('captures record the discount as a credit transaction item', function () {
    $schema = promotionDb()->getSchemaBuilder();
    $schema->dropIfExists('transaction_items');
    $schema->create('transaction_items', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('transaction_uuid')->nullable();
        $table->integer('amount')->nullable();
        $table->string('currency')->nullable();
        $table->string('details')->nullable();
        $table->string('code')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $promotion   = makePromotion(['name' => 'Fall sale']);
    $transaction = tap(new Fleetbase\Models\Transaction())->forceFill(['uuid' => 'transaction_uuid']);

    invokeCheckout('createDiscountTransactionItem', $transaction, appliedResult($promotion, 700, 300), 'USD');
    invokeCheckout('createDiscountTransactionItem', $transaction, new PromotionResult(), 'USD');

    $item = promotionDb()->table('transaction_items')->first();

    expect(promotionDb()->table('transaction_items')->count())->toBe(1)
        ->and((int) $item->amount)->toBe(1000)
        ->and(json_decode($item->meta, true)['direction'])->toBe('credit')
        ->and($item->code)->toBe('discount')
        ->and($item->details)->toBe('Discount: Fall sale')
        ->and(json_decode($item->meta, true)['promotions'][0]['amount'])->toBe(700);
});

test('QPay invoices include a negative discount line', function () {
    $cart  = promotionCart([(object) ['subtotal' => 10000, 'quantity' => 1]]);
    $quote = tap(new ServiceQuote())->forceFill(['amount' => 500]);

    $delivery = collect(QPay::createQpayInitialLines($cart, $quote, ['promotions' => ['discount_subtotal' => 1000, 'discount_delivery' => 500]]))->firstWhere('line_description', 'Discount');
    $pickup   = collect(QPay::createQpayInitialLines($cart, null, ['is_pickup' => true, 'promotions' => ['discount_subtotal' => 1000, 'discount_delivery' => 500]]))->firstWhere('line_description', 'Discount');
    $none     = collect(QPay::createQpayInitialLines($cart, $quote, []))->firstWhere('line_description', 'Discount');

    expect($delivery['line_unit_price'])->toBe('-1500.00')
        ->and($delivery['taxes'][0]['amount'])->toBe(-QPay::calculateTax(1500))
        ->and($pickup['line_unit_price'])->toBe('-1000.00')
        ->and($none)->toBeNull();
});

function createCheckoutPromotionTables(): void
{
    $schema = promotionDb()->getSchemaBuilder();
    foreach (['carts', 'gateways', 'contacts', 'service_quotes'] as $table) {
        $schema->dropIfExists($table);
    }
    $schema->create('carts', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
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
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('contacts', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('type')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('service_quotes', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->integer('amount')->nullable();
        $table->text('meta')->nullable();
        $table->timestamp('expired_at')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    promotionDb()->table('carts')->insert([
        'uuid'              => 'cart_uuid',
        'public_id'         => 'cart_public',
        'company_uuid'      => 'company_uuid',
        'unique_identifier' => 'browser-cart',
        'currency'          => 'USD',
        'items'             => json_encode([['id' => 'line_1', 'store_id' => 'store_a', 'quantity' => 1, 'subtotal' => 2000]]),
        'events'            => '[]',
    ]);
}

test('checkout initialization and stripe updates refuse promotion codes that cannot be applied', function () {
    createCheckoutPromotionTables();
    promotionDb()->table('contacts')->insert(['uuid' => 'customer_uuid', 'public_id' => 'contact_abcdefgh', 'company_uuid' => 'company_uuid', 'type' => 'customer']);
    promotionDb()->table('gateways')->insert(['uuid' => 'stripe_uuid', 'code' => 'stripe', 'owner_uuid' => 'store_a_uuid', 'type' => 'stripe', 'config' => '{}']);
    session(['storefront_store' => 'store_a_uuid', 'storefront_network' => null]);

    $before = (new CheckoutController())->beforeCheckout(Fleetbase\Storefront\Http\Requests\InitializeCheckoutRequest::create('/', 'POST', [
        'gateway'    => 'cash',
        'cash'       => true,
        'cart'       => 'browser-cart',
        'pickup'     => true,
        'promo_code' => 'nope',
    ]));
    $update = (new CheckoutController())->updateStripePaymentIntent(Request::create('/', 'POST', [
        'customer'   => 'contact_abcdefgh',
        'cart'       => 'browser-cart',
        'pickup'     => true,
        'promo_code' => 'nope',
    ]));

    expect($before->getData(true)['error'])->toBe('Promotion code "NOPE" cannot be applied (invalid_code).')
        ->and($update->getData(true)['error'])->toBe('Promotion code "NOPE" cannot be applied (invalid_code).');
});
