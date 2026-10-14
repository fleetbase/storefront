<?php

require_once __DIR__ . '/../../Support/PromotionTestHelpers.php';

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Promotion;
use Fleetbase\Storefront\Models\PromotionCode;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Promotions\PromotionCalculator;
use Fleetbase\Storefront\Promotions\PromotionEngine;
use Fleetbase\Storefront\Promotions\PromotionResult;
use Illuminate\Support\Carbon;

function promotionEngine(): PromotionEngine
{
    return new PromotionEngine(new PromotionCalculator());
}

function networkStorefront(): Network
{
    return tap(new Network())->forceFill(['uuid' => 'network_uuid', 'public_id' => 'network_m', 'company_uuid' => 'company_uuid']);
}

function promotionCustomer(string $uuid = 'customer_uuid'): Contact
{
    return tap(new Contact())->forceFill(['uuid' => $uuid]);
}

function redemption(Promotion $promotion, array $attributes = []): void
{
    promotionDb()->table('promotion_redemptions')->insert(array_merge([
        'uuid'           => uniqid('redemption_'),
        'promotion_uuid' => $promotion->uuid,
        'amount'         => 100,
        'status'         => 'redeemed',
        'created_at'     => now(),
        'updated_at'     => now(),
    ], $attributes));
}

beforeEach(function () {
    createPromotionSchema();
});

test('automatic promotions of the storefront apply without a code', function () {
    $promotion = makePromotion(['value' => 10, 'owner_uuid' => 'store_a_uuid']);
    makePromotion(['value' => 50, 'trigger' => Promotion::TRIGGER_CODE]);
    makePromotion(['value' => 50, 'status' => Promotion::STATUS_DRAFT]);
    makePromotion(['value' => 50, 'owner_uuid' => 'someone_else']);

    $result = promotionEngine()->evaluate(promotionContext());

    expect(array_column($result->applied, 'promotion_uuid'))->toBe([$promotion->uuid])
        // Store A promotion: only store A items (7000).
        ->and($result->discountSubtotal())->toBe(700)
        ->and($result->discountDelivery())->toBe(0)
        ->and($result->allocationsByStore())->toBe(['store_a' => 700])
        ->and($result->rejected)->toBe([]);
});

test('network carts can use the network and each store promotions', function () {
    makePromotion(['owner_uuid' => 'network_uuid', 'owner_type' => Network::class, 'type' => Promotion::TYPE_FREE_DELIVERY, 'stackable' => true]);
    makePromotion(['owner_uuid' => 'store_b_uuid', 'value' => 20, 'stackable' => true]);

    $result = promotionEngine()->evaluate(promotionContext(['storefront' => networkStorefront()]));

    expect($result->discountDelivery())->toBe(500)
        ->and($result->discountSubtotal())->toBe(600)
        ->and($result->allocationsByStore())->toBe(['store_b' => 600])
        ->and($result->discount())->toBe(1100);
});

test('codes unlock code promotions and report why other codes cannot be used', function () {
    $coded   = makePromotion(['trigger' => Promotion::TRIGGER_CODE, 'value' => 25]);
    $foreign = makePromotion(['trigger' => Promotion::TRIGGER_CODE, 'owner_uuid' => 'other_store']);
    makePromotionCode($coded, 'SAVE25');
    makePromotionCode($coded, 'OFF', ['status' => PromotionCode::STATUS_DISABLED]);
    makePromotionCode($coded, 'OLD', ['expires_at' => now()->subDay()]);
    makePromotionCode($coded, 'VIP', ['customer_uuid' => 'someone_else']);
    makePromotionCode($coded, 'ONCE', ['usage_limit' => 1]);
    makePromotionCode($foreign, 'ELSEWHERE');
    redemption($coded, ['promotion_code_uuid' => 'code_uuid_once']);

    $result = promotionEngine()->evaluate(
        promotionContext(['customer' => promotionCustomer()]),
        [' save25 ', 'off', 'OLD', 'vip', 'ONCE', 'ELSEWHERE', 'MISSING', '']
    );

    expect(array_column($result->applied, 'code'))->toBe(['SAVE25'])
        ->and($result->discountSubtotal())->toBe(1750)
        ->and($result->rejected)->toBe([
            ['code' => 'OFF', 'reason' => PromotionEngine::REASON_INVALID_CODE],
            ['code' => 'OLD', 'reason' => PromotionEngine::REASON_INVALID_CODE],
            ['code' => 'VIP', 'reason' => PromotionEngine::REASON_INVALID_CODE],
            ['code' => 'ONCE', 'reason' => PromotionEngine::REASON_USAGE_LIMIT],
            ['code' => 'ELSEWHERE', 'reason' => PromotionEngine::REASON_NOT_APPLICABLE],
            ['code' => 'MISSING', 'reason' => PromotionEngine::REASON_INVALID_CODE],
        ]);
});

