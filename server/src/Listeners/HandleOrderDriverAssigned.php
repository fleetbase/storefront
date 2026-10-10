<?php

namespace Fleetbase\Storefront\Listeners;

use Fleetbase\FleetOps\Events\OrderDriverAssigned;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Storefront\Notifications\StorefrontOrderDriverAssigned;
use Fleetbase\Storefront\Support\OrderChat;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

class HandleOrderDriverAssigned implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the event.
     *
     * @param object $event
     *
     * @return void
     */
    public function handle(OrderDriverAssigned $event)
    {
        /** @var Order $order */
        $order = $event->getModelRecord();

        // halt if unable to resolve order record from event
        if (!$order instanceof Order) {
            return;
        }

        // if storefront order notify customer driver has been addigned
        if ($order->hasMeta('storefront_id')) {
            $order->load(['customer']);

            // Assigning and dispatching can both raise this event for the same driver; the
            // customer hears about each driver once (a reassignment is a new driver).
            $onceKey = 'storefront:driver-assigned:' . $order->uuid . ':' . ($order->driver_assigned_uuid ?? 'none');
            if ($order->customer && Cache::add($onceKey, true, now()->addDay())) {
                $order->customer->notify(new StorefrontOrderDriverAssigned($order));
            }

            // Start the order chat so it is waiting in the driver's chat list.
            try {
                OrderChat::open($order);
            } catch (\Throwable $e) {
                app(ExceptionHandler::class)->report($e);
            }
        }
    }
}
