<?php

namespace Fleetbase\Storefront\Observers;

use Fleetbase\Models\ChatMessage;
use Fleetbase\Storefront\Notifications\StorefrontOrderChatMessage;
use Fleetbase\Storefront\Support\OrderChat;

/**
 * Pushes driver messages in an order chat to the customer's storefront app.
 *
 * Core notifies chat participants through their user's devices. Customers' devices are
 * registered with the storefront instead, so storefront sends its own notification.
 */
class ChatMessageObserver
{
    public function created(ChatMessage $message): void
    {
        $channel = $message->chatChannel;
        if (!$channel || !data_get($channel->meta, OrderChat::META_ORDER_UUID)) {
            return;
        }

        $order = OrderChat::orderFor($channel);
        if (!$order) {
            return;
        }

        $customer = $order->customer;
        $sender   = $message->sender;
        if (!$customer || !$sender || $sender->user_uuid === data_get($customer, 'user_uuid')) {
            return;
        }

        $customer->notify(new StorefrontOrderChatMessage($message, $order));
    }
}
