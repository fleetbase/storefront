<?php

namespace Fleetbase\Storefront\Listeners;

use Fleetbase\Storefront\Support\Storefront;
use Fleetbase\FleetOps\Events\OrderStarted;
use Fleetbase\Storefront\Notifications\StorefrontOrderEnroute;

class HandleOrderStarted
{
    /**
     * Handle the event.
     *
     * @param object $event
     *
     * @return void
     */
    public function handle(OrderStarted $event)
    {
        /** @var \Fleetbase\FleetOps\Models\Order $order */
        $order = $event->getModelRecord();

        // Bookings are told about each step by the order activity observer, in booking terms.
        if (Storefront::isBookingOrder($order)) {
            return;
        }

        // if storefront order / notify customer driver has started and is en-route
        if ($order->hasMeta('storefront_id')) {
            $order->load(['customer']);

            if ($order->customer) {
                $order->customer->notify(new StorefrontOrderEnroute($order));
            }
        }
    }
}
