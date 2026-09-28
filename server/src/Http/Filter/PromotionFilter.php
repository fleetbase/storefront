<?php

namespace Fleetbase\Storefront\Http\Filter;

use Fleetbase\Http\Filter\Filter;

class PromotionFilter extends Filter
{
    public function queryForInternal()
    {
        $this->builder->where('company_uuid', $this->session->get('company'));
    }

    /**
     * Promotions owned by a store or network (uuid).
     */
    public function owner(string $owner)
    {
        $this->builder->where('owner_uuid', $owner);
    }

    public function status(string $status)
    {
        $this->builder->where('status', $status);
    }

    public function type(string $type)
    {
        $this->builder->where('type', $type);
    }
}
