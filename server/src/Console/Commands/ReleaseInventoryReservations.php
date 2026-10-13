<?php

namespace Fleetbase\Storefront\Console\Commands;

use Fleetbase\Storefront\Support\Inventory;
use Illuminate\Console\Command;

class ReleaseInventoryReservations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'storefront:release-inventory';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Give back stock held by checkouts that were never paid';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $released = Inventory::releaseExpired();

        $this->info('Released ' . $released . ' expired stock reservation(s).');

        return 0;
    }
}
