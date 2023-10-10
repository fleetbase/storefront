<?php

namespace Fleetbase\Storefront\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        /**
         * Order Events
         */
        \Fleetbase\FleetOps\Events\OrderStarted::class => [\Fleetbase\Storefront\Listeners\HandleOrderStarted::class],
        \Fleetbase\FleetOps\Events\OrderDispatched::class => [\Fleetbase\Storefront\Listeners\HandleOrderDispatched::class],
        \Fleetbase\FleetOps\Events\OrderCompleted::class => [\Fleetbase\Storefront\Listeners\HandleOrderCompleted::class],
        \Fleetbase\FleetOps\Events\OrderDriverAssigned::class => [\Fleetbase\Storefront\Listeners\HandleOrderDriverAssigned::class],
    ];
}
