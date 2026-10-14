<?php

require_once __DIR__ . '/../../Support/PromotionTestHelpers.php';

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Storefront\Http\Controllers\PromotionController as InternalPromotionController;
use Fleetbase\Storefront\Http\Controllers\PromotionRedemptionController;
use Fleetbase\Storefront\Http\Filter\PromotionRedemptionFilter;
use Fleetbase\Storefront\Http\Resources\PromotionRedemption as PromotionRedemptionResource;
use Fleetbase\Storefront\Models\CustomerSegment;
use Fleetbase\Storefront\Models\Promotion;
use Fleetbase\Storefront\Models\PromotionRedemption;
use Fleetbase\Storefront\Promotions\PromotionCalculator;
use Fleetbase\Storefront\Promotions\PromotionEngine;
use Fleetbase\Storefront\Promotions\PromotionRedemptions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

function promotionCoverageRequest(array $input = []): Request
{
    $request = Request::create('/', 'GET', $input);
    $request->setLaravelSession(new Illuminate\Session\Store('promotion-coverage', new Illuminate\Session\ArraySessionHandler(120)));
    app()->instance('request', $request);

    return $request;
}

function promotionCoverageRedemption(array $attributes): void
{
    promotionDb()->table('promotion_redemptions')->insert(array_merge([
        'company_uuid' => 'company_uuid',
        'amount'       => 0,
        'currency'     => 'USD',
        'status'       => PromotionRedemption::STATUS_REDEEMED,
        'created_at'   => now(),
        'updated_at'   => now(),
    ], $attributes));
}

/**
 * Apply a redemption filter the way the internal API does, without its request plumbing.
 */
function promotionCoverageFilter(array $calls): array
{
    $builder = PromotionRedemption::query();
    $filter  = (new ReflectionClass(PromotionRedemptionFilter::class))->newInstanceWithoutConstructor();
    $session = new Illuminate\Session\Store('filter', new Illuminate\Session\ArraySessionHandler(1));
    $session->put('company', 'company_uuid');
    (new ReflectionProperty(Fleetbase\Http\Filter\Filter::class, 'builder'))->setValue($filter, $builder);
    (new ReflectionProperty(Fleetbase\Http\Filter\Filter::class, 'session'))->setValue($filter, $session);

    $filter->queryForInternal();
    foreach ($calls as $method => $argument) {
        $filter->{$method}($argument);
    }

    return $builder->orderBy('uuid')->pluck('uuid')->all();
}

beforeEach(function () {
    createPromotionSchema();
    createCampaignSchema();
    session(['company' => 'company_uuid', 'user' => 'user_uuid']);
});

afterEach(function () {
    Carbon::setTestNow();
    session(['company' => null, 'user' => null]);
});

