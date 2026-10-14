<?php

require_once __DIR__ . '/../../Support/PromotionTestHelpers.php';

use Fleetbase\Storefront\Http\Controllers\v1\PromotionController;
use Fleetbase\Storefront\Http\Resources\Promotion as PromotionResource;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Promotion;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

// Monday 5 October 2026, 10:00 UTC.
const PROMO_MONDAY_10AM = '2026-10-05 10:00:00';

const WEEKDAY_AFTERNOONS = [['days' => [1, 2, 3, 4, 5], 'start' => '14:00', 'end' => '17:00']];

function publicPromotionRequest(array $input = [], bool $internal = false): Request
{
    $request = Request::create('/', 'GET', $input);
    $request->setLaravelSession(new Illuminate\Session\Store('promotion-details', new Illuminate\Session\ArraySessionHandler(120)));
    if ($internal) {
        $request->setRouteResolver(fn () => new class {
            public array $action = [];

            public function uri(): string
            {
                return 'int/v1/storefront/promotions';
            }
        });
    }
    app()->instance('request', $request);

    return $request;
}

beforeEach(function () {
    createPromotionSchema();
    seedPromotionStores();
    session(['storefront_key' => 'store_key_a', 'storefront_store' => 'store_a_uuid', 'storefront_network' => null, 'company' => 'company_uuid']);
    Carbon::setTestNow(Carbon::parse(PROMO_MONDAY_10AM, 'UTC'));
});

afterEach(function () {
    Carbon::setTestNow();
});

test('promotion availability distinguishes live, scheduled, ended and inactive promotions', function () {
    expect(makePromotion()->availabilityAt())->toBe('live')
        ->and(makePromotion(['schedule' => WEEKDAY_AFTERNOONS, 'timezone' => 'UTC'])->availabilityAt())->toBe('scheduled')
        ->and(makePromotion(['starts_at' => now()->addDay()])->availabilityAt())->toBe('scheduled')
        ->and(makePromotion(['status' => Promotion::STATUS_ENDED])->availabilityAt())->toBe('ended')
        ->and(makePromotion(['ends_at' => now()->subMinute()])->availabilityAt())->toBe('ended')
        ->and(makePromotion(['status' => Promotion::STATUS_DRAFT])->availabilityAt())->toBe('inactive')
        ->and(makePromotion(['status' => Promotion::STATUS_PAUSED])->availabilityAt(now()))->toBe('inactive');
});

test('scheduled promotions report when they next start', function () {
    $afternoons = makePromotion(['schedule' => WEEKDAY_AFTERNOONS, 'timezone' => 'UTC']);
    $friday     = Carbon::parse('2026-10-09 18:00:00', 'UTC');
    $starting   = makePromotion(['starts_at' => Carbon::parse('2026-10-20 09:00:00', 'UTC')]);
    $overnight  = makePromotion([
        'schedule'  => [['days' => [1], 'start' => '22:00', 'end' => '02:00']],
        'timezone'  => 'UTC',
        'starts_at' => Carbon::parse('2026-10-05 23:00:00', 'UTC'),
    ]);
    // Singapore is UTC+8: 14:00 local on Monday is 06:00 UTC, already passed at 10:00 UTC,
    // so the next start is Tuesday 06:00 UTC.
    $singapore = makePromotion(['schedule' => WEEKDAY_AFTERNOONS, 'timezone' => 'Asia/Singapore']);
    $endsFirst = makePromotion(['schedule' => WEEKDAY_AFTERNOONS, 'timezone' => 'UTC', 'ends_at' => Carbon::parse('2026-10-05 12:00:00', 'UTC')]);
    $noDays    = makePromotion(['schedule' => [['days' => [], 'start' => '14:00', 'end' => '17:00']], 'timezone' => 'UTC']);

    expect(makePromotion()->nextLiveAt())->toBeNull()
        ->and($afternoons->nextLiveAt()->toDateTimeString())->toBe('2026-10-05 14:00:00')
        ->and($afternoons->nextLiveAt($friday)->toDateTimeString())->toBe('2026-10-12 14:00:00')
        ->and($starting->nextLiveAt()->toDateTimeString())->toBe('2026-10-20 09:00:00')
        ->and($overnight->nextLiveAt()->toDateTimeString())->toBe('2026-10-05 23:00:00')
        ->and($singapore->nextLiveAt()->toDateTimeString())->toBe('2026-10-06 06:00:00')
        ->and($endsFirst->nextLiveAt())->toBeNull()
        ->and($noDays->nextLiveAt())->toBeNull();
});

