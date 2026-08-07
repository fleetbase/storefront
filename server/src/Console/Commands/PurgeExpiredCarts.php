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
        $dbConnection = DB::connection(config('storefront.connection.db'));
        $schema       = $dbConnection->getSchemaBuilder();

        $schema->disableForeignKeyConstraints();

        try {
            $dbDeletedCount = $dbConnection->table('carts')
                ->where('expires_at', '<', now())
                ->delete();
        } finally {
            $schema->enableForeignKeyConstraints();
        }

        // Log output
        $this->info("Successfully deleted {$dbDeletedCount} expired carts.");

        return Command::SUCCESS;
    }
}
