<?php

require_once __DIR__ . '/../../Support/PromotionTestHelpers.php';

use Fleetbase\Storefront\Http\Controllers\ActionController;
use Fleetbase\Storefront\Http\Controllers\CampaignController;
use Fleetbase\Storefront\Http\Controllers\CustomerSegmentController;
use Fleetbase\Storefront\Http\Controllers\PromotionController;
use Fleetbase\Storefront\Http\Filter\CampaignFilter;
use Fleetbase\Storefront\Http\Filter\CustomerSegmentFilter;
use Fleetbase\Storefront\Http\Requests\CampaignRequest;
use Fleetbase\Storefront\Http\Requests\CustomerSegmentRequest;
use Fleetbase\Storefront\Http\Resources\Campaign as CampaignResource;
use Fleetbase\Storefront\Http\Resources\CustomerSegment as CustomerSegmentResource;
use Fleetbase\Storefront\Models\Campaign;
use Fleetbase\Storefront\Models\CustomerSegment;
use Fleetbase\Storefront\Models\Promotion;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Promotions\CampaignDispatcher;
use Fleetbase\Storefront\Promotions\SegmentResolver;
use Illuminate\Http\Request;

function endpointCampaign(array $attributes = []): Campaign
{
    static $sequence = 0;
    $sequence++;
    $campaign = new Campaign();
    $campaign->forceFill(array_merge([
        'uuid'         => 'endpoint_campaign_' . $sequence,
        'public_id'    => 'campaign_endpoint_' . $sequence,
        'company_uuid' => 'company_uuid',
        'owner_uuid'   => 'store_a_uuid',
        'owner_type'   => Store::class,
        'name'         => 'Campaign',
        'status'       => Campaign::STATUS_DRAFT,
        'title'        => 'Hello',
        'body'         => 'World',
    ], $attributes));
    $campaign->save();

    return $campaign;
}

function endpointSegment(array $attributes = []): CustomerSegment
{
    $segment = new CustomerSegment();
    $segment->forceFill(array_merge([
        'uuid'         => 'segment_uuid',
        'public_id'    => 'segment_public',
        'company_uuid' => 'company_uuid',
        'owner_uuid'   => 'store_a_uuid',
        'owner_type'   => Store::class,
        'name'         => 'Everyone',
        'rules'        => [],
    ], $attributes));
    $segment->save();

    return $segment;
}

function endpointRequest(array $input = [], string $method = 'POST'): Request
{
    $request = Request::create('/', $method, $input);
    $request->setLaravelSession(new Illuminate\Session\Store('campaigns', new Illuminate\Session\ArraySessionHandler(120)));
    app()->instance('request', $request);

    return $request;
}

/**
 * Run model events (uuid and public id generation) for records created by the code under test.
 */
function withModelEvents(callable $callback): mixed
{
    Illuminate\Database\Eloquent\Model::setEventDispatcher(new Illuminate\Events\Dispatcher());
    Illuminate\Database\Eloquent\Model::clearBootedModels();
    app()->instance('responsecache', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });

    try {
        return $callback();
    } finally {
        Illuminate\Database\Eloquent\Model::unsetEventDispatcher();
        Illuminate\Database\Eloquent\Model::clearBootedModels();
        app()->offsetUnset('responsecache');
    }
}

function campaignDispatcher(): CampaignDispatcher
{
    return new CampaignDispatcher(new SegmentResolver());
}

beforeEach(function () {
    createPromotionSchema();
    createCampaignSchema();
    seedPromotionStores();
    promotionDb()->table('contacts')->insert([
        ['uuid' => 'customer_1', 'public_id' => 'contact_1', 'company_uuid' => 'company_uuid', 'type' => 'customer', 'name' => 'One', 'email' => 'one@example.test'],
        ['uuid' => 'customer_2', 'public_id' => 'contact_2', 'company_uuid' => 'company_uuid', 'type' => 'customer', 'name' => 'Two', 'email' => null],
        ['uuid' => 'outsider', 'public_id' => 'contact_3', 'company_uuid' => 'other_company', 'type' => 'customer', 'name' => 'Three', 'email' => null],
    ]);
    session(['company' => 'company_uuid', 'user' => 'user_uuid']);
});

test('campaigns can be sent now, only once, and canceled until sent', function () {
    $bus        = fakeCampaignBus();
    $controller = new CampaignController();
    $draft      = endpointCampaign();
    $canceled   = endpointCampaign(['status' => Campaign::STATUS_SCHEDULED, 'send_at' => now()->addDay()]);

    $sent = $controller->send($draft->public_id, campaignDispatcher())->resolve(endpointRequest());

    expect($sent['status'])->toBe(Campaign::STATUS_SENT)
        ->and($sent['stats'])->toBe(['targeted' => 2, 'batches' => 1])
        ->and($bus->jobs)->toHaveCount(1)
        ->and($controller->send($draft->uuid, campaignDispatcher())->getData(true))->toBe(['error' => 'This campaign has already been sent.'])
        ->and($controller->cancel($draft->uuid)->getData(true))->toBe(['error' => 'This campaign has already been sent.'])
        ->and($controller->cancel($canceled->uuid)->resolve(endpointRequest())['status'])->toBe(Campaign::STATUS_CANCELED)
        ->and($controller->send('missing', campaignDispatcher())->getStatusCode())->toBe(404)
        ->and($controller->cancel('missing')->getStatusCode())->toBe(404);
});

