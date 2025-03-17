<?php

namespace Fleetbase\Storefront\Console\Commands;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\Utils;
use Illuminate\Console\Command;

class SendOrderNotification extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'storefront:send-notification {--id= : The ID of the order} {--event= : The name of the event to trigger}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manually trigger a push notification for an order';

    /**
     * Mapping of event names to notification classes.
     *
     * @var array
     */
    protected $eventToNotification = [
        'created'          => \Fleetbase\Storefront\Notifications\StorefrontOrderCreated::class,
        'canceled'         => \Fleetbase\Storefront\Notifications\StorefrontOrderCanceled::class,
        'completed'        => \Fleetbase\Storefront\Notifications\StorefrontOrderCompleted::class,
        'driver_assigned'  => \Fleetbase\Storefront\Notifications\StorefrontOrderDriverAssigned::class,
        'enroute'          => \Fleetbase\Storefront\Notifications\StorefrontOrderEnroute::class,
        'preparing'        => \Fleetbase\Storefront\Notifications\StorefrontOrderPreparing::class,
        'ready_for_pickup' => \Fleetbase\Storefront\Notifications\StorefrontOrderReadyForPickup::class,
        'nearby'           => \Fleetbase\Storefront\Notifications\StorefrontOrderNearby::class,
    ];

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Get order ID and event from options
        $orderId = $this->option('id');
        $event   = $this->option('event');

        // Prompt user to search for order ID if not provided
        if (!$orderId) {
            $orderId = $this->ask('Enter the order ID to trigger the notification');
        }

        // Attempt to find the order
        $order = Order::where('public_id', $orderId)->first();

        if (!$order) {
            $this->error('Order not found!');

            return 1;
        }

        // Load customer relation
        $order->loadMissing('customer');

        if (!$order->customer) {
            $this->error('Order does not have an associated customer!');

            return 1;
        }

        // Prompt user to select event if not provided
        if (!$event) {
            $event = $this->choice(
                'Select the event to trigger',
                array_keys($this->eventToNotification),
                'created' // Default event
            );
        }

        // Resolve notification class
        $notificationClass = $this->eventToNotification[$event] ?? null;

        if (!$notificationClass) {
            $this->error('Invalid event selected!');

            return 1;
        }

        // nearby notification requires more arguments
        try {
            if ($event === 'nearby') {
                $origin      = $order->payload->getPickupOrFirstWaypoint();
                $destination = $order->payload->getDropoffOrLastWaypoint();
                $matrix      = Utils::getDrivingDistanceAndTime($origin, $destination);
                $distance    = $matrix->distance;
                $time        = $matrix->time;

                // Trigger notification
                $order->customer->notify(new $notificationClass($order, $distance, $time));
            } else {
                // Trigger notification
                $order->customer->notify(new $notificationClass($order));
            }
        } catch (\Exception $e) {
            $this->error($e->getMessage());

            return 0;
        }

        $this->info("Notification '{$event}' has been triggered for order ID '{$orderId}'.");

        return 0;
    }
}