test('promotion rules reject codes with the reason they do not apply', function ($attributes, $context, $reason, $setup = null) {
    $promotion = makePromotion(array_merge(['trigger' => Promotion::TRIGGER_CODE], $attributes));
    makePromotionCode($promotion, 'CODE');
    if ($setup) {
        $setup($promotion);
    }

    $result = promotionEngine()->evaluate(promotionContext($context), ['CODE']);

    expect($result->applied)->toBe([])
        ->and($result->rejected)->toBe([['code' => 'CODE', 'reason' => $reason]]);
})->with([
    'not started'           => [['starts_at' => '2999-01-01 00:00:00'], [], PromotionEngine::REASON_NOT_ACTIVE],
    'currency mismatch'     => [['type' => Promotion::TYPE_FIXED_AMOUNT, 'value' => 500, 'currency' => 'EUR'], [], PromotionEngine::REASON_CURRENCY_MISMATCH],
    'usage limit reached'   => [['usage_limit' => 1], [], PromotionEngine::REASON_USAGE_LIMIT, fn ($promotion) => redemption($promotion)],
    'budget exhausted'      => [['budget_amount' => 100], [], PromotionEngine::REASON_BUDGET_EXHAUSTED, fn ($promotion) => redemption($promotion)],
    'customer limit'        => [['usage_limit_per_customer' => 1], ['customer' => promotionCustomer()], PromotionEngine::REASON_CUSTOMER_USAGE_LIMIT, fn ($promotion) => redemption($promotion, ['customer_uuid' => 'customer_uuid'])],
    'first order only'      => [['first_order_only' => true], ['customer' => promotionCustomer()], PromotionEngine::REASON_FIRST_ORDER_ONLY, fn () => promotionDb()->table('orders')->insert(['uuid' => 'o1', 'customer_uuid' => 'customer_uuid', 'type' => 'storefront', 'status' => 'completed'])],
    'minimum subtotal'      => [['min_subtotal' => 50000], [], PromotionCalculator::REASON_MIN_SUBTOTAL],
    'pickup free delivery'  => [['type' => Promotion::TYPE_FREE_DELIVERY], ['is_pickup' => true, 'delivery_fee' => 0], PromotionCalculator::REASON_PICKUP_ORDER],
]);

test('customer rules are skipped until the customer is known and honor other customers usage', function () {
    $promotion = makePromotion(['usage_limit_per_customer' => 1, 'first_order_only' => true]);
    redemption($promotion, ['customer_uuid' => 'someone_else']);
    redemption($promotion, ['customer_uuid' => 'customer_uuid', 'status' => 'released']);
    redemption($promotion, ['customer_uuid' => 'customer_uuid', 'status' => 'reserved', 'created_at' => now()->subHours(3)]);
    promotionDb()->table('orders')->insert([
        ['uuid' => 'canceled', 'customer_uuid' => 'customer_uuid', 'type' => 'storefront', 'status' => 'canceled'],
        ['uuid' => 'fleet', 'customer_uuid' => 'customer_uuid', 'type' => 'default', 'status' => 'completed'],
    ]);

    expect(promotionEngine()->evaluate(promotionContext())->applied)->toHaveCount(1)
        ->and(promotionEngine()->evaluate(promotionContext(['customer' => promotionCustomer()]))->applied)->toHaveCount(1);
});

