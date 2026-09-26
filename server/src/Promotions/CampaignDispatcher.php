<?php

namespace Fleetbase\Storefront\Promotions;

use Fleetbase\Storefront\Jobs\SendCampaignBatch;
use Fleetbase\Storefront\Models\Campaign;
use Fleetbase\Storefront\Models\Promotion;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Resolves a campaign's audience and queues it for delivery in batches.
 */
class CampaignDispatcher
{
    public const BATCH_SIZE = 250;

    public function __construct(protected SegmentResolver $segments)
    {
    }

    /**
     * Send every scheduled campaign that is due.
     */
    public function dispatchDue(?Carbon $now = null): int
    {
        $now = $now ?? Carbon::now();
        $due = Campaign::where('status', Campaign::STATUS_SCHEDULED)->where('send_at', '<=', $now)->get();

        return $due->filter(fn (Campaign $campaign) => $this->dispatch($campaign))->count();
    }

    /**
     * Send a campaign now. Returns false if it was not scheduled (e.g. already sending) or
     * cannot be sent; the campaign status records why.
     */
    public function dispatch(Campaign $campaign): bool
    {
        // Claim the campaign so concurrent schedulers never send it twice.
        $claimed = Campaign::where('uuid', $campaign->uuid)
            ->where('status', Campaign::STATUS_SCHEDULED)
            ->update(['status' => Campaign::STATUS_SENDING, 'started_at' => now()]);
        if (!$claimed) {
            return false;
        }
        $campaign->refresh();

        $owner = $campaign->resolveOwner();
        if (!$owner) {
            return $this->finish($campaign, Campaign::STATUS_CANCELED, ['reason' => 'owner_missing']);
        }

        // An announcement for a promotion that is no longer running is not sent.
        $promotion = $campaign->promotion;
        if ($campaign->promotion_uuid && (!$promotion || in_array($promotion->status, [Promotion::STATUS_PAUSED, Promotion::STATUS_ENDED], true) || ($promotion->ends_at && $promotion->ends_at->isPast()))) {
            return $this->finish($campaign, Campaign::STATUS_CANCELED, ['reason' => 'promotion_unavailable']);
        }

        $targeted = 0;
        $batches  = 0;
        $this->audience($campaign, $owner)->select('uuid')->chunkById(static::BATCH_SIZE, function ($customers) use ($campaign, &$targeted, &$batches) {
            $uuids = $customers->pluck('uuid')->all();
            $targeted += count($uuids);
            $batches++;
            app(BusDispatcher::class)->dispatch(new SendCampaignBatch($campaign->uuid, $uuids));
        }, 'uuid');

        return $this->finish($campaign, Campaign::STATUS_SENT, ['targeted' => $targeted, 'batches' => $batches]);
    }

    /**
     * Customers the campaign is sent to: its segment's rules, narrowed to explicit recipients.
     */
    public function audience(Campaign $campaign, $owner = null): Builder
    {
        $owner ??= $campaign->resolveOwner();
        $rules = (array) ($campaign->segment?->rules ?? []);

        // An explicit recipient list (even an empty one) restricts the audience; null does not.
        if ($campaign->recipients !== null) {
            $recipients         = (array) $campaign->recipients;
            $rules['customers'] = !empty($rules['customers']) ? array_values(array_intersect((array) $rules['customers'], $recipients)) : $recipients;
            if (empty($rules['customers'])) {
                $rules['customers'] = ['__none__'];
            }
        }

        return $this->segments->query($owner, $rules);
    }

    protected function finish(Campaign $campaign, string $status, array $stats): bool
    {
        $campaign->forceFill([
            'status'  => $status,
            'sent_at' => $status === Campaign::STATUS_SENT ? now() : null,
            'stats'   => array_merge((array) ($campaign->stats ?? []), $stats),
        ])->save();

        return $status === Campaign::STATUS_SENT;
    }
}