test('the promotions hub counts promotions by status, campaigns, segments and redeemed discounts', function () {
    Carbon::setTestNow('2026-10-14 12:00:00');
    $active    = makePromotion(['status' => Promotion::STATUS_ACTIVE, 'owner_uuid' => 'store_a_uuid']);
    $scheduled = makePromotion(['status' => Promotion::STATUS_ACTIVE, 'owner_uuid' => 'store_a_uuid', 'starts_at' => '2026-10-20 00:00:00']);
    makePromotion(['status' => Promotion::STATUS_ACTIVE, 'owner_uuid' => 'store_a_uuid', 'starts_at' => '2026-10-01 00:00:00']);
    makePromotion(['status' => Promotion::STATUS_PAUSED, 'owner_uuid' => 'store_a_uuid']);
    makePromotion(['status' => Promotion::STATUS_ENDED, 'owner_uuid' => 'store_a_uuid']);
    makePromotion(['status' => Promotion::STATUS_DRAFT, 'owner_uuid' => 'store_a_uuid']);
    $otherStore = makePromotion(['status' => Promotion::STATUS_ACTIVE, 'owner_uuid' => 'store_b_uuid']);
    $foreign    = makePromotion(['status' => Promotion::STATUS_ACTIVE, 'owner_uuid' => 'store_a_uuid', 'company_uuid' => 'other_company']);

    foreach ([['store_a_uuid', 'company_uuid'], ['store_a_uuid', 'company_uuid'], ['store_b_uuid', 'company_uuid'], ['store_a_uuid', 'other_company']] as $i => [$owner, $company]) {
        promotionDb()->table('campaigns')->insert(['uuid' => 'campaign_' . $i, 'company_uuid' => $company, 'owner_uuid' => $owner, 'name' => 'Campaign ' . $i]);
        promotionDb()->table('customer_segments')->insert(['uuid' => 'segment_' . $i, 'company_uuid' => $company, 'owner_uuid' => $owner, 'name' => 'Segment ' . $i]);
    }
    promotionDb()->table('customer_segments')->where('uuid', 'segment_1')->delete();

    promotionCoverageRedemption(['uuid' => 'r_active', 'promotion_uuid' => $active->uuid, 'amount' => 500]);
    promotionCoverageRedemption(['uuid' => 'r_scheduled', 'promotion_uuid' => $scheduled->uuid, 'amount' => 250]);
    // Neither a reservation nor a released use, another store's or another company's use counts.
    promotionCoverageRedemption(['uuid' => 'r_reserved', 'promotion_uuid' => $active->uuid, 'amount' => 900, 'status' => PromotionRedemption::STATUS_RESERVED]);
    promotionCoverageRedemption(['uuid' => 'r_released', 'promotion_uuid' => $active->uuid, 'amount' => 900, 'status' => PromotionRedemption::STATUS_RELEASED]);
    promotionCoverageRedemption(['uuid' => 'r_other_store', 'promotion_uuid' => $otherStore->uuid, 'amount' => 70]);
    promotionCoverageRedemption(['uuid' => 'r_foreign', 'promotion_uuid' => $foreign->uuid, 'amount' => 900, 'company_uuid' => 'other_company']);

    $controller = new InternalPromotionController();
    $store      = $controller->hub(promotionCoverageRequest(['owner' => 'store_a_uuid']))->getData(true);
    $company    = $controller->hub(promotionCoverageRequest())->getData(true);

    expect($store)->toBe([
        'promotions' => [
            'total'     => 6,
            // Three are active, but one has not started yet and counts as scheduled.
            'active'    => 2,
            'scheduled' => 1,
            'paused'    => 1,
            'ended'     => 1,
            'draft'     => 1,
        ],
        'campaigns'   => 2,
        'segments'    => 1,
        'redemptions' => ['total' => 2, 'amount' => 750],
    ])
        ->and($company['promotions']['total'])->toBe(7)
        ->and($company['promotions']['active'])->toBe(3)
        ->and($company['campaigns'])->toBe(3)
        ->and($company['segments'])->toBe(2)
        ->and($company['redemptions'])->toBe(['total' => 3, 'amount' => 820]);
});

test('the promotions hub is all zeros for an owner without promotions', function () {
    makePromotion(['owner_uuid' => 'store_a_uuid']);

    $response = (new InternalPromotionController())->hub(promotionCoverageRequest(['owner' => 'store_nobody']))->getData(true);

    expect($response)->toBe([
        'promotions'  => ['total' => 0, 'active' => 0, 'scheduled' => 0, 'paused' => 0, 'ended' => 0, 'draft' => 0],
        'campaigns'   => 0,
        'segments'    => 0,
        'redemptions' => ['total' => 0, 'amount' => 0],
    ]);
});