test('the best non stackable promotion wins over a smaller stack, and a larger stack wins over it', function () {
    $exclusive = makePromotion(['value' => 30, 'trigger' => Promotion::TRIGGER_CODE]);
    makePromotionCode($exclusive, 'BIG');
    makePromotion(['value' => 10, 'stackable' => true, 'priority' => 2]);
    makePromotion(['type' => Promotion::TYPE_FIXED_AMOUNT, 'value' => 300, 'stackable' => true, 'priority' => 1]);

    $withCode = promotionEngine()->evaluate(promotionContext(), ['BIG']);

    // 30% of store A's 7000 = 2100, versus 10% (700) then 300 off the rest = 1000.
    expect(array_column($withCode->applied, 'code'))->toBe(['BIG'])
        ->and($withCode->discountSubtotal())->toBe(2100);

    $withoutCode = promotionEngine()->evaluate(promotionContext());

    expect($withoutCode->applied)->toHaveCount(2)
        ->and(array_column($withoutCode->applied, 'amount'))->toBe([700, 300])
        ->and($withoutCode->discountSubtotal())->toBe(1000);
});

test('a code that loses to a better combination is reported as not combinable', function () {
    $small = makePromotion(['value' => 5, 'trigger' => Promotion::TRIGGER_CODE]);
    makePromotionCode($small, 'SMALL');
    makePromotion(['value' => 40]);

    $result = promotionEngine()->evaluate(promotionContext(), ['SMALL']);

    expect(array_column($result->applied, 'code'))->toBe([null])
        ->and($result->rejected)->toBe([['code' => 'SMALL', 'reason' => PromotionEngine::REASON_NOT_COMBINABLE]]);
});

test('stackable promotions that no longer discount anything are skipped', function () {
    makePromotion(['type' => Promotion::TYPE_FREE_DELIVERY, 'stackable' => true, 'priority' => 2, 'owner_uuid' => 'network_uuid', 'owner_type' => Network::class]);
    makePromotion(['type' => Promotion::TYPE_FREE_DELIVERY, 'stackable' => true, 'priority' => 1, 'owner_uuid' => 'network_uuid', 'owner_type' => Network::class]);

    $result = promotionEngine()->evaluate(promotionContext(['storefront' => networkStorefront()]));

    expect($result->applied)->toHaveCount(1)
        ->and($result->discountDelivery())->toBe(500);
});

test('maximum discounts and remaining budgets cap a promotion', function () {
    $capped = makePromotion(['value' => 50, 'max_discount_amount' => 1000, 'stackable' => true]);
    $budget = makePromotion(['owner_uuid' => 'network_uuid', 'owner_type' => Network::class, 'type' => Promotion::TYPE_FREE_DELIVERY, 'budget_amount' => 1000, 'stackable' => true]);
    redemption($budget, ['amount' => 800]);

    $result  = promotionEngine()->evaluate(promotionContext(['storefront' => networkStorefront()]));
    $applied = collect($result->applied)->keyBy('promotion_uuid');

    expect($applied[$capped->uuid]['amount'])->toBe(1000)
        ->and(array_sum($applied[$capped->uuid]['line_amounts']))->toBe(1000)
        ->and($applied[$budget->uuid]['delivery_amount'])->toBe(200)
        ->and($applied[$budget->uuid]['amount'])->toBe(0);
});

test('a capped delivery and item promotion keeps the delivery discount first', function () {
    makePromotion(['owner_uuid' => 'network_uuid', 'owner_type' => Network::class, 'type' => Promotion::TYPE_FREE_DELIVERY, 'max_discount_amount' => 300]);

    $result = promotionEngine()->evaluate(promotionContext(['storefront' => networkStorefront()]));

    expect($result->discountDelivery())->toBe(300)
        ->and($result->discountSubtotal())->toBe(0);
});

