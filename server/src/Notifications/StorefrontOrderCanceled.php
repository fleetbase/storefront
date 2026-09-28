<?php

namespace Fleetbase\Storefront\Notifications;

use Fleetbase\FleetOps\Models\Order;

class StorefrontOrderCanceled extends StorefrontOrderNotification
{
    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(Order $order)
    {
        parent::__construct($order);

        $this->subject = 'Your order from ' . $this->storefront->name . ' was canceled';
        $this->body    = 'Your order from ' . $this->storefront->name . ' has been canceled, if your card has been charged you will be refunded.';
        $this->status  = 'order_canceled';
    }

    /**
     * Get the mail representation of the notification.
     *
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        return parent::toMail($notifiable)->line('No further action is necessary.');
    }
}
