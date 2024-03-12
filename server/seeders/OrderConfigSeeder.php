<?php

namespace Fleetbase\Storefront\Seeders;

use Fleetbase\Models\Company;
use Fleetbase\Storefront\Support\Storefront;
use Illuminate\Database\Seeder;

class OrderConfigSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $companies = Company::all();
        foreach ($companies as $company) {
            Storefront::createStorefrontConfig($company);
        }
    }
}
