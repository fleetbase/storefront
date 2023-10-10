<?php

namespace Fleetbase\Storefront\Http\Filter;

use Fleetbase\Http\Filter\Filter;

class StoreLocationFilter extends Filter
{
    public function queryForInternal()
    {
        $this->builder->whereHas(
            'store',
            function ($query) {
                $query->where('company_uuid', $this->session->get('company'));
            }
        );
    }

    public function store(string $store)
    {
        $this->builder->where('store_uuid', $store);
    }
}
