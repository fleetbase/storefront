<?php

namespace Fleetbase\FleetOps\Observers;

use Fleetbase\Models\Company;
use Fleetbase\Storefront\Seeders\OrderConfigSeeder;

class CompanyObserver
{
    /**
     * Handle the Company "created" event.
     *
     * @return void
     */
    public function created(Company $company)
    {
        // Add the default storefront order config
        OrderConfigSeeder::createStorefrontConfig($company);
    }
}