test('campaign audiences are counted before sending', function () {
    $controller = new CampaignController();
    $all        = endpointCampaign();
    $selected   = endpointCampaign(['recipients' => ['customer_2', 'outsider']]);
    $orphan     = endpointCampaign(['owner_uuid' => 'missing_store']);

    expect($controller->audience($all->uuid, campaignDispatcher())->getData(true))->toBe(['count' => 2])
        ->and($controller->audience($selected->uuid, campaignDispatcher())->getData(true))->toBe(['count' => 1])
        ->and($controller->audience($orphan->uuid, campaignDispatcher())->getStatusCode())->toBe(404)
        ->and($controller->audience('missing', campaignDispatcher())->getStatusCode())->toBe(404);
});

test('segments preview their size and a sample, saved or not', function () {
    $segment    = endpointSegment(['rules' => ['customers' => ['customer_1']]]);
    $controller = new CustomerSegmentController();
    $resolver   = new SegmentResolver();

    expect($controller->preview($segment->public_id, $resolver)->getData(true))->toBe([
        'count'  => 1,
        'sample' => [['id' => 'contact_1', 'name' => 'One', 'email' => 'one@example.test', 'phone' => null]],
    ])
        ->and($controller->previewRules(endpointRequest(['owner_uuid' => 'network_uuid', 'rules' => ['unknown_rule' => 1]]), $resolver)->getData(true)['count'])->toBe(2)
        ->and($controller->previewRules(endpointRequest(['owner_uuid' => 'someone_else']), $resolver)->getStatusCode())->toBe(400)
        ->and($controller->preview('missing', $resolver)->getStatusCode())->toBe(404);

    endpointSegment(['uuid' => 'orphan_segment', 'public_id' => 'segment_orphan', 'owner_uuid' => 'missing_store']);

    expect($controller->preview('orphan_segment', $resolver)->getStatusCode())->toBe(404);
});

test('promotions are announced with a campaign sent now or when the promotion starts', function () {
    withModelEvents(function () {
        $bus        = fakeCampaignBus();
        $controller = new PromotionController();
        $live       = makePromotion(['name' => 'Half off', 'description' => 'Half off everything']);
        $upcoming   = makePromotion(['name' => 'Next week', 'starts_at' => now()->addWeek()]);
        $paused     = makePromotion(['status' => Promotion::STATUS_PAUSED]);
        $segment    = endpointSegment(['rules' => ['customers' => ['customer_1']]]);

        $now   = $controller->announce($live->public_id, endpointRequest(['segment' => $segment->uuid]), campaignDispatcher())->resolve(endpointRequest());
        $later = $controller->announce($upcoming->uuid, endpointRequest(['title' => 'Coming soon', 'channels' => ['inbox']]), campaignDispatcher())->resolve(endpointRequest());

        expect($now)->toMatchArray([
            'name'           => 'Announce: Half off',
            'title'          => 'Half off',
            'body'           => 'Half off everything',
            'status'         => Campaign::STATUS_SENT,
            'promotion_uuid' => $live->uuid,
            'segment_uuid'   => $segment->uuid,
            'action'         => ['type' => 'promotion', 'id' => $live->public_id],
            'stats'          => ['targeted' => 1, 'batches' => 1],
        ])
            ->and($later)->toMatchArray([
                'title'    => 'Coming soon',
                'body'     => 'A new deal is available, tap to see it.',
                'status'   => Campaign::STATUS_SCHEDULED,
                'channels' => ['inbox'],
            ])
            ->and($bus->jobs)->toHaveCount(1)
            ->and($controller->announce($paused->uuid, endpointRequest(), campaignDispatcher())->getStatusCode())->toBe(400)
            ->and($controller->announce($live->uuid, endpointRequest(['segment' => 'missing']), campaignDispatcher())->getStatusCode())->toBe(404)
            ->and($controller->announce('missing', endpointRequest(), campaignDispatcher())->getStatusCode())->toBe(404);

        $explicit = $controller->announce($live->uuid, endpointRequest(['send_at' => now()->addDay()->toDateTimeString()]), campaignDispatcher())->resolve(endpointRequest());

        expect($explicit['status'])->toBe(Campaign::STATUS_SCHEDULED);
    });
});

