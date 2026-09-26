<?php

namespace Fleetbase\Storefront\Jobs;

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\Storefront\Models\Campaign;
use Fleetbase\Storefront\Notifications\CampaignNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Sends a campaign to one batch of customers.
 */
class SendCampaignBatch implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    /**
     * @param string[] $customerUuids
     */
    public function __construct(public string $campaignUuid, public array $customerUuids)
    {
    }

    public function handle(): void
    {
        $campaign = Campaign::where('uuid', $this->campaignUuid)->first();
        if (!$campaign || $campaign->status === Campaign::STATUS_CANCELED) {
            return;
        }

        $notification = new CampaignNotification($campaign);
        foreach (Contact::whereIn('uuid', $this->customerUuids)->get() as $customer) {
            try {
                $customer->notify($notification);
            } catch (\Throwable $e) {
                Log::warning('[Storefront] Unable to send campaign to customer.', ['campaign' => $campaign->public_id, 'customer' => $customer->public_id, 'error' => $e->getMessage()]);
            }
        }
    }
}
