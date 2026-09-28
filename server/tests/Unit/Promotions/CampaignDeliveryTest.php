<?php

require_once __DIR__ . '/../../Support/PromotionTestHelpers.php';

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\Storefront\Console\Commands\DispatchCampaigns;
use Fleetbase\Storefront\Jobs\SendCampaignBatch;
use Fleetbase\Storefront\Models\Campaign;
use Fleetbase\Storefront\Models\CustomerSegment;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Promotion;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Notifications\CampaignNotification;
use Fleetbase\Storefront\Notifications\Channels\SafeBroadcastChannel;
use Fleetbase\Storefront\Promotions\CampaignDispatcher;
use Fleetbase\Storefront\Promotions\SegmentResolver;
use Fleetbase\Storefront\Push\StorefrontPushChannel;
use Illuminate\Support\Carbon;

function campaignStore(): Store
{
    return Store::where('uuid', 'store_a_uuid')->first();
}

function makeCampaign(array $attributes = []): Campaign
{
    static $sequence = 0;
    $sequence++;

    $campaign = new Campaign();
    $campaign->forceFill(array_merge([
        'uuid'         => 'campaign_uuid_' . $sequence,
        'public_id'    => 'campaign_' . $sequence,
        'company_uuid' => 'company_uuid',
        'owner_uuid'   => 'store_a_uuid',
        'owner_type'   => Store::class,
        'name'         => 'Campaign ' . $sequence,
        'status'       => Campaign::STATUS_SCHEDULED,
        'title'        => 'Fresh deals',
        'body'         => 'Come see what is new.',
        'send_at'      => now()->subMinute(),
    ], $attributes));
    $campaign->save();

    return $campaign;
}

function segmentUuids(array $rules, $owner = null, ?Carbon $now = null): array
{
    return (new SegmentResolver())->query($owner ?? campaignStore(), $rules, $now)->orderBy('uuid')->pluck('uuid')->all();
}

function fakeNotificationDispatcher(?callable $onSend = null): object
{
    $dispatcher = new class($onSend) implements Illuminate\Contracts\Notifications\Dispatcher {
        public array $sent = [];

        public function __construct(public $onSend)
        {
        }

        public function send($notifiables, $notification)
        {
            foreach (collect($notifiables instanceof Illuminate\Support\Collection ? $notifiables : [$notifiables]) as $notifiable) {
                if ($this->onSend) {
                    ($this->onSend)($notifiable);
                }
                $this->sent[] = [$notifiable->uuid, $notification];
            }
        }

        public function sendNow($notifiables, $notification, ?array $channels = null)
        {
            $this->send($notifiables, $notification);
        }
    };
    app()->instance(Illuminate\Contracts\Notifications\Dispatcher::class, $dispatcher);
    Illuminate\Support\Facades\Notification::clearResolvedInstance(Illuminate\Contracts\Notifications\Dispatcher::class);

    return $dispatcher;
}

