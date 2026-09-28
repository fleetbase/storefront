<?php

namespace Fleetbase\Storefront\Notifications;

use Fleetbase\FleetOps\Models\Order;

class StorefrontOrderCompleted extends StorefrontOrderNotification
{
    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(Order $order)
    {
        parent::__construct($order);

        $this->subject = 'Your order from ' . $this->storefront->name . ' has been delivered';
        $this->body    = 'Your order from ' . $this->storefront->name . ' has been delivered, enjoy!';
        $this->status  = 'order_completed';
    }
}
