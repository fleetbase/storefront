<?php

namespace Fleetbase\Storefront\Notifications;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Storefront\Support\Storefront;

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
        if (Storefront::isBookingOrder($order)) {
            $this->subject = $this->trans('order_driver_assigned.subject_booking', ['driver' => $this->driver->name]);
            $this->body    = $this->trans('order_driver_assigned.body_booking', ['driver' => $this->driver->name, 'store' => $this->storefront->name]);
        } else {
            $this->subject = $this->trans('order_driver_assigned.subject', ['driver' => $this->driver->name]);
            $this->body    = $this->trans('order_driver_assigned.body', ['store' => $this->storefront->name]);
        }
        $this->status  = 'order_driver_assigned';
    }
}