beforeEach(function () {
    createPromotionSchema();
    createCampaignSchema();
    seedPromotionStores();

    $days = fn (int $days) => now()->subDays($days)->toDateTimeString();
    promotionDb()->table('contacts')->insert([
        ['uuid' => 'a_regular', 'public_id' => 'contact_a', 'company_uuid' => 'company_uuid', 'user_uuid' => 'user_a', 'type' => 'customer', 'name' => 'Regular', 'created_at' => $days(400)],
        ['uuid' => 'b_lapsed', 'public_id' => 'contact_b', 'company_uuid' => 'company_uuid', 'user_uuid' => 'user_b', 'type' => 'customer', 'name' => 'Lapsed', 'created_at' => $days(400)],
        ['uuid' => 'c_new', 'public_id' => 'contact_c', 'company_uuid' => 'company_uuid', 'user_uuid' => null, 'type' => 'customer', 'name' => 'New', 'created_at' => $days(2)],
        ['uuid' => 'd_other_company', 'public_id' => 'contact_d', 'company_uuid' => 'other_company', 'user_uuid' => null, 'type' => 'customer', 'name' => 'Other', 'created_at' => $days(2)],
        ['uuid' => 'e_not_customer', 'public_id' => 'contact_e', 'company_uuid' => 'company_uuid', 'user_uuid' => null, 'type' => 'contact', 'name' => 'Vendor', 'created_at' => $days(2)],
        ['uuid' => 'f_store_b', 'public_id' => 'contact_f', 'company_uuid' => 'company_uuid', 'user_uuid' => null, 'type' => 'customer', 'name' => 'Store B fan', 'created_at' => $days(400)],
    ]);
    $order = fn (string $uuid, string $customer, int $daysAgo, array $meta = ['storefront_id' => 'store_a'], string $status = 'completed') => [
        'uuid' => $uuid, 'customer_uuid' => $customer, 'type' => 'storefront', 'status' => $status, 'meta' => json_encode($meta), 'created_at' => $days($daysAgo),
    ];
    promotionDb()->table('orders')->insert([
        $order('o1', 'a_regular', 1),
        $order('o2', 'a_regular', 5),
        $order('o3', 'a_regular', 20, ['storefront_id' => 'store_a', 'storefront_network_id' => 'network_m']),
        $order('o4', 'b_lapsed', 60),
        $order('o5', 'b_lapsed', 2, ['storefront_id' => 'store_a'], 'canceled'),
        $order('o6', 'f_store_b', 3, ['storefront_id' => 'store_b', 'storefront_network_id' => 'network_m']),
    ]);
    promotionDb()->table('transactions')->insert([
        ['uuid' => 't1', 'customer_uuid' => 'a_regular', 'type' => 'storefront', 'status' => 'success', 'amount' => 9000],
        ['uuid' => 't2', 'customer_uuid' => 'b_lapsed', 'type' => 'storefront', 'status' => 'success', 'amount' => 2000],
        ['uuid' => 't3', 'customer_uuid' => 'b_lapsed', 'type' => 'storefront', 'status' => 'failed', 'amount' => 9000],
    ]);
    promotionDb()->table('user_devices')->insert([
        ['uuid' => 'dev_a', 'user_uuid' => 'user_a', 'platform' => 'ios', 'token' => 'token_a', 'status' => 'active'],
        ['uuid' => 'dev_b', 'user_uuid' => 'user_b', 'platform' => 'android', 'token' => 'token_b', 'status' => 'invalid'],
    ]);
});

test('segment rules select the owner company customers by order history, spend, age and devices', function ($rules, $expected) {
    expect(segmentUuids($rules))->toBe($expected);
})->with([
    'everyone'             => [[], ['a_regular', 'b_lapsed', 'c_new', 'f_store_b']],
    'explicit customers'   => [['customers' => ['c_new', 'd_other_company']], ['c_new']],
    'at least two orders'  => [['min_orders' => 2], ['a_regular']],
    'never ordered here'   => [['max_orders' => 0], ['c_new', 'f_store_b']],
    'ordered this week'    => [['ordered_within_days' => 7], ['a_regular']],
    'lapsed for a month'   => [['not_ordered_within_days' => 30], ['b_lapsed']],
    'joined this week'     => [['joined_within_days' => 7], ['c_new']],
    'spent at least 5000'  => [['min_spent' => 5000], ['a_regular']],
    'reachable by push'    => [['has_push_device' => true], ['a_regular']],
    'combined'             => [['min_orders' => 1, 'max_orders' => 1], ['b_lapsed']],
]);

test('network segments count orders placed through the network', function () {
    $network = Network::where('uuid', 'network_uuid')->first();

    expect(segmentUuids(['min_orders' => 1], $network))->toBe(['a_regular', 'f_store_b']);
});

test('the dispatcher queues due campaigns in batches and records what it targeted', function () {
    $bus     = fakeCampaignBus();
    $segment = tap(new CustomerSegment())->forceFill(['uuid' => 'segment_uuid', 'company_uuid' => 'company_uuid', 'owner_uuid' => 'store_a_uuid', 'name' => 'Buyers', 'rules' => ['min_orders' => 1]]);
    $segment->save();
    $due     = makeCampaign(['segment_uuid' => 'segment_uuid']);
    $future  = makeCampaign(['send_at' => now()->addHour()]);
    $draft   = makeCampaign(['status' => Campaign::STATUS_DRAFT]);

    $sent = (new CampaignDispatcher(new SegmentResolver()))->dispatchDue();

    expect($sent)->toBe(1)
        ->and($due->refresh()->status)->toBe(Campaign::STATUS_SENT)
        ->and($due->stats)->toBe(['targeted' => 2, 'batches' => 1])
        ->and($due->sent_at)->not->toBeNull()
        ->and($future->refresh()->status)->toBe(Campaign::STATUS_SCHEDULED)
        ->and($draft->refresh()->status)->toBe(Campaign::STATUS_DRAFT)
        ->and($bus->jobs)->toHaveCount(1)
        ->and($bus->jobs[0])->toBeInstanceOf(SendCampaignBatch::class)
        ->and($bus->jobs[0]->customerUuids)->toBe(['a_regular', 'b_lapsed'])
        // A campaign that is already being sent is never claimed twice.
        ->and((new CampaignDispatcher(new SegmentResolver()))->dispatch($due))->toBeFalse();
});

