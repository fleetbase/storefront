<?php

namespace Fleetbase\Storefront\Notifications;

use Fleetbase\Storefront\Models\Campaign;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Notifications\Channels\SafeBroadcastChannel;
use Fleetbase\Storefront\Push\Contracts\SendsPushNotification;
use Fleetbase\Storefront\Push\PushMessage;
use Fleetbase\Storefront\Push\StorefrontPushChannel;
use Fleetbase\Storefront\Support\CustomerNotificationPresenter;
use Fleetbase\Storefront\Support\NotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * A campaign message delivered to one customer by push and/or to their inbox.
 */
class CampaignNotification extends Notification implements SendsPushNotification
{
    use Queueable;

    public function __construct(public Campaign $campaign, public Store|Network|null $owner = null)
    {
        $this->owner ??= $campaign->resolveOwner();
    }

    /**
     * Campaigns are marketing: customers who turned promotions off receive nothing.
     */
    public function via($notifiable): array
    {
        if (!NotificationPreferences::allows($notifiable, 'promotions')) {
            return [];
        }

        $channels = $this->campaign->enabledChannels();
        $via      = [];
        if (in_array(Campaign::CHANNEL_PUSH, $channels, true)) {
            $via[] = StorefrontPushChannel::class;
        }
        if (in_array(Campaign::CHANNEL_INBOX, $channels, true)) {
            $via[] = 'database';
            $via[] = SafeBroadcastChannel::class;
        }

        return $via;
    }

    public function toPush($notifiable): ?PushMessage
    {
        return PushMessage::create($this->campaign->title, $this->campaign->body, $this->payload())
            ->image($this->imageUrl())
            ->analyticsLabel('campaign');
    }

    /**
     * The owner's app first, then (for a store) the apps of the networks it belongs to.
     */
    public function pushStorefronts(): array
    {
        $storefronts = array_filter([$this->owner]);

        if ($this->owner instanceof Store) {
            try {
                foreach ($this->owner->networks as $network) {
                    $storefronts[] = $network;
                }
            } catch (\Throwable $e) {
                // Network membership is optional for delivery.
            }
        }

        return array_values($storefronts);
    }

    public function toArray($notifiable): array
    {
        return [
            'title'   => $this->campaign->title,
            'body'    => $this->campaign->body,
            'subject' => $this->campaign->title,
            'message' => $this->campaign->body,
            'image'   => $this->imageUrl(),
            ...$this->payload(),
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return new BroadcastMessage(CustomerNotificationPresenter::present($this->toArray($notifiable), static::class));
    }

    public function broadcastType(): string
    {
        return 'campaign';
    }

    /**
     * Deep-link data shared by the push payload and the inbox copy.
     */
    protected function payload(): array
    {
        $owner  = $this->owner;
        $action = (array) ($this->campaign->action ?? []);

        return array_filter([
            'type'        => 'campaign',
            'campaign_id' => $this->campaign->public_id,
            'promotion_id'=> $this->campaign->promotion?->public_id,
            'store_id'    => $owner instanceof Store ? $owner->public_id : null,
            'network_id'  => $owner instanceof Network ? $owner->public_id : null,
            'action'      => $action['type'] ?? null,
            'action_id'   => $action['id'] ?? null,
            'action_url'  => $action['url'] ?? null,
        ], fn ($value) => $value !== null);
    }

    protected function imageUrl(): ?string
    {
        return $this->campaign->image_uuid ? $this->campaign->image?->url : null;
    }
}
