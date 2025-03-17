<?php

namespace Fleetbase\Storefront\Observers;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Storefront\Support\Storefront;

class OrderObserver
{
    /**
     * Handle the Order "created" event.
     *
     * @return void
     */
    public function creating(Order $order)
    {
        // Set the storefront order config
        $orderConfig = Storefront::getDefaultOrderConfig();
        if ($orderConfig) {
            $order->order_config_uuid = $orderConfig->uuid;
        }
    }
}