test('explicit recipients narrow the audience, and an empty list reaches nobody', function () {
    fakeCampaignBus();
    $dispatcher = new CampaignDispatcher(new SegmentResolver());
    $segment    = tap(new CustomerSegment())->forceFill(['uuid' => 'segment_uuid', 'company_uuid' => 'company_uuid', 'owner_uuid' => 'store_a_uuid', 'name' => 'Buyers', 'rules' => ['min_orders' => 1, 'customers' => ['a_regular', 'c_new']]]);
    $segment->save();

    $recipients   = makeCampaign(['recipients' => ['c_new', 'f_store_b']]);
    $intersection = makeCampaign(['segment_uuid' => 'segment_uuid', 'recipients' => ['a_regular', 'b_lapsed']]);
    $nobody       = makeCampaign(['recipients' => []]);
    $disjoint     = makeCampaign(['segment_uuid' => 'segment_uuid', 'recipients' => ['f_store_b']]);

    expect($dispatcher->audience($recipients)->orderBy('uuid')->pluck('uuid')->all())->toBe(['c_new', 'f_store_b'])
        ->and($dispatcher->audience($intersection)->pluck('uuid')->all())->toBe(['a_regular'])
        ->and($dispatcher->audience($nobody)->count())->toBe(0)
        ->and($dispatcher->audience($disjoint)->count())->toBe(0);
});

test('campaigns without an owner or for a stopped promotion are canceled instead of sent', function () {
    fakeCampaignBus();
    $dispatcher = new CampaignDispatcher(new SegmentResolver());
    $paused     = makePromotion(['status' => Promotion::STATUS_PAUSED]);
    $expired    = makePromotion(['ends_at' => now()->subDay()]);
    $live       = makePromotion();

    $orphan    = makeCampaign(['owner_uuid' => 'missing_store']);
    $forPaused = makeCampaign(['promotion_uuid' => $paused->uuid]);
    $forEnded  = makeCampaign(['promotion_uuid' => $expired->uuid]);
    $forGone   = makeCampaign(['promotion_uuid' => 'deleted_promotion']);
    $forLive   = makeCampaign(['promotion_uuid' => $live->uuid]);

    foreach ([$orphan, $forPaused, $forEnded, $forGone, $forLive] as $campaign) {
        $dispatcher->dispatch($campaign);
    }

    expect($orphan->refresh()->stats)->toBe(['reason' => 'owner_missing'])
        ->and($orphan->status)->toBe(Campaign::STATUS_CANCELED)
        ->and($forPaused->refresh()->stats)->toBe(['reason' => 'promotion_unavailable'])
        ->and($forEnded->refresh()->status)->toBe(Campaign::STATUS_CANCELED)
        ->and($forGone->refresh()->status)->toBe(Campaign::STATUS_CANCELED)
        ->and($forLive->refresh()->status)->toBe(Campaign::STATUS_SENT);
});

test('batches notify each customer, skip canceled campaigns and isolate failures', function () {
    $campaign   = makeCampaign();
    $dispatcher = fakeNotificationDispatcher(function ($notifiable) {
        if ($notifiable->uuid === 'b_lapsed') {
            throw new RuntimeException('database down');
        }
    });

    (new SendCampaignBatch($campaign->uuid, ['a_regular', 'b_lapsed', 'c_new']))->handle();
    (new SendCampaignBatch('missing_campaign', ['a_regular']))->handle();
    $campaign->forceFill(['status' => Campaign::STATUS_CANCELED])->save();
    (new SendCampaignBatch($campaign->uuid, ['a_regular']))->handle();

    app()->offsetUnset(Illuminate\Contracts\Notifications\Dispatcher::class);

    expect(array_column($dispatcher->sent, 0))->toBe(['a_regular', 'c_new'])
        ->and($dispatcher->sent[0][1])->toBeInstanceOf(CampaignNotification::class);
});

