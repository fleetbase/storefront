<?php

namespace Fleetbase\Storefront\Listeners;

use Fleetbase\Storefront\Support\Storefront;
use Fleetbase\FleetOps\Events\OrderCompleted;
use Fleetbase\Storefront\Notifications\StorefrontOrderCompleted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class HandleOrderCompleted implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the event.
     *
     * @param object $event
     *
     * @return void
     */
    public function handle(OrderCompleted $event)
    {
        /** @var \Fleetbase\FleetOps\Models\Order $order */
        $order = $event->getModelRecord();

        // Bookings are told about each step by the order activity observer, in booking terms.
        if (Storefront::isBookingOrder($order)) {
            return;
        }

        // if storefront order notify customer driver has been addigned
        if ($order->hasMeta('storefront_id')) {
            $order->load(['customer']);

            if ($order->customer) {
                $order->customer->notify(new StorefrontOrderCompleted($order));
            }
        }
    }
}
