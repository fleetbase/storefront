<?php

namespace Fleetbase\Storefront\Notifications;

use Fleetbase\FleetOps\Models\Order;

class StorefrontOrderPreparing extends StorefrontOrderNotification
{
    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(Order $order)
    {
        parent::__construct($order);

        $this->subject = 'Your order from ' . $this->storefront->name . ' is being prepared!';
        $this->body    = 'Your order is getting started.';
        $this->status  = 'order_preparing';
    }
}
