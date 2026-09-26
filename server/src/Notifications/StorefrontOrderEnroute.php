<?php

namespace Fleetbase\Storefront\Notifications;

use Fleetbase\FleetOps\Models\Order;

class StorefrontOrderEnroute extends StorefrontOrderNotification
{
    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(Order $order)
    {
        parent::__construct($order);

        $this->subject = 'Your order from ' . $this->storefront->name . ' is on the way!';
        $this->body    = 'Your order from ' . $this->storefront->name . ' has been picked up, we will update you when your order is nearby';
        $this->status  = 'order_enroute';
    }
}
