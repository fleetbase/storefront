<?php

namespace Fleetbase\Storefront\Http\Filter;

use Fleetbase\Http\Filter\Filter;

class CustomerSegmentFilter extends Filter
{
    public function queryForInternal()
    {
        $this->builder->where('company_uuid', $this->session->get('company'));
    }

    public function owner(string $owner)
    {
        $this->builder->where('owner_uuid', $owner);
    }
}
