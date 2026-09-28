<?php

require_once __DIR__ . '/../../Support/PromotionTestHelpers.php';

use Fleetbase\Storefront\Http\Controllers\PromotionController as InternalPromotionController;
use Fleetbase\Storefront\Http\Controllers\v1\CartController;
use Fleetbase\Storefront\Http\Controllers\v1\PromotionController;
use Fleetbase\Storefront\Http\Filter\PromotionCodeFilter;
use Fleetbase\Storefront\Http\Filter\PromotionFilter;
use Fleetbase\Storefront\Http\Requests\PromotionRequest;
use Fleetbase\Storefront\Http\Resources\Promotion as PromotionResource;
use Fleetbase\Storefront\Http\Resources\PromotionCode as PromotionCodeResource;
use Fleetbase\Storefront\Models\Promotion;
use Fleetbase\Storefront\Models\PromotionCode;
use Fleetbase\Storefront\Support\Storefront;
use Illuminate\Http\Request;

function promotionRequest(array $input = [], string $method = 'GET'): Request
{
    $request = Request::create('/', $method, $input);
    $request->setLaravelSession(new Illuminate\Session\Store('promotions', new Illuminate\Session\ArraySessionHandler(120)));
    app()->instance('request', $request);

    return $request;
}

function seedPromotionCart(?string $codes = null): void
{
    $schema = promotionDb()->getSchemaBuilder();
    $schema->dropIfExists('carts');
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
    promotionDb()->table('carts')->insert([
        'uuid'              => 'cart_uuid',
        'public_id'         => 'cart_public',
        'unique_identifier' => 'cart_public',
        'currency'          => 'USD',
        'discount_code'     => $codes,
        'items'             => json_encode([['id' => 'line_1', 'product_id' => 'product_unlisted', 'store_id' => 'store_a', 'quantity' => 1, 'subtotal' => 10000]]),
        'events'            => '[]',
        'expires_at'        => now()->addDay(),
    ]);
    $schema->dropIfExists('service_quotes');
    $schema->create('service_quotes', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->integer('amount')->nullable();
        $table->timestamp('expired_at')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    promotionDb()->table('service_quotes')->insert(['uuid' => 'quote_uuid', 'public_id' => 'quote_public', 'amount' => 500]);
}

function cartController(): CartController
{
    return new class extends CartController {
        protected function retrieveCart(?string $uniqueId): Fleetbase\Storefront\Models\Cart
        {
            return Fleetbase\Storefront\Models\Cart::where('public_id', $uniqueId)->firstOrFail();
        }
    };
}

beforeEach(function () {
    createPromotionSchema();
    seedPromotionStores();
    promotionDb()->table('products')->insert(['uuid' => 'product_1_uuid', 'public_id' => 'product_1', 'store_uuid' => 'store_a_uuid']);
    session(['storefront_key' => 'store_key_a', 'storefront_store' => 'store_a_uuid', 'storefront_network' => null, 'company' => 'company_uuid']);
});

test('customers apply, preview and remove promotion codes on a cart', function () {
    seedPromotionCart();
    $coded = makePromotion(['trigger' => Promotion::TRIGGER_CODE, 'value' => 20]);
    makePromotionCode($coded, 'TWENTY');
    makePromotion(['type' => Promotion::TYPE_FREE_DELIVERY, 'stackable' => true]);

    $invalid = cartController()->applyPromotionCode('cart_public', promotionRequest(['code' => 'nope'], 'POST'));
    $empty   = cartController()->applyPromotionCode('cart_public', promotionRequest([], 'POST'));
    $applied = cartController()->applyPromotionCode('cart_public', promotionRequest(['code' => ' twenty ', 'service_quote' => 'quote_public'], 'POST'))->getData(true);

    expect($invalid->getData(true))->toBe(['error' => 'Promotion code "NOPE" cannot be applied (invalid_code).'])
        ->and($empty->getData(true))->toBe(['error' => 'A promotion code is required.'])
        ->and($applied['promotions']['discount'])->toBe(2000)
        ->and(array_column($applied['promotions']['applied'], 'code'))->toBe(['TWENTY'])
        ->and(promotionDb()->table('carts')->value('discount_code'))->toBe('TWENTY');

    $preview       = cartController()->promotions('cart_public', promotionRequest(['service_quote' => 'quote_public']))->getData(true);
    $pickupPreview = cartController()->promotions('cart_public', promotionRequest(['pickup' => true]))->getData(true);
    $removed       = cartController()->removePromotionCode('cart_public', 'twenty', promotionRequest(['service_quote' => 'quote_public'], 'DELETE'))->getData(true);

    expect($preview['discount'])->toBe(2000)
        ->and($pickupPreview['discount_delivery'])->toBe(0)
        ->and($removed['promotions']['applied'])->toHaveCount(1)
        ->and($removed['promotions']['discount'])->toBe(500)
        ->and(promotionDb()->table('carts')->value('discount_code'))->toBeNull();
});

test('codes that only lose to a better promotion stay on the cart', function () {
    seedPromotionCart();
    $coded = makePromotion(['trigger' => Promotion::TRIGGER_CODE, 'value' => 5]);
    makePromotionCode($coded, 'FIVE');
    makePromotion(['value' => 30]);

    $response = cartController()->applyPromotionCode('cart_public', promotionRequest(['code' => 'FIVE'], 'POST'))->getData(true);

    expect($response['promotions']['rejected'])->toBe([['code' => 'FIVE', 'reason' => 'not_combinable']])
        ->and(promotionDb()->table('carts')->value('discount_code'))->toBe('FIVE');
});

test('the storefront lists its live public promotions, and network apps include member stores', function () {
    $live    = makePromotion(['name' => 'Live', 'priority' => 1]);
    $network = makePromotion(['name' => 'Network', 'owner_uuid' => 'network_uuid', 'owner_type' => Fleetbase\Storefront\Models\Network::class]);
    $storeB  = makePromotion(['name' => 'Store B', 'owner_uuid' => 'store_b_uuid']);
    makePromotion(['name' => 'Hidden', 'is_public' => false]);
    makePromotion(['name' => 'Draft', 'status' => Promotion::STATUS_DRAFT]);
    makePromotion(['name' => 'Expired', 'ends_at' => now()->subDay()]);
    promotionRequest();
    $controller = new PromotionController();

    expect(collect($controller->query(promotionRequest())->resolve())->pluck('name')->all())->toBe(['Live'])
        ->and($controller->find($live->public_id)->resolve()['name'])->toBe('Live')
        ->and($controller->find($storeB->public_id)->getStatusCode())->toBe(404);

    session(['storefront_key' => 'network_key', 'storefront_store' => null, 'storefront_network' => 'network_uuid']);

    expect(collect($controller->query(promotionRequest())->resolve())->pluck('name')->sort()->values()->all())->toBe(['Live', 'Network', 'Store B'])
        ->and(collect($controller->query(promotionRequest(['store' => 'store_b']))->resolve())->pluck('name')->all())->toBe(['Store B'])
        ->and($controller->find($network->public_id)->resolve()['id'])->toBe($network->public_id);
});

test('admins generate unique promotion codes', function () {
    $promotion  = makePromotion(['trigger' => Promotion::TRIGGER_AUTOMATIC]);
    $controller = new InternalPromotionController();

    $batch = $controller->generateCodes($promotion->uuid, promotionRequest(['count' => 3, 'length' => 6, 'prefix' => 'fall-', 'usage_limit' => 1], 'POST'))->getData(true);
    $named = $controller->generateCodes($promotion->public_id, promotionRequest(['code' => 'welcome'], 'POST'))->getData(true);
    $dupe  = $controller->generateCodes($promotion->uuid, promotionRequest(['code' => 'WELCOME'], 'POST'));
    $none  = $controller->generateCodes('missing', promotionRequest([], 'POST'));

    expect($batch['codes'])->toHaveCount(3)
        ->and(collect($batch['codes'])->every(fn ($code) => str_starts_with($code['code'], 'FALL-') && strlen($code['code']) === 11 && $code['usage_limit'] === 1))->toBeTrue()
        ->and(array_column($named['codes'], 'code'))->toBe(['WELCOME'])
        ->and($dupe->getStatusCode())->toBe(400)
        ->and($none->getStatusCode())->toBe(404)
        ->and(PromotionCode::query()->count())->toBe(4)
        ->and(promotionDb()->table('promotions')->where('uuid', $promotion->uuid)->value('trigger'))->toBe(Promotion::TRIGGER_CODE);
});

test('code generation skips collisions and gives up after too many attempts', function () {
    $promotion = makePromotion(['trigger' => Promotion::TRIGGER_CODE]);
    makePromotionCode($promotion, 'TAKEN');
    $controller = new class extends InternalPromotionController {
        public array $sequence = ['TAKEN', 'FRESH1', 'FRESH1', 'FRESH2'];

        protected function generateCode(int $length, string $prefix): string
        {
            return array_shift($this->sequence) ?? 'TAKEN';
        }
    };

    $generated = $controller->generateCodes($promotion->uuid, promotionRequest(['count' => 2], 'POST'))->getData(true);
    $exhausted = $controller->generateCodes($promotion->uuid, promotionRequest(['count' => 1], 'POST'))->getData(true);

    expect(array_column($generated['codes'], 'code'))->toBe(['FRESH1', 'FRESH2'])
        ->and($exhausted['codes'])->toBe([]);
});

test('promotion validation requires an owner from the company and valid discount settings', function () {
    session(['company' => 'company_uuid']);
    $request  = PromotionRequest::createFrom(promotionRequest([], 'POST'));
    $rules    = $request->rules();
    $owner    = $rules['owner_uuid'][2];
    $failures = [];

    $owner('owner_uuid', 'store_a_uuid', function ($message) use (&$failures) {
        $failures[] = $message;
    });
    $owner('owner_uuid', 'network_uuid', function ($message) use (&$failures) {
        $failures[] = $message;
    });
    $owner('owner_uuid', 'someone_elses_store', function ($message) use (&$failures) {
        $failures[] = $message;
    });

    $endsAt       = PromotionRequest::createFrom(promotionRequest(['promotion' => ['starts_at' => '2026-10-01 00:00:00']], 'POST'))->rules()['ends_at'][2];
    $dateFailures = [];
    $collect      = function ($message) use (&$dateFailures) {
        $dateFailures[] = $message;
    };
    $endsAt('ends_at', '2026-11-01 00:00:00', $collect);
    $endsAt('ends_at', '2026-09-01 00:00:00', $collect);
    $endsAt('ends_at', null, $collect);
    PromotionRequest::createFrom(promotionRequest([], 'POST'))->rules()['ends_at'][2]('ends_at', '2026-09-01 00:00:00', $collect);

    expect($dateFailures)->toBe(['The end date must be after the start date.']);

    expect($rules['name'][0])->toBe('required')
        ->and($rules['type'][1])->toBe('in:percentage,fixed_amount,free_delivery,bogo')
        ->and($rules['value'][3])->toBe('required_if:type,percentage,fixed_amount')
        ->and($failures)->toBe(['The promotion must belong to one of your stores or networks.'])
        ->and(PromotionRequest::createFrom(promotionRequest([], 'PUT'))->rules()['name'][0])->toBe('sometimes')
        ->and($request->authorize())->toBeFalse();
});

test('promotion filters scope to the company and support owner, status, type and promotion', function () {
    makePromotion(['name' => 'A', 'status' => Promotion::STATUS_ACTIVE]);
    $other = makePromotion(['name' => 'B', 'owner_uuid' => 'store_b_uuid', 'type' => Promotion::TYPE_BOGO, 'status' => Promotion::STATUS_PAUSED]);
    makePromotion(['name' => 'C', 'company_uuid' => 'other_company']);
    makePromotionCode($other, 'BCODE');

    $filter = function (string $class, $builder, array $calls) {
        $instance = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $session  = new Illuminate\Session\Store('filter', new Illuminate\Session\ArraySessionHandler(1));
        $session->put('company', 'company_uuid');
        foreach (['builder' => $builder, 'session' => $session] as $property => $value) {
            $reflection = new ReflectionProperty(Fleetbase\Http\Filter\Filter::class, $property);
            $reflection->setValue($instance, $value);
        }
        $instance->queryForInternal();
        foreach ($calls as $method => $argument) {
            $instance->{$method}($argument);
        }

        return $builder->pluck('name')->all();
    };

    expect($filter(PromotionFilter::class, Promotion::query(), []))->toBe(['A', 'B'])
        ->and($filter(PromotionFilter::class, Promotion::query(), ['owner' => 'store_b_uuid']))->toBe(['B'])
        ->and($filter(PromotionFilter::class, Promotion::query(), ['status' => 'active']))->toBe(['A'])
        ->and($filter(PromotionFilter::class, Promotion::query(), ['type' => Promotion::TYPE_BOGO]))->toBe(['B'])
        ->and(PromotionCode::query()->count())->toBe(1);

    $codes   = (new ReflectionClass(PromotionCodeFilter::class))->newInstanceWithoutConstructor();
    $builder = PromotionCode::query();
    (new ReflectionProperty(Fleetbase\Http\Filter\Filter::class, 'builder'))->setValue($codes, $builder);
    $session = new Illuminate\Session\Store('filter', new Illuminate\Session\ArraySessionHandler(1));
    $session->put('company', 'company_uuid');
    (new ReflectionProperty(Fleetbase\Http\Filter\Filter::class, 'session'))->setValue($codes, $session);
    $codes->queryForInternal();
    $codes->promotion($other->uuid);

    expect($builder->pluck('code')->all())->toBe(['BCODE']);
});

test('promotion resources expose usage stats internally and hide internals publicly', function () {
    $promotion = makePromotion(['name' => 'Stats', 'usage_limit' => 10, 'budget_amount' => 5000]);
    $code      = makePromotionCode($promotion, 'STATS');
    promotionDb()->table('promotion_redemptions')->insert([
        ['uuid' => 'r1', 'promotion_uuid' => $promotion->uuid, 'promotion_code_uuid' => $code->uuid, 'amount' => 300, 'status' => 'redeemed', 'created_at' => now()],
        ['uuid' => 'r2', 'promotion_uuid' => $promotion->uuid, 'promotion_code_uuid' => $code->uuid, 'amount' => 200, 'status' => 'reserved', 'created_at' => now()],
    ]);

    $public = (new PromotionResource($promotion))->resolve(promotionRequest());

    expect($public['id'])->toBe($promotion->public_id)
        ->and($public)->not->toHaveKey('budget_amount')
        ->and((new PromotionCodeResource($code))->resolve(promotionRequest()))->toMatchArray(['id' => $code->public_id, 'code' => 'STATS', 'times_used' => 2]);

    $internal = promotionRequest();
    $internal->setRouteResolver(fn () => new class {
        public array $action = [];

        public function uri(): string
        {
            return 'int/v1/storefront/promotions';
        }
    });
    $resource = (new PromotionResource($promotion))->resolve($internal);

    expect($resource['id'])->toBe($promotion->uuid)
        ->and($resource['stats'])->toBe(['redemptions' => 1, 'discount_given' => 300])
        ->and($resource['budget_amount'])->toBe(5000);
});

test('storefront lookup returns null for an unknown storefront key', function () {
    session(['storefront_key' => 'store_missing']);

    expect(Storefront::about())->toBeNull();
});

test('promotion models expose their owner, codes and redemptions', function () {
    $promotion = makePromotion(['owner_uuid' => 'store_a_uuid']);
    $code      = makePromotionCode($promotion, 'REL');
    promotionDb()->table('promotion_redemptions')->insert(['uuid' => 'rel', 'promotion_uuid' => $promotion->uuid, 'promotion_code_uuid' => $code->uuid, 'status' => 'redeemed']);
    $redemption = Fleetbase\Storefront\Models\PromotionRedemption::query()->first();

    expect($promotion->codes()->pluck('code')->all())->toBe(['REL'])
        ->and($promotion->redemptions()->count())->toBe(1)
        ->and($promotion->owner()->getRelated())->toBeInstanceOf(Fleetbase\Storefront\Models\Store::class)
        ->and($redemption->promotion->uuid)->toBe($promotion->uuid)
        ->and($redemption->code->code)->toBe('REL');
});