test('an empty storefront has no promotions', function () {
    makePromotion();

    $context             = promotionContext(['lines' => []]);
    $context->storefront = null;

    expect(promotionEngine()->evaluate($context)->isEmpty())->toBeTrue()
        ->and(promotionEngine()->evaluate($context)->toArray())->toBe([
            'discount'          => 0,
            'discount_subtotal' => 0,
            'discount_delivery' => 0,
            'applied'           => [],
            'rejected'          => [],
            'allocations'       => [],
        ]);
});

test('promotion results round trip through checkout options and hide internals publicly', function () {
    makePromotion(['name' => 'Tenner', 'value' => 10]);
    $result   = promotionEngine()->evaluate(promotionContext());
    $restored = PromotionResult::fromArray(json_decode(json_encode($result->toArray())));

    expect($restored->discount())->toBe(700)
        ->and($restored->allocationsByStore())->toBe(['store_a' => 700])
        ->and($restored->toPublicArray())->toBe([
            'discount'          => 700,
            'discount_subtotal' => 700,
            'discount_delivery' => 0,
            'applied'           => [[
                'promotion'       => 'promotion_' . substr($result->applied[0]['promotion_uuid'], strlen('promo_uuid_')),
                'name'            => 'Tenner',
                'type'            => Promotion::TYPE_PERCENTAGE,
                'code'            => null,
                'amount'          => 700,
                'delivery_amount' => 0,
            ]],
            'rejected' => [],
        ])
        ->and(PromotionResult::fromArray(null)->isEmpty())->toBeTrue();
});

test('promotions honor their date range and weekly schedule windows', function () {
    $promotion = new Promotion();
    $promotion->forceFill([
        'status'   => Promotion::STATUS_ACTIVE,
        'schedule' => [['days' => [1, 2, 3, 4, 5], 'start' => '15:00', 'end' => '18:00'], ['days' => [6], 'start' => '22:00', 'end' => '02:00'], 'junk'],
        'timezone' => 'UTC',
    ]);

    // 2026-09-28 is a Monday.
    expect($promotion->isLiveAt(Carbon::parse('2026-09-28 16:00', 'UTC')))->toBeTrue()
        ->and($promotion->isLiveAt(Carbon::parse('2026-09-28 18:00', 'UTC')))->toBeFalse()
        ->and($promotion->isLiveAt(Carbon::parse('2026-09-27 16:00', 'UTC')))->toBeFalse()
        // Saturday night window runs into Sunday morning.
        ->and($promotion->isLiveAt(Carbon::parse('2026-10-03 23:30', 'UTC')))->toBeTrue()
        ->and($promotion->isLiveAt(Carbon::parse('2026-10-04 01:30', 'UTC')))->toBeTrue()
        ->and($promotion->isLiveAt(Carbon::parse('2026-10-04 03:00', 'UTC')))->toBeFalse();

    $dated = tap(new Promotion())->forceFill(['status' => Promotion::STATUS_ACTIVE, 'starts_at' => '2026-01-01 00:00:00', 'ends_at' => '2026-02-01 00:00:00']);

    expect($dated->isLiveAt(Carbon::parse('2025-12-31 23:59')))->toBeFalse()
        ->and($dated->isLiveAt(Carbon::parse('2026-01-15')))->toBeTrue()
        ->and($dated->isLiveAt(Carbon::parse('2026-02-01 00:00')))->toBeFalse()
        ->and(tap(new Promotion())->forceFill(['status' => Promotion::STATUS_PAUSED])->isLiveAt())->toBeFalse()
        ->and(tap(new Promotion())->forceFill(['status' => Promotion::STATUS_ACTIVE])->isLiveAt())->toBeTrue();
});

