<?php

namespace Fleetbase\Storefront\Observers;

use Fleetbase\Models\Company;
use Fleetbase\Storefront\Support\Storefront;

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
        Storefront::createStorefrontConfig($company);
    }
}
