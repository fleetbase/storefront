<?php

namespace Fleetbase\Storefront\Notifications;

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

class PromotionalPushNotification extends Notification implements SendsPushNotification
{
    use Queueable;

    /**
     * The notification title.
     */
    public string $title;

    /**
     * The notification body.
     */
    public string $body;

    /**
     * The store instance.
     */
    public Store $store;

    /**
     * The time the notification was sent.
     */
    public string $sentAt;

    /**
     * The ID of the notification.
     */
    public string $notificationId;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(string $title, string $body, Store $store)
    {
        $this->title          = $title;
        $this->body           = $body;
        $this->store          = $store;
        $this->sentAt         = now()->toDateTimeString();
        $this->notificationId = uniqid('notification_');
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via($notifiable): array
    {
        if (!NotificationPreferences::allows($notifiable, 'promotions')) {
            return [];
        }

        return [StorefrontPushChannel::class, 'database', SafeBroadcastChannel::class];
    }

    /**
     * Realtime payload sent to the customer's `contact.{uuid}` channel.
     */
    public function toBroadcast($notifiable): BroadcastMessage
    {
        return new BroadcastMessage(CustomerNotificationPresenter::present($this->toArray($notifiable), static::class));
    }

    /**
     * The broadcast event uses this as the payload `type`, so keep it equal to the inbox item type.
     */
    public function broadcastType(): string
    {
        return 'promotional';
    }

    /**
     * Get the push notification representation of the notification.
     */
    public function toPush($notifiable): ?PushMessage
    {
        return PushMessage::create($this->title, $this->body, [
            'type'     => 'promotional',
            'store'    => $this->store->uuid,
            'store_id' => $this->store->public_id,
        ])->analyticsLabel('promotional');
    }

    /**
     * The store's own app first, then the apps of networks the store belongs to.
     */
    public function pushStorefronts(): array
    {
        $storefronts = [$this->store];

        try {
            foreach ($this->store->networks as $network) {
                $storefronts[] = $network;
            }
        } catch (\Throwable $e) {
            // Network membership is optional for promotional delivery.
        }

        return $storefronts;
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray($notifiable): array
    {
        return [
            'title'           => $this->title,
            'body'            => $this->body,
            'subject'         => $this->title,
            'message'         => $this->body,
            'store'           => $this->store->uuid,
            'store_id'        => $this->store->public_id,
            'type'            => 'promotional',
            'sent_at'         => $this->sentAt,
            'notification_id' => $this->notificationId,
        ];
    }
}