test('the scheduled command dispatches due campaigns', function () {
    fakeCampaignBus();
    makeCampaign();
    $command = new class extends DispatchCampaigns {
        public array $lines = [];

        public function info($string, $verbosity = null)
        {
            $this->lines[] = $string;
        }
    };

    expect($command->handle(new CampaignDispatcher(new SegmentResolver())))->toBe(0)
        ->and($command->lines)->toBe(['Dispatched 1 campaign(s).']);
});

test('campaign notifications honor channels and preferences and deep link to the campaign', function () {
    $promotion = makePromotion();
    $campaign  = makeCampaign(['promotion_uuid' => $promotion->uuid, 'channels' => ['inbox'], 'action' => ['type' => 'promotion', 'id' => $promotion->public_id]]);
    $optedOut  = tap(new Contact())->forceFill(['meta' => ['notification_preferences' => ['promotions' => false]]]);
    $customer  = tap(new Contact())->forceFill(['meta' => []]);

    $inboxOnly = new CampaignNotification($campaign);
    $pushOnly  = new CampaignNotification(makeCampaign(['channels' => ['push']]));
    $both      = new CampaignNotification(makeCampaign(['channels' => ['sms']]));

    expect($inboxOnly->via($optedOut))->toBe([])
        ->and($inboxOnly->via($customer))->toBe(['database', SafeBroadcastChannel::class])
        ->and($pushOnly->via($customer))->toBe([StorefrontPushChannel::class])
        ->and($both->via($customer))->toBe([StorefrontPushChannel::class, 'database', SafeBroadcastChannel::class])
        ->and($inboxOnly->toArray($customer))->toBe([
            'title'        => 'Fresh deals',
            'body'         => 'Come see what is new.',
            'subject'      => 'Fresh deals',
            'message'      => 'Come see what is new.',
            'image'        => null,
            'type'         => 'campaign',
            'campaign_id'  => $campaign->public_id,
            'promotion_id' => $promotion->public_id,
            'store_id'     => 'store_a',
            'action'       => 'promotion',
            'action_id'    => $promotion->public_id,
        ])
        ->and($inboxOnly->toPush($customer)->data['campaign_id'])->toBe($campaign->public_id)
        ->and($inboxOnly->toPush($customer)->analyticsLabel)->toBe('campaign')
        ->and($inboxOnly->toBroadcast($customer)->data['type'])->toBe('campaign')
        ->and($inboxOnly->broadcastType())->toBe('campaign')
        ->and(collect($inboxOnly->pushStorefronts())->pluck('uuid')->all())->toBe(['store_a_uuid', 'network_uuid']);
});

test('network campaigns deep link with the network and survive missing memberships', function () {
    $campaign = makeCampaign(['owner_uuid' => 'network_uuid', 'owner_type' => Network::class]);
    $network  = new CampaignNotification($campaign);

    expect($network->toArray(null)['network_id'])->toBe('network_m')
        ->and(collect($network->pushStorefronts())->pluck('uuid')->all())->toBe(['network_uuid'])
        ->and($campaign->resolveOwner())->toBeInstanceOf(Network::class)
        ->and($campaign->enabledChannels())->toBe(['push', 'inbox']);

    promotionDb()->getSchemaBuilder()->dropIfExists('network_stores');
    $store = new CampaignNotification(makeCampaign());

    expect(collect($store->pushStorefronts())->pluck('uuid')->all())->toBe(['store_a_uuid']);
});

test('campaigns relate to their segment, promotion and owner', function () {
    $promotion = makePromotion();
    $segment   = tap(new CustomerSegment())->forceFill(['uuid' => 'segment_uuid', 'owner_uuid' => 'store_a_uuid', 'owner_type' => 'storefront:store', 'name' => 'Buyers']);
    $segment->save();
    $campaign = makeCampaign(['segment_uuid' => 'segment_uuid', 'promotion_uuid' => $promotion->uuid]);

    expect($campaign->segment->name)->toBe('Buyers')
        ->and($campaign->promotion->uuid)->toBe($promotion->uuid)
        ->and($campaign->owner()->getRelated())->toBeInstanceOf(Store::class)
        ->and($segment->owner()->getRelated())->toBeInstanceOf(Store::class)
        ->and($segment->getAttributes()['owner_type'])->toBe(Store::class)
        ->and($campaign->image)->toBeNull();
});
