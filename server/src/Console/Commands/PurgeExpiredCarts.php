<?php

namespace Fleetbase\Storefront\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PurgeExpiredCarts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'storefront:purge-carts';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Permanently delete all expired carts from the database';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Alternatively, using the DB facade for direct deletion
        $dbDeletedCount = DB::table('carts')->where('expires_at', '<', now())->delete();

        // Log and output the results
        $this->info("Successfully deleted {$dbDeletedCount} expired carts.");

        return Command::SUCCESS;
    }
}
