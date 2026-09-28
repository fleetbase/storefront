<?php

namespace Fleetbase\Storefront\Http\Filter;

use Fleetbase\Http\Filter\Filter;

class CampaignFilter extends Filter
{
    public function queryForInternal()
    {
        $this->builder->where('company_uuid', $this->session->get('company'));
    }

    public function owner(string $owner)
    {
        $this->builder->where('owner_uuid', $owner);
    }

    public function status(string $status)
    {
        $this->builder->where('status', $status);
    }

    public function promotion(string $promotion)
    {
        $this->builder->where('promotion_uuid', $promotion);
    }
}