test('promotion redemptions load their customer and order and serialize for the console', function () {
    $promotion = makePromotion(['name' => 'Fall Sale', 'type' => Promotion::TYPE_FIXED_AMOUNT]);
    $code      = makePromotionCode($promotion, 'FALL10');
    promotionDb()->table('contacts')->insert(['uuid' => 'customer_uuid', 'public_id' => 'contact_ada', 'company_uuid' => 'company_uuid', 'type' => 'customer', 'name' => 'Ada Buyer']);
    promotionDb()->table('orders')->insert(['uuid' => 'order_uuid', 'public_id' => 'order_ada', 'customer_uuid' => 'customer_uuid', 'status' => 'completed']);
    promotionCoverageRedemption([
        'uuid'                => 'redemption_uuid',
        'promotion_uuid'      => $promotion->uuid,
        'promotion_code_uuid' => $code->uuid,
        'customer_uuid'       => 'customer_uuid',
        'checkout_uuid'       => 'checkout_uuid',
        'order_uuid'          => 'order_uuid',
        'amount'              => 1000,
        'redeemed_at'         => '2026-10-01 09:30:00',
    ]);
    promotionCoverageRedemption(['uuid' => 'redemption_bare', 'promotion_uuid' => 'promotion_gone', 'amount' => 0, 'status' => PromotionRedemption::STATUS_RESERVED]);

    $redemption = PromotionRedemption::where('uuid', 'redemption_uuid')->first();
    $data       = (new PromotionRedemptionResource($redemption))->resolve(promotionCoverageRequest());
    $bare       = (new PromotionRedemptionResource(PromotionRedemption::where('uuid', 'redemption_bare')->first()))->resolve(promotionCoverageRequest());

    expect($redemption->customer)->toBeInstanceOf(Contact::class)
        ->and($redemption->order)->toBeInstanceOf(Order::class)
        ->and($redemption->customer()->getForeignKeyName())->toBe('customer_uuid')
        ->and($redemption->order()->getForeignKeyName())->toBe('order_uuid')
        ->and($data)->toMatchArray([
            'id'                  => 'redemption_uuid',
            'uuid'                => 'redemption_uuid',
            'promotion_uuid'      => $promotion->uuid,
            'promotion_code_uuid' => $code->uuid,
            'customer_uuid'       => 'customer_uuid',
            'checkout_uuid'       => 'checkout_uuid',
            'order_uuid'          => 'order_uuid',
            'promotion_name'      => 'Fall Sale',
            'promotion_type'      => Promotion::TYPE_FIXED_AMOUNT,
            'code'                => 'FALL10',
            'customer_name'       => 'Ada Buyer',
            'customer_public_id'  => 'contact_ada',
            'order_public_id'     => 'order_ada',
            'order_status'        => 'completed',
            'amount'              => 1000,
            'currency'            => 'USD',
            'status'              => PromotionRedemption::STATUS_REDEEMED,
        ])
        ->and($data['redeemed_at'])->toBeInstanceOf(Carbon::class)
        ->and($data['redeemed_at']->toDateTimeString())->toBe('2026-10-01 09:30:00')
        ->and($data)->toHaveKeys(['created_at', 'updated_at'])
        // A redemption whose promotion, code, customer and order are gone still serializes.
        ->and($bare)->toMatchArray([
            'promotion_name'     => null,
            'promotion_type'     => null,
            'code'               => null,
            'customer_name'      => null,
            'customer_public_id' => null,
            'order_public_id'    => null,
            'order_status'       => null,
            'status'             => PromotionRedemption::STATUS_RESERVED,
            'redeemed_at'        => null,
        ]);
});

test('the redemptions screen eager loads the promotion, code, customer and order', function () {
    $builder = PromotionRedemption::query();

    (new PromotionRedemptionController())->onQueryRecord($builder);

    expect(array_keys($builder->getEagerLoads()))->toBe(['promotion', 'code', 'customer', 'order']);
});

test('promotion redemption filters scope to the company and narrow by promotion, customer, status, owner and search', function () {
    $fall   = makePromotion(['name' => 'Fall Sale', 'owner_uuid' => 'store_a_uuid']);
    $winter = makePromotion(['name' => 'Winter Deals', 'owner_uuid' => 'store_b_uuid']);
    $code   = makePromotionCode($winter, 'SNOW25');
    promotionCoverageRedemption(['uuid' => 'r1', 'promotion_uuid' => $fall->uuid, 'customer_uuid' => 'customer_ada']);
    promotionCoverageRedemption(['uuid' => 'r2', 'promotion_uuid' => $fall->uuid, 'customer_uuid' => 'customer_bo', 'status' => PromotionRedemption::STATUS_RESERVED]);
    promotionCoverageRedemption(['uuid' => 'r3', 'promotion_uuid' => $winter->uuid, 'promotion_code_uuid' => $code->uuid, 'customer_uuid' => 'customer_ada']);
    promotionCoverageRedemption(['uuid' => 'r4', 'promotion_uuid' => $fall->uuid, 'customer_uuid' => 'customer_ada', 'company_uuid' => 'other_company']);

    expect(promotionCoverageFilter([]))->toBe(['r1', 'r2', 'r3'])
        ->and(promotionCoverageFilter(['promotion' => $fall->uuid]))->toBe(['r1', 'r2'])
        ->and(promotionCoverageFilter(['customer' => 'customer_ada']))->toBe(['r1', 'r3'])
        ->and(promotionCoverageFilter(['status' => PromotionRedemption::STATUS_RESERVED]))->toBe(['r2'])
        ->and(promotionCoverageFilter(['owner' => 'store_b_uuid']))->toBe(['r3'])
        // Search matches the promotion name or the code that was entered.
        ->and(promotionCoverageFilter(['query' => 'fall']))->toBe(['r1', 'r2'])
        ->and(promotionCoverageFilter(['query' => 'snow']))->toBe(['r3'])
        ->and(promotionCoverageFilter(['query' => 'nothing like it']))->toBe([])
        // An empty search leaves the list alone.
        ->and(promotionCoverageFilter(['query' => '']))->toBe(['r1', 'r2', 'r3'])
        ->and(promotionCoverageFilter(['query' => null]))->toBe(['r1', 'r2', 'r3']);
});

