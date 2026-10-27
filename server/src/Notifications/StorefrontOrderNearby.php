<?php

namespace Fleetbase\Storefront\Notifications;

use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Carbon;

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
        $this->subject  = $this->trans('order_nearby.subject');
        $this->body     = $this->trans('order_nearby.body', [
            'store' => $this->storefront->name,
            'time'  => Carbon::now()->locale($this->locale ?? app()->getLocale())->addSeconds($time)->longAbsoluteDiffForHumans(),
        ]);
        $this->status   = 'order_nearby';
    }
}
