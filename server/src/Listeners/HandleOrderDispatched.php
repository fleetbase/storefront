<?php

namespace Fleetbase\Storefront\Listeners;

use Fleetbase\FleetOps\Events\OrderDispatched;
use Fleetbase\Storefront\Notifications\StorefrontOrderReadyForPickup;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class HandleOrderDispatched implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the event.
     *
     * @param object $event
     *
     * @return void
     */
    public function handle(OrderDispatched $event)
    {
        /** @var \Fleetbase\FleetOps\Models\Order $order */
        $order = $event->getModelRecord();

        // notufy customer order is ready for pickup
        if ($order->isMeta('is_pickup')) {
            $order->load(['customer']);

            if ($order->customer) {
                $order->customer->notify(new StorefrontOrderReadyForPickup($order));
            }
        }
    }
}
