<?php

namespace Fleetbase\Storefront\Http\Filter;

use Fleetbase\FleetOps\Http\Filter\ContactFilter;

class CustomerFilter extends ContactFilter
{
    public function storefront($storefront)
    {
        $this->builder->whereHas(
            'customerOrders',
            function ($query) use ($storefront) {
                $query->where('meta->storefront_id', $storefront);
            }
        );
    }
}
