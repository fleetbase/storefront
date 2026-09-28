<?php

namespace Fleetbase\Storefront\Http\Filter;

use Fleetbase\Http\Filter\Filter;

class PromotionCodeFilter extends Filter
{
    public function queryForInternal()
    {
        $this->builder->where('company_uuid', $this->session->get('company'));
    }

    public function promotion(string $promotion)
    {
        $this->builder->where('promotion_uuid', $promotion);
    }
}
