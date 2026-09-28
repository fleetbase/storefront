<?php

namespace Fleetbase\Storefront\Notifications;

use Fleetbase\FleetOps\Models\Order;

class StorefrontOrderAccepted extends StorefrontOrderNotification
{
    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(Order $order)
    {
        parent::__construct($order);

        $this->subject = 'Your order from ' . $this->storefront->name . ' has been accepted.';
        $this->body    = 'Your order was accepted.';
        $this->status  = 'order_accepted';
    }
}
