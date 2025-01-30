<?php

namespace Fleetbase\Storefront\Http\Filter;

use Fleetbase\FleetOps\Http\Filter\OrderFilter as FleetOpsOrderFilter;

class FoodTruckFilter extends FleetOpsOrderFilter
{
    public function queryForInternal()
    {
        $this->builder->where('company_uuid', $this->session->get('company'));
    }

    public function storefront($storefront)
    {
        $this->builder->whereHas(
            'store',
            function ($query) use ($storefront) {
                $query->where('public_id', $storefront);
            }
        );
    }
}
