<?php

namespace Fleetbase\Storefront\Http\Filter;

use Fleetbase\Http\Filter\Filter;

class StoreFilter extends Filter
{
    public function queryForInternal()
    {
        $this->builder->where('company_uuid', $this->session->get('company'));
    }

    public function storeQuery(?string $searchQuery)
    {
        $this->builder->search($searchQuery);
    }

    public function network(?string $network)
    {
        $this->builder->whereHas(
            'networks',
            function ($query) use ($network) {
                $query->where('network_uuid', $network);

                // Query stores without a category
                if ($this->request->filled('without_category')) {
                    $query->whereNull('category_uuid');
                }

                // Query stores by category
                if ($this->request->filled('category')) {
                    if ($this->request->input('category') === '_parent') {
                        $query->whereNull('category_uuid');
                    } else {
                        $query->where('category_uuid', $this->request->input('category'));
                    }
                }
            }
        );
    }
}