test('only one active, unassigned, unexpired, reusable code is shared publicly', function () {
    $automatic = makePromotion();
    $coded     = makePromotion(['trigger' => Promotion::TRIGGER_CODE]);
    makePromotionCode($coded, 'SINGLE', ['usage_limit' => 1]);
    makePromotionCode($coded, 'MINE', ['customer_uuid' => 'customer_uuid']);
    makePromotionCode($coded, 'OLDCODE', ['expires_at' => now()->subDay()]);
    makePromotionCode($coded, 'OFF', ['status' => 'disabled']);
    makePromotionCode($coded, 'BLOOM10', ['usage_limit' => 100, 'created_at' => now()->subDay()]);
    makePromotionCode($coded, 'BLOOMNEW', ['usage_limit' => null, 'created_at' => now()]);
    $batchOnly = makePromotion(['trigger' => Promotion::TRIGGER_CODE]);
    makePromotionCode($batchOnly, 'BATCH1', ['usage_limit' => 1]);

    expect($automatic->shareableCode())->toBeNull()
        ->and($coded->shareableCode())->toBe('BLOOMNEW')
        ->and($coded->load('codes')->shareableCode())->toBe('BLOOMNEW')
        ->and($batchOnly->shareableCode())->toBeNull();
});

test('promotions describe their owner and name their targets by public id', function () {
    $schema = promotionDb()->getSchemaBuilder();
    $schema->dropIfExists('categories');
    $schema->create('categories', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    promotionDb()->table('categories')->insert(['uuid' => 'category_uuid', 'public_id' => 'category_flowers']);
    promotionDb()->table('products')->insert(['uuid' => 'product_1_uuid', 'public_id' => 'product_1', 'store_uuid' => 'store_a_uuid']);

    $storeOwned   = makePromotion(['applies_to' => [
        'products'           => ['product_1_uuid', 'product_gone'],
        'exclude_products'   => [],
        'stores'             => ['store_b'],
        'categories'         => ['category_uuid', 42],
        'exclude_categories' => ['category_missing'],
    ]]);
    $networkOwned = makePromotion(['owner_uuid' => 'network_uuid', 'owner_type' => Network::class]);
    $orphaned     = makePromotion(['owner_uuid' => 'missing_store']);

    expect($storeOwned->ownerSummary())->toBe(['type' => 'store', 'id' => 'store_a', 'name' => 'Store A', 'logo_url' => null])
        ->and($networkOwned->ownerSummary())->toBe(['type' => 'network', 'id' => 'network_m', 'name' => 'Market', 'logo_url' => null])
        ->and($orphaned->ownerSummary())->toBeNull()
        ->and($storeOwned->publicAppliesTo())->toBe([
            'products'           => ['product_1'],
            'stores'             => ['store_b'],
            'categories'         => ['category_flowers'],
            'exclude_categories' => [],
        ])
        ->and($networkOwned->publicAppliesTo())->toBe([]);
});

test('public promotion listings can include scheduled promotions and details open for scheduled and ended ones', function () {
    $live       = makePromotion(['name' => 'Live']);
    $scheduled  = makePromotion(['name' => 'Afternoons', 'schedule' => WEEKDAY_AFTERNOONS, 'timezone' => 'UTC']);
    $ended      = makePromotion(['name' => 'Ended', 'status' => Promotion::STATUS_ENDED]);
    $paused     = makePromotion(['name' => 'Paused', 'status' => Promotion::STATUS_PAUSED]);
    $draft      = makePromotion(['name' => 'Draft', 'status' => Promotion::STATUS_DRAFT]);
    $controller = new PromotionController();

    $default   = collect($controller->query(publicPromotionRequest())->resolve())->pluck('name')->all();
    $withLater = collect($controller->query(publicPromotionRequest(['include' => 'scheduled']))->resolve());

    expect($default)->toBe(['Live'])
        ->and($withLater->pluck('name')->all())->toBe(['Live', 'Afternoons'])
        ->and($withLater->firstWhere('name', 'Afternoons')['availability'])->toBe('scheduled')
        ->and($withLater->firstWhere('name', 'Afternoons')['next_starts_at']->toDateTimeString())->toBe('2026-10-05 14:00:00')
        ->and($controller->find($scheduled->public_id)->resolve()['availability'])->toBe('scheduled')
        ->and($controller->find($ended->public_id)->resolve()['availability'])->toBe('ended')
        ->and($controller->find($live->public_id)->resolve()['availability'])->toBe('live')
        ->and($controller->find($paused->public_id)->getStatusCode())->toBe(404)
        ->and($controller->find($draft->public_id)->getStatusCode())->toBe(404);
});

test('public promotion resources add owner and shareable code, internal ones keep raw targets', function () {
    $promotion = makePromotion(['trigger' => Promotion::TRIGGER_CODE, 'applies_to' => ['stores' => ['store_a_uuid']]]);
    makePromotionCode($promotion, 'SHARE', ['usage_limit' => null]);

    $public   = (new PromotionResource($promotion))->resolve(publicPromotionRequest());
    $internal = (new PromotionResource($promotion))->resolve(publicPromotionRequest([], true));

    expect($public['owner'])->toBe(['type' => 'store', 'id' => 'store_a', 'name' => 'Store A', 'logo_url' => null])
        ->and($public['code'])->toBe('SHARE')
        ->and($public['availability'])->toBe('live')
        ->and($public['next_starts_at'])->toBeNull()
        ->and($public['applies_to'])->toBe(['stores' => ['store_a']])
        ->and($internal)->not->toHaveKey('owner')
        ->and($internal)->not->toHaveKey('code')
        ->and($internal['applies_to'])->toBe(['stores' => ['store_a_uuid']]);
});
