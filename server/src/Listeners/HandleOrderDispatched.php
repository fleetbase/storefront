<?php

namespace Fleetbase\Storefront\Listeners;

use Fleetbase\Storefront\Support\Storefront;
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

        // Bookings are told about each step by the order activity observer, in booking terms.
        if (Storefront::isBookingOrder($order)) {
            return;
        }

        // notufy customer order is ready for pickup
        if ($order->isMeta('is_pickup')) {
            $order->load(['customer']);

            if ($order->customer) {
                $order->customer->notify(new StorefrontOrderReadyForPickup($order));
            }
        }
    }
}
