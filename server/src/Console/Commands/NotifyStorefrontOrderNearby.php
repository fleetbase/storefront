<?php

namespace Fleetbase\Storefront\Console\Commands;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Storefront\Notifications\StorefrontOrderNearby;
use Illuminate\Console\Command;

class NotifyStorefrontOrderNearby extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'storefront:notify-order-nearby';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Notifies customer when their order is nearby/ reaching';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Get storefront orders that are enroute
        $orders = $this->getActiveStorefrontOrders();

        // Notify and update 
        $this->alert('Found (' . $orders->count() . ') Storefront Orders which are Enroute.');

        // Iterate each order
        $orders->each(
            function ($order) {
                $origin = $order->payload->getPickupOrFirstWaypoint();
                $destination = $order->payload->getDropoffOrLastWaypoint();
                $matrix = Utils::getDrivingDistanceAndTime($origin, $destination);
                $distance = $matrix->distance;
                $time = $matrix->time;

                if (!$distance || !$time) {
                    return;
                }

                $this->line('Order ' . $order->public_id . ' is ' . Utils::formatSeconds($time) . ' from being delivered');
                $this->line('Order ' . $order->public_id . ' distance is ' . Utils::formatMeters($distance) . ' away');

                if (round($distance) < 1500 && $order->missingMeta('storefront_order_nearby')) {
                    $this->line('Order ' . $order->public_id . ' is nearby');
                    $order->customer->notify(new StorefrontOrderNearby($order, $distance, $time));
                    $order->updateMeta('storefront_order_nearby', true);
                }
            }
        );
    }

    /**
     * Fetches active storefront orders based on certain criteria.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getActiveStorefrontOrders(): \Illuminate\Database\Eloquent\Collection
    {
        return Order::where(
            [
                'status' => 'driver_enroute',
                'type' => 'storefront',
                'dispatched' => true
            ]
        )->with(
            [
                'payload',
                'customer'
            ]
        )
            ->withoutGlobalScopes()
            ->get();
    }
}