test('canceling an order gives back its reserved and redeemed promotion uses', function () {
    $promotion = makePromotion();
    promotionCoverageRedemption(['uuid' => 'held', 'promotion_uuid' => $promotion->uuid, 'order_uuid' => 'order_canceled', 'status' => PromotionRedemption::STATUS_RESERVED]);
    promotionCoverageRedemption(['uuid' => 'used', 'promotion_uuid' => $promotion->uuid, 'order_uuid' => 'order_canceled', 'status' => PromotionRedemption::STATUS_REDEEMED]);
    promotionCoverageRedemption(['uuid' => 'already', 'promotion_uuid' => $promotion->uuid, 'order_uuid' => 'order_canceled', 'status' => PromotionRedemption::STATUS_RELEASED]);
    promotionCoverageRedemption(['uuid' => 'other', 'promotion_uuid' => $promotion->uuid, 'order_uuid' => 'order_kept', 'status' => PromotionRedemption::STATUS_REDEEMED]);

    $released = PromotionRedemptions::releaseForOrder((new Order())->forceFill(['uuid' => 'order_canceled']));
    $again    = PromotionRedemptions::releaseForOrder((new Order())->forceFill(['uuid' => 'order_canceled']));

    expect($released)->toBe(2)
        ->and($again)->toBe(0)
        ->and(promotionDb()->table('promotion_redemptions')->orderBy('uuid')->pluck('status', 'uuid')->all())->toBe([
            'already' => PromotionRedemption::STATUS_RELEASED,
            'held'    => PromotionRedemption::STATUS_RELEASED,
            'other'   => PromotionRedemption::STATUS_REDEEMED,
            'used'    => PromotionRedemption::STATUS_RELEASED,
        ])
        ->and(PromotionRedemption::query()->counting()->pluck('uuid')->all())->toBe(['other']);
});

test('a segment promotion whose segment and promotion owners are both gone applies to nobody', function () {
    // No stores are seeded: the segment's owner and the promotion's owner (store A) do not resolve.
    promotionDb()->table('contacts')->insert(['uuid' => 'customer_in', 'public_id' => 'contact_in', 'company_uuid' => 'company_uuid', 'type' => 'customer', 'name' => 'In', 'created_at' => now(), 'updated_at' => now()]);
    $segment = tap(new CustomerSegment())->forceFill(['uuid' => 'segment_uuid', 'public_id' => 'segment_vips', 'company_uuid' => 'company_uuid', 'owner_uuid' => 'store_deleted_uuid', 'owner_type' => 'storefront:store', 'name' => 'VIPs', 'rules' => ['customers' => ['customer_in']]]);
    $segment->save();
    $coded = makePromotion(['value' => 20, 'trigger' => Promotion::TRIGGER_CODE, 'applies_to' => ['segment' => 'segment_vips']]);
    makePromotionCode($coded, 'VIP20');
    $engine   = new PromotionEngine(new PromotionCalculator());
    $customer = tap(new Contact())->forceFill(['uuid' => 'customer_in']);

    $orphaned = $engine->evaluate(promotionContext(['customer' => $customer]), ['VIP20']);

    // Once the promotion's store exists again, it stands in for the missing segment owner.
    seedPromotionStores();
    $restored = $engine->evaluate(promotionContext(['customer' => $customer]), ['VIP20']);

    expect($orphaned->applied)->toBe([])
        ->and($orphaned->rejected)->toBe([['code' => 'VIP20', 'reason' => PromotionEngine::REASON_NOT_IN_SEGMENT]])
        ->and(array_column($restored->applied, 'promotion_uuid'))->toBe([$coded->uuid])
        ->and($restored->rejected)->toBe([]);
});
