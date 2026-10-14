<?php

namespace Fleetbase\Storefront\Console\Commands;

use Fleetbase\Storefront\Models\Cart;
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
    protected $description = 'Permanently delete expired carts that are still open and empty';

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
            // Only expired carts that are still open and hold nothing. Checked out and
            // cleared carts, and abandoned carts with items, are the record of what was
            // bought or wanted, so they are kept.
            $dbDeletedCount = $dbConnection->table('carts')
                ->where('expires_at', '<', now())
                ->whereNull('checkout_uuid')
                ->where(function ($query) {
                    $query->whereNull('status')->orWhere('status', 'open');
                })
                ->where(function ($query) {
                    $query->whereNull('items')->orWhereIn('items', ['[]', '{}', '', 'null']);
                })
                ->delete();
        } finally {
            $schema->enableForeignKeyConstraints();
        }

        // The rows were deleted through the query builder without model events and span
        // companies, so the table wide cart cache version is advanced (which every
        // company's cart caches read from fleetbase/core-api#301 on).
        if ($dbDeletedCount > 0) {
            Cart::invalidateApiCacheManually();
        }

        // Log output
        $this->info("Successfully deleted {$dbDeletedCount} expired carts.");

        return Command::SUCCESS;
    }
}
