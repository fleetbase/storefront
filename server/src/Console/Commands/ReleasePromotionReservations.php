<?php

namespace Fleetbase\Storefront\Console\Commands;

use Fleetbase\Storefront\Promotions\PromotionRedemptions;
use Illuminate\Console\Command;

class ReleasePromotionReservations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'storefront:release-promotion-reservations';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Release promotion uses reserved by checkouts that were never captured';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $released = PromotionRedemptions::releaseStale();

        $this->info('Released ' . $released . ' stale promotion reservation(s).');

        return 0;
    }
}
