<?php

namespace Fleetbase\Storefront\Providers;

use Fleetbase\FleetOps\Providers\FleetOpsServiceProvider;
use Fleetbase\Providers\CoreServiceProvider;
use Fleetbase\Storefront\Support\StorefrontSocket;
use Fleetbase\Support\SocketCluster\SocketChannelRegistry;

// These dependency guards are only reachable before Composer can load this provider.
// The test runtime necessarily has both parent providers loaded, so the throw paths cannot execute.
// @codeCoverageIgnoreStart
if (!class_exists(CoreServiceProvider::class)) {
    throw new \Exception('Storefront cannot be loaded without `fleetbase/core-api` installed!');
}

if (!class_exists(FleetOpsServiceProvider::class)) {
    throw new \Exception('Storefront cannot be loaded without `fleetbase/fleetops-api` installed!');
}
// @codeCoverageIgnoreEnd

/**
 * Storefront service provider.
 */
class StorefrontServiceProvider extends CoreServiceProvider
{
    /**
     * The observers registered with the service provider.
     *
     * @var array
     */
    public $observers = [
        \Fleetbase\Storefront\Models\Product::class   => \Fleetbase\Storefront\Observers\ProductObserver::class,
        \Fleetbase\Storefront\Models\Network::class   => \Fleetbase\Storefront\Observers\NetworkObserver::class,
        \Fleetbase\Storefront\Models\Catalog::class   => \Fleetbase\Storefront\Observers\CatalogObserver::class,
        \Fleetbase\Storefront\Models\FoodTruck::class => \Fleetbase\Storefront\Observers\FoodTruckObserver::class,
        \Fleetbase\Models\Company::class              => \Fleetbase\Storefront\Observers\CompanyObserver::class,
    ];

    /**
     * The middleware groups registered with the service provider.
     *
     * @var array
     */
    public $middleware = [
        'storefront.api' => [
            \Fleetbase\Storefront\Http\Middleware\ThrottleRequests::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            \Fleetbase\Storefront\Http\Middleware\SetStorefrontSession::class,
            \Fleetbase\Http\Middleware\ConvertStringBooleans::class,
            \Fleetbase\Http\Middleware\SetGlobalHeaders::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \Fleetbase\Http\Middleware\LogApiRequests::class,
        ],
    ];

    /**
     * The console commands registered with the service provider.
     *
     * @var array
     */
    public $commands = [
        \Fleetbase\Storefront\Console\Commands\NotifyStorefrontOrderNearby::class,
        \Fleetbase\Storefront\Console\Commands\SendOrderNotification::class,
        \Fleetbase\Storefront\Console\Commands\PurgeExpiredCarts::class,
        \Fleetbase\Storefront\Console\Commands\MigrateStripeSandboxCustomers::class,
        \Fleetbase\Storefront\Console\Commands\ReleasePromotionReservations::class,
        \Fleetbase\Storefront\Console\Commands\DispatchCampaigns::class,
    ];

    /**
     * Register any application services.
     *
     * Within the register method, you should only bind things into the
     * service container. You should never attempt to register any event
     * listeners, routes, or any other piece of functionality within the
     * register method.
     *
     * More information on this can be found in the Laravel documentation:
     * https://laravel.com/docs/8.x/providers
     *
     * @return void
     */
    public function register()
    {
        $this->app->register(CoreServiceProvider::class);
        $this->app->register(FleetOpsServiceProvider::class);
    }

    /**
     * Bootstrap any package services.
     *
     * @return void
     *
     * @throws \Exception if the `fleetbase/core-api` package is not installed
     * @throws \Exception if the `fleetbase/fleetops-api` package is not installed
     */
    public function boot()
    {
        $this->registerCommands();
        $this->scheduleCommands(function ($schedule) {
            $schedule->command('storefront:notify-order-nearby')->everyMinute()->storeOutputInDb();
            $schedule->command('storefront:purge-carts')->daily()->storeOutputInDb();
            $schedule->command('storefront:release-promotion-reservations')->everyFifteenMinutes()->storeOutputInDb();
            $schedule->command('storefront:dispatch-campaigns')->everyMinute()->storeOutputInDb();
        });
        $this->registerObservers();
        $this->registerMiddleware();
        $this->registerExpansionsFrom(__DIR__ . '/../Expansions');
        $this->loadRoutesFrom(__DIR__ . '/../routes.php');
        $this->loadMigrationsFrom(__DIR__ . '/../../migrations');
        $this->mergeConfigFrom(__DIR__ . '/../../config/database.connections.php', 'database.connections');
        $this->mergeConfigFrom(__DIR__ . '/../../config/storefront.php', 'storefront');
        $this->mergeConfigFrom(__DIR__ . '/../../config/api.php', 'storefront.api');
        $this->registerStorefrontSocketChannels();
    }

    /**
     * Registers the realtime channel prefixes storefront owns (`storefront`, `checkout`)
     * with core-api's socket channel registry, so the socket server can authorize them.
     */
    public function registerStorefrontSocketChannels(): void
    {
        StorefrontSocket::registerChannels($this->app->make(SocketChannelRegistry::class));
    }
}
