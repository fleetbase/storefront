<?php

namespace Fleetbase\Storefront\Http\Filter;

use Fleetbase\Http\Filter\Filter;

class PromotionRedemptionFilter extends Filter
{
    public function queryForInternal()
    {
        $this->builder->where('company_uuid', $this->session->get('company'));
    }

    public function promotion(string $promotion)
    {
        $this->builder->where('promotion_uuid', $promotion);
    }

    public function customer(string $customer)
    {
        $this->builder->where('customer_uuid', $customer);
    }

    public function status(string $status)
    {
        $this->builder->where('status', $status);
    }

    /**
     * Redemptions of the promotions a store or network owns.
     */
    public function owner(string $owner)
    {
        $this->builder->whereHas('promotion', function ($query) use ($owner) {
            $query->where('owner_uuid', $owner);
        });
    }

    public function query(?string $searchQuery)
    {
        if (!$searchQuery) {
            return;
        }

        $this->builder->where(function ($query) use ($searchQuery) {
            $query->whereHas('promotion', fn ($promotion) => $promotion->where('name', 'like', '%' . $searchQuery . '%'))
                ->orWhereHas('code', fn ($code) => $code->where('code', 'like', '%' . $searchQuery . '%'));
        });
    }
}
