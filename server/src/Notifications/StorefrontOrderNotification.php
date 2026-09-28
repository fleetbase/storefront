<?php

namespace Fleetbase\Storefront\Notifications;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Notifications\Channels\SafeBroadcastChannel;
use Fleetbase\Storefront\Push\Contracts\SendsPushNotification;
use Fleetbase\Storefront\Push\PushCredentialResolver;
use Fleetbase\Storefront\Push\PushMessage;
use Fleetbase\Storefront\Push\StorefrontPushChannel;
use Fleetbase\Storefront\Support\CustomerNotificationPresenter;
use Fleetbase\Storefront\Support\NotificationPreferences;
use Fleetbase\Storefront\Support\Storefront;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Base class for order lifecycle notifications sent to storefront customers.
 */
abstract class StorefrontOrderNotification extends Notification implements SendsPushNotification
{
    use Queueable;

    /**
     * The order instance this notification is for.
     */
    public Order $order;

    /**
     * The storefront the order was placed with.
     */
    public Store|Network $storefront;

    /**
     * The time the notification was sent.
     */
    public string $sentAt;

    /**
     * The ID of the notification.
     */
    public string $notificationId;

    /**
     * The notification subject.
     */
    public string $subject;

    /**
     * The notification body.
     */
    public string $body;

    /**
     * The notification order status.
     */
    public string $status;

    /**
     * Create a new notification instance.
     */
    public function __construct(Order $order)
    {
        $this->order          = $order;
        $this->storefront     = Storefront::findAbout($order->getMeta('storefront_id'));
        $this->sentAt         = now()->toDateTimeString();
        $this->notificationId = uniqid('notification_');
    }

    /**
     * Get the notification's delivery channels.
     *
     * Push is sent first and never throws, so mail or database failures cannot stop it. The
     * customer can turn order update pushes off; the inbox copy is always kept.
     */
    public function via($notifiable): array
    {
        $channels = [];
        if (NotificationPreferences::allows($notifiable, 'order_updates')) {
            $channels[] = StorefrontPushChannel::class;
        }

        return [...$channels, 'database', 'mail', SafeBroadcastChannel::class];
    }

    /**
     * Realtime payload sent to the customer's `contact.{uuid}` channel. Mirrors an inbox item,
     * without the recipient details stored in the database copy.
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
        return $this->status;
    }

    /**
     * Get the mail representation of the notification.
     *
     * @return MailMessage
     */
    public function toMail($notifiable)
    {
        return (new MailMessage())
            ->subject($this->subject)
            ->line($this->body);
    }

    /**
     * Get the push notification representation of the notification.
     */
    public function toPush($notifiable): ?PushMessage
    {
        return PushMessage::create($this->subject, $this->body, [
            'type'       => $this->status,
            'order'      => $this->order->uuid,
            'id'         => $this->order->public_id,
            'store_id'   => $this->order->getMeta('storefront_id'),
            'network_id' => $this->order->getMeta('storefront_network_id'),
        ])->analyticsLabel('storefront_order');
    }

    /**
     * The storefronts whose push credentials may deliver this notification.
     */
    public function pushStorefronts(): array
    {
        $storefronts = PushCredentialResolver::storefrontsForOrder($this->order);

        if (empty($storefronts) && isset($this->storefront)) {
            $storefronts[] = $this->storefront;
        }

        return $storefronts;
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray($notifiable): array
    {
        $this->order->loadMissing(['customer', 'company']);
        $customer = $this->order->customer;
        $company  = $this->order->company;

        return [
            'notifiable'      => data_get($notifiable, 'public_id'),
            'notification_id' => $this->notificationId,
            'sent_at'         => $this->sentAt,
            'type'            => $this->status,
            'title'           => $this->subject,
            'body'            => $this->body,
            'subject'         => $this->subject,
            'message'         => $this->body,
            'order'           => $this->order->uuid,
            'order_id'        => $this->order->public_id,
            'storefront'      => $this->storefront->name,
            'storefront_id'   => $this->storefront->public_id,
            'store_id'        => $this->order->getMeta('storefront_id'),
            'network_id'      => $this->order->getMeta('storefront_network_id'),
            'id'              => data_get($customer, 'public_id'),
            'email'           => data_get($customer, 'email'),
            'phone'           => data_get($customer, 'phone'),
            'companyId'       => data_get($company, 'public_id'),
            'company'         => data_get($company, 'name'),
        ];
    }
}
