<?php

use Fleetbase\FleetOps\Providers\FleetOpsServiceProvider;
use Fleetbase\Providers\CoreServiceProvider;
use Fleetbase\Storefront\Providers\StorefrontServiceProvider;

test('storefront provider registers its required Fleetbase providers', function () {
    $app = new class extends Fleetbase\TestSupport\ApplicationContainer {
        public array $registered = [];

        public function register($provider, $force = false)
        {
            $this->registered[] = $provider;

            return $provider;
        }
    };
    $provider = new StorefrontServiceProvider($app);

    $provider->register();

    expect($app->registered)->toBe([
        CoreServiceProvider::class,
        FleetOpsServiceProvider::class,
    ]);
});

test('storefront provider boot wires commands schedules observers middleware and package files', function () {
    $provider = new class(new Fleetbase\TestSupport\ApplicationContainer()) extends StorefrontServiceProvider {
        public array $calls = [];

        public function registerCommands(): void
        {
            $this->calls[] = 'commands';
        }

        public function scheduleCommands(?callable $callback = null): void
        {
            $this->calls[] = 'schedule';
            $schedule      = new class {
                public array $commands = [];

                public function command(string $command): self
                {
                    $this->commands[] = $command;

                    return $this;
                }

                public function everyMinute(): self
                {
                    return $this;
                }

                public function daily(): self
                {
                    return $this;
                }

                public function storeOutputInDb(): self
                {
                    return $this;
                }
            };
            $callback($schedule);
            $this->calls = [...$this->calls, ...$schedule->commands];
        }

        public function registerObservers(): void
        {
            $this->calls[] = 'observers';
        }

        public function registerMiddleware(): void
        {
            $this->calls[] = 'middleware';
        }

        public function registerExpansionsFrom($from = null, $namespace = null): void
        {
            $this->calls[] = 'expansions';
        }

        protected function loadRoutesFrom($path)
        {
            $this->calls[] = 'routes';
        }

        protected function loadMigrationsFrom($paths)
        {
            $this->calls[] = 'migrations';
        }

        protected function mergeConfigFrom($path, $key)
        {
            $this->calls[] = $key;
        }
    };

    $provider->boot();

    expect($provider->calls)->toBe([
        'commands',
        'schedule',
        'storefront:notify-order-nearby',
        'storefront:purge-carts',
        'observers',
        'middleware',
        'expansions',
        'routes',
        'migrations',
        'database.connections',
        'storefront',
        'storefront.api',
    ]);
});
