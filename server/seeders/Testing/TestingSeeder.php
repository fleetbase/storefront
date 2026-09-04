<?php

namespace Fleetbase\Storefront\Seeders\Testing;

use Illuminate\Database\Seeder;

/**
 * Runs every Storefront testing seeder.
 *
 * Each seeder purges and recreates its own fixtures, so this entrypoint just orders
 * them. Run a single seeder directly when only one scenario is needed:
 *
 *   php artisan db:seed --class="Fleetbase\\Storefront\\Seeders\\Testing\\TestingSeeder"
 *   php artisan db:seed --class="Fleetbase\\Storefront\\Seeders\\Testing\\StoreSeeder"
 *   php artisan db:seed --class="Fleetbase\\Storefront\\Seeders\\Testing\\NetworkSeeder"
 */
class TestingSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            StoreSeeder::class,
            NetworkSeeder::class,
        ]);
    }
}
