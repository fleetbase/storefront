<?php

namespace Fleetbase\Storefront\Notifications;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Support\Storefront;
use Fleetbase\Support\Utils;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\Twilio\TwilioChannel;
use NotificationChannels\Twilio\TwilioSmsMessage;

class StorefrontOrderCreated extends Notification
{
    use Queueable;

    /**
     * The order instance this notification is for.
     */
    public Order $order;

    /**
     * The order instance this notification is for.
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
     * Create a new notification instance.
     *
     * @return void
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
     */
    public function via($notifiable): array
    {
        return ['mail', TwilioChannel::class];
    }

    /**
     * Get the twilio sms representation of the notification.
     *
     * @return \NotificationChannels\Twilio\TwilioSmsMessage;
     */
    public function toTwilio($notifiable)
    {
        $storeName  = $this->storefront->name;
        $isPickup   = $this->order->getMeta('is_pickup');
        $isDelivery = !$isPickup;
        $method     = $isPickup ? 'pickup' : 'delivery';
        $items      = $this->order->payload->entities->map(function ($entity) {
            return $entity->name;
        })->join(',');
        $customerName    = $this->order->customer->name;
        $customerPhone   = $this->order->customer->phone;
        $deliveryAddress = $this->order->payload->dropoff->address;
        $subtotal        = $this->order->getMeta('subtotal');
        $deliveryFee     = $this->order->getMeta('delivery_fee');
        $tip             = $this->order->getMeta('tip');
        $deliveryTip     = $this->order->getMeta('delivery_tip');
        $total           = $this->order->getMeta('total');
        $currency        = $this->order->getMeta('currency');

        $content = '🚨 ' . $storeName . ' has received new order!' . "\n\n" .
            'A new ' . $method . ' order was just created!' . "\n\n" .
            'Customer: ' . $customerName . ' (' . $customerPhone . ')' . "\n" .
            'Items: ' . $items . "\n";

        if ($isDelivery) {
            $content .= 'Address: ' . $deliveryAddress . "\n";
            $content .= 'Delivery Fee: ' . Utils::moneyFormat($deliveryFee, $currency) . "\n";

            if ($deliveryTip) {
                $content .= 'Delivery Tip: ' . Utils::moneyFormat($deliveryTip, $currency) . "\n";
            }
        }

        if ($tip) {
            $content .= 'Tip: ' . Utils::moneyFormat($tip, $currency) . "\n";
        }

        $content .= 'Subtotal: ' . Utils::moneyFormat($subtotal, $currency) . "\n";
        $content .= 'Total: ' . Utils::moneyFormat($total, $currency) . "\n";

        return (new TwilioSmsMessage())->content($content);
    }

    /**
     * Get the mail representation of the notification.
     *
     * @return MailMessage
     */
    public function toMail($notifiable)
    {
        $storeName  = $this->storefront->name;
        $isPickup   = $this->order->getMeta('is_pickup');
        $isDelivery = !$isPickup;
        $method     = $isPickup ? 'pickup' : 'delivery';
        $items      = $this->order->payload->entities->map(function ($entity) {
            return $entity->name;
        })->join(',');
        $customerName    = $this->order->customer->name;
        $customerPhone   = $this->order->customer->phone;
        $deliveryAddress = $this->order->payload->dropoff->address;
        $subtotal        = $this->order->getMeta('subtotal');
        $deliveryFee     = $this->order->getMeta('delivery_fee');
        $tip             = $this->order->getMeta('tip');
        $deliveryTip     = $this->order->getMeta('delivery_tip');
        $total           = $this->order->getMeta('total');
        $currency        = $this->order->getMeta('currency');

        $message = (new MailMessage())
            ->subject('🚨 ' . $storeName . ' has received new order!')
            ->greeting('Hello!')
            ->line('A new ' . $method . ' order was just created!')
            ->line('Customer: ' . $customerName . ' (' . $customerPhone . ')')
            ->line('Items: ' . $items);

        if ($isDelivery) {
            $message->line('Address: ' . $deliveryAddress);
            $message->line('Delivery Fee: ' . Utils::moneyFormat($deliveryFee, $currency));

            if ($deliveryTip) {
                $message->line('Delivery Tip: ' . Utils::moneyFormat($deliveryTip, $currency));
            }
        }

        if ($tip) {
            $message->line('Tip: ' . Utils::moneyFormat($tip, $currency));
        }

        $message->line('Subtotal: ' . Utils::moneyFormat($subtotal, $currency));
        $message->line('Total: ' . Utils::moneyFormat($total, $currency));

        return $message;
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray($notifiable): array
    {
        return [...$this->order->toArray()];
    }
}
