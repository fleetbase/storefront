<?php

namespace Fleetbase\Storefront\Notifications;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;

class StorefrontOrderDriverAssigned extends StorefrontOrderNotification
{
    /**
     * The driver assigned to the order.
     */
    public Driver $driver;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(Order $order)
    {
        parent::__construct($order);

        $this->driver  = $order->driverAssigned;
        $this->subject = 'Your driver is ' . $this->driver->name;
        $this->body    = 'A driver has been assigned to your order from ' . $this->storefront->name . ', they are currently en-route for pickup';
        $this->status  = 'order_driver_assigned';
    }
}
