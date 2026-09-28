<?php

namespace Fleetbase\Storefront\Http\Controllers;

use Fleetbase\Storefront\Http\Requests\CampaignRequest;
use Fleetbase\Storefront\Http\Resources\Campaign as CampaignResource;
use Fleetbase\Storefront\Models\Campaign;
use Fleetbase\Storefront\Promotions\CampaignDispatcher;

class CampaignController extends StorefrontController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'campaign';

    /**
     * Validates creates and updates.
     *
     * @var string
     */
    public $request = CampaignRequest::class;

    /**
     * Send a draft or scheduled campaign now.
     */
    public function send(string $id, CampaignDispatcher $dispatcher)
    {
        $campaign = $this->findCampaign($id);
        if (!$campaign) {
            return response()->error('Campaign not found.', 404);
        }

        if (!in_array($campaign->status, [Campaign::STATUS_DRAFT, Campaign::STATUS_SCHEDULED], true)) {
            return response()->error('This campaign has already been ' . $campaign->status . '.');
        }

        $campaign->forceFill(['status' => Campaign::STATUS_SCHEDULED, 'send_at' => now()])->save();
        $dispatcher->dispatch($campaign);

        return new CampaignResource($campaign->refresh());
    }

    /**
     * Cancel a campaign that has not been sent. Batches already queued are skipped.
     */
    public function cancel(string $id)
    {
        $campaign = $this->findCampaign($id);
        if (!$campaign) {
            return response()->error('Campaign not found.', 404);
        }

        if ($campaign->status === Campaign::STATUS_SENT) {
            return response()->error('This campaign has already been sent.');
        }

        $campaign->forceFill(['status' => Campaign::STATUS_CANCELED])->save();

        return new CampaignResource($campaign);
    }

    /**
     * Number of customers the campaign would reach right now.
     */
    public function audience(string $id, CampaignDispatcher $dispatcher)
    {
        $campaign = $this->findCampaign($id);
        if (!$campaign || !$campaign->resolveOwner()) {
            return response()->error('Campaign not found.', 404);
        }

        return response()->json(['count' => $dispatcher->audience($campaign)->count()]);
    }

    protected function findCampaign(string $id): ?Campaign
    {
        return Campaign::where('company_uuid', session('company'))
            ->where(fn ($query) => $query->where('uuid', $id)->orWhere('public_id', $id))
            ->first();
    }
}
