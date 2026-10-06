<?php

namespace Fleetbase\Storefront\Notifications;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Models\ChatMessage;
use Fleetbase\Storefront\Notifications\Channels\SafeBroadcastChannel;
use Fleetbase\Storefront\Push\Contracts\SendsPushNotification;
use Fleetbase\Storefront\Push\PushCredentialResolver;
use Fleetbase\Storefront\Push\PushMessage;
use Fleetbase\Storefront\Push\StorefrontPushChannel;
use Fleetbase\Storefront\Support\CustomerNotificationPresenter;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a customer their driver sent a message about an order.
 *
 * Messages about an order in progress always reach the customer: they are not promotional
 * and do not depend on the order update preference.
 */
class StorefrontOrderChatMessage extends Notification implements SendsPushNotification
{
    use Queueable;

    public function __construct(public ChatMessage $message, public Order $order)
    {
    }

    public function via($notifiable): array
    {
        return [StorefrontPushChannel::class, 'database', SafeBroadcastChannel::class];
    }

    public function title(): string
    {
        $name = data_get($this->message, 'sender.user.name') ?: 'Your driver';

        return $name . ' sent a message';
    }

    public function body(): string
    {
        $content = trim((string) $this->message->content);

        return $content !== '' ? $content : 'Sent a photo';
    }

    public function toPush($notifiable): ?PushMessage
    {
        return PushMessage::create($this->title(), $this->body(), $this->payload())->analyticsLabel('storefront_order_chat');
    }

    public function pushStorefronts(): array
    {
        return PushCredentialResolver::storefrontsForOrder($this->order);
    }

    public function toArray($notifiable): array
    {
        return [
            'title'   => $this->title(),
            'body'    => $this->body(),
            'subject' => $this->title(),
            'message' => $this->body(),
            ...$this->payload(),
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return new BroadcastMessage(CustomerNotificationPresenter::present($this->toArray($notifiable), static::class));
    }

    public function broadcastType(): string
    {
        return 'order_chat_message';
    }

    /**
     * @return array<string, string>
     */
    protected function payload(): array
    {
        return array_filter([
            'type'         => 'order_chat_message',
            'order'        => $this->order->uuid,
            'order_id'     => $this->order->public_id,
            'chat_id'      => data_get($this->message, 'chatChannel.public_id'),
            'message_id'   => $this->message->public_id,
            'store_id'     => $this->order->getMeta('storefront_id'),
            'network_id'   => $this->order->getMeta('storefront_network_id'),
        ], fn ($value) => $value !== null);
    }
}