test('segment and campaign validation require owned storefronts and allowed values', function () {
    $segmentRules  = (new CustomerSegmentRequest())->rules();
    $campaignRules = CampaignRequest::createFrom(endpointRequest([], 'PUT'))->rules();
    $owned         = CustomerSegmentRequest::ownedStorefrontRule();
    $failures      = [];
    foreach (['store_a_uuid', 'network_uuid', 'strangers_store'] as $owner) {
        $owned('owner_uuid', $owner, function ($message) use (&$failures) {
            $failures[] = $message;
        });
    }

    expect($segmentRules['rules.min_orders'])->toBe(['nullable', 'integer', 'min:0'])
        ->and($campaignRules['name'][0])->toBe('sometimes')
        ->and($campaignRules['channels.*'])->toBe(['in:push,inbox'])
        ->and($campaignRules['status'])->toBe(['sometimes', 'in:draft,scheduled'])
        ->and($failures)->toBe(['The owner must be one of your stores or networks.'])
        ->and((new CustomerSegmentRequest())->authorize())->toBeFalse()
        ->and((new CampaignRequest())->authorize())->toBeFalse();
});

test('segment and campaign filters scope to the company', function () {
    endpointSegment();
    endpointSegment(['uuid' => 'network_segment', 'public_id' => 'segment_network', 'owner_uuid' => 'network_uuid', 'name' => 'Network']);
    endpointSegment(['uuid' => 'foreign_segment', 'public_id' => 'segment_foreign', 'company_uuid' => 'other_company', 'name' => 'Foreign']);
    endpointCampaign(['name' => 'Draft']);
    endpointCampaign(['name' => 'Sent', 'status' => Campaign::STATUS_SENT, 'promotion_uuid' => 'promo', 'owner_uuid' => 'network_uuid']);
    endpointCampaign(['name' => 'Foreign', 'company_uuid' => 'other_company']);

    $filter = function (string $class, $builder, array $calls) {
        $instance = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $session  = new Illuminate\Session\Store('filter', new Illuminate\Session\ArraySessionHandler(1));
        $session->put('company', 'company_uuid');
        (new ReflectionProperty(Fleetbase\Http\Filter\Filter::class, 'builder'))->setValue($instance, $builder);
        (new ReflectionProperty(Fleetbase\Http\Filter\Filter::class, 'session'))->setValue($instance, $session);
        $instance->queryForInternal();
        foreach ($calls as $method => $argument) {
            $instance->{$method}($argument);
        }

        return $builder->orderBy('name')->pluck('name')->all();
    };

    expect($filter(CustomerSegmentFilter::class, CustomerSegment::query(), []))->toBe(['Everyone', 'Network'])
        ->and($filter(CustomerSegmentFilter::class, CustomerSegment::query(), ['owner' => 'network_uuid']))->toBe(['Network'])
        ->and($filter(CampaignFilter::class, Campaign::query(), []))->toBe(['Draft', 'Sent'])
        ->and($filter(CampaignFilter::class, Campaign::query(), ['status' => 'sent', 'owner' => 'network_uuid', 'promotion' => 'promo']))->toBe(['Sent']);
});

test('segment and campaign resources expose console fields', function () {
    $segment  = endpointSegment(['rules' => ['min_orders' => 1]]);
    $campaign = endpointCampaign(['segment_uuid' => $segment->uuid, 'channels' => ['push']]);

    expect((new CustomerSegmentResource($segment))->resolve(endpointRequest()))->toMatchArray(['id' => 'segment_uuid', 'name' => 'Everyone', 'rules' => ['min_orders' => 1]])
        ->and((new CampaignResource($campaign))->resolve(endpointRequest()))->toMatchArray([
            'id'           => $campaign->uuid,
            'segment_name' => 'Everyone',
            'channels'     => ['push'],
            'recipients'   => [],
            'image_url'    => null,
        ]);
});

test('the promotional push action sends a tracked campaign to selected or all customers', function () {
    withModelEvents(function () {
        $bus        = fakeCampaignBus();
        $controller = new ActionController();

        $selected = $controller->sendPushNotification(endpointRequest(['title' => 'New menu', 'body' => 'Try it', 'customers' => ['customer_1', 'outsider'], 'store' => 'store_a']))->getData(true);
        $all      = $controller->sendPushNotification(endpointRequest(['title' => 'New menu', 'body' => 'Try it', 'select_all' => true, 'store' => 'store_a']))->getData(true);
        $nobody   = $controller->sendPushNotification(endpointRequest(['title' => 'New menu', 'body' => 'Try it', 'customers' => ['outsider'], 'store' => 'store_a']))->getData(true);

        expect($selected)->toMatchArray(['status' => 'OK', 'sent_count' => 1, 'total' => 1])
            ->and($all)->toMatchArray(['status' => 'OK', 'sent_count' => 2, 'total' => 2])
            ->and($nobody)->toBe(['status' => 'OK', 'sent_count' => 0, 'total' => 0])
            ->and(Campaign::query()->count())->toBe(2)
            ->and(Campaign::query()->first()->only(['name', 'status', 'owner_uuid', 'recipients']))->toBe([
                'name'       => 'Push notification: New menu',
                'status'     => Campaign::STATUS_SENT,
                'owner_uuid' => 'store_a_uuid',
                'recipients' => ['customer_1'],
            ])
            ->and($bus->jobs)->toHaveCount(2)
            ->and($controller->sendPushNotification(endpointRequest(['title' => 'x', 'body' => 'y', 'select_all' => true, 'store' => 'store_missing']))->getStatusCode())->toBe(404);
    });
});
