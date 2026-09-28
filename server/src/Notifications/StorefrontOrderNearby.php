<?php

namespace Fleetbase\Storefront\Notifications;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Support\Utils;

class StorefrontOrderNearby extends StorefrontOrderNotification
{
    /**
     * The distance the driver is from the customer.
     */
    public int $distance = 0;

    /**
     * The estimated time remaining before the driver reaches the customer.
     */
    public int $time = 0;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(Order $order, int $distance = 0, int $time = 0)
    {
        parent::__construct($order);

        $this->distance = $distance;
        $this->time     = $time;
        $this->subject  = 'Your order is nearby!';
        $this->body     = 'Your order from ' . $this->storefront->name . ' is reaching in ' . Utils::formatSeconds($time);
        $this->status   = 'order_nearby';
    }
}
