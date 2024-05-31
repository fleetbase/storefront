<?php

namespace Fleetbase\Storefront\Observers;

use Fleetbase\FleetOps\Models\Order;

class OrderObserver
{
    /**
     * Handle the Order "created" event.
     *
     * @return void
     */
    public function created(Order $order)
    {
    }
}