test('promotion owners are inferred from the owning store or network', function () {
    seedPromotionStores();

    $store   = tap(new Promotion())->forceFill(['owner_uuid' => 'store_a_uuid']);
    $network = tap(new Promotion())->forceFill(['owner_uuid' => 'network_uuid']);
    $typed   = tap(new Promotion())->forceFill(['owner_uuid' => 'store_a_uuid', 'owner_type' => 'storefront:network']);
    foreach ([$store, $network, $typed] as $promotion) {
        $promotion->inferOwnerType();
    }

    expect($store->getAttributes()['owner_type'])->toBe(Store::class)
        ->and($network->getAttributes()['owner_type'])->toBe(Network::class)
        ->and($typed->getAttributes()['owner_type'])->toBe(Network::class);
});

test('promotion codes are normalized, generated without ambiguous characters and parsed from carts', function () {
    $code = PromotionCode::generate(10, 'vip-');
    $cart = new Fleetbase\Storefront\Models\Cart();
    $cart->setPromotionCodes([' save10', 'SAVE10', 'fall ', '']);

    expect(PromotionCode::normalize('  summer25 '))->toBe('SUMMER25')
        ->and($code)->toStartWith('VIP-')
        ->and(strlen($code))->toBe(14)
        ->and(preg_match('/[01IO]/', substr($code, 4)))->toBe(0)
        ->and($cart->discount_code)->toBe('SAVE10,FALL')
        ->and($cart->getPromotionCodes())->toBe(['SAVE10', 'FALL'])
        ->and($cart->setPromotionCodes([])->discount_code)->toBeNull()
        ->and($cart->getPromotionCodes())->toBe([]);
});

test('a promotion targeting a segment applies only to customers in it once the customer is known', function () {
    createCampaignSchema();
    seedPromotionStores();
    promotionDb()->table('contacts')->insert([
        ['uuid' => 'customer_in', 'public_id' => 'contact_in', 'company_uuid' => 'company_uuid', 'type' => 'customer', 'name' => 'In', 'created_at' => now(), 'updated_at' => now()],
        ['uuid' => 'customer_out', 'public_id' => 'contact_out', 'company_uuid' => 'company_uuid', 'type' => 'customer', 'name' => 'Out', 'created_at' => now(), 'updated_at' => now()],
    ]);
    $segment = tap(new Fleetbase\Storefront\Models\CustomerSegment())->forceFill(['uuid' => 'segment_uuid', 'company_uuid' => 'company_uuid', 'owner_uuid' => 'store_a_uuid', 'owner_type' => 'storefront:store', 'name' => 'VIPs', 'rules' => ['customers' => ['customer_in']]]);
    $segment->save();
    $promotion = makePromotion(['value' => 10, 'applies_to' => ['segment' => 'segment_uuid']]);
    $coded     = makePromotion(['value' => 20, 'trigger' => Promotion::TRIGGER_CODE, 'applies_to' => ['segment' => 'segment_uuid']]);
    makePromotionCode($coded, 'VIP20');

    $preview = promotionEngine()->evaluate(promotionContext());
    $member  = promotionEngine()->evaluate(promotionContext(['customer' => promotionCustomer('customer_in')]));
    $other   = promotionEngine()->evaluate(promotionContext(['customer' => promotionCustomer('customer_out')]), ['VIP20']);

    expect(array_column($preview->applied, 'promotion_uuid'))->toBe([$promotion->uuid])
        ->and(array_column($member->applied, 'promotion_uuid'))->toBe([$promotion->uuid])
        ->and($other->applied)->toBe([])
        ->and($other->rejected)->toBe([['code' => 'VIP20', 'reason' => PromotionEngine::REASON_NOT_IN_SEGMENT]]);
});

test('a promotion targeting a missing segment applies to nobody', function () {
    createCampaignSchema();
    seedPromotionStores();
    promotionDb()->table('contacts')->insert([['uuid' => 'customer_in', 'public_id' => 'contact_in', 'company_uuid' => 'company_uuid', 'type' => 'customer', 'name' => 'In', 'created_at' => now(), 'updated_at' => now()]]);
    makePromotion(['value' => 10, 'applies_to' => ['segment' => 'gone_segment']]);

    $result = promotionEngine()->evaluate(promotionContext(['customer' => promotionCustomer('customer_in')]));

    expect($result->applied)->toBe([]);
});
