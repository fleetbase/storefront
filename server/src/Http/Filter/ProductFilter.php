<?php

namespace Fleetbase\Storefront\Http\Filter;

use Fleetbase\Http\Filter\Filter;
use Fleetbase\Models\Category;

class ProductFilter extends Filter
{
    public function queryForInternal()
    {
        $this->builder->where('company_uuid', $this->session->get('company'));
    }

    public function query(?string $query)
    {
        $this->builder->search($query);
    }

    public function categorySlug(?string $categorySlug)
    {
        $category = Category::where(['slug' => $categorySlug, 'for' => 'storefront_product'])->first();

        if ($category) {
            $this->builder->where('category_uuid', $category->uuid);
        }
    }

    public function status(string|array $status)
    {
        $statuses = is_array($status) ? $status : array_filter(explode(',', $status));

        if ($statuses) {
            $this->builder->whereIn('status', $statuses);
        }
    }

    public function category(?string $category)
    {
        $this->builder->where('category_uuid', $category);
    }

    /**
     * Products with no category: the ones a draft card tells the operator to file.
     */
    public function uncategorized($flag)
    {
        if (filter_var($flag, FILTER_VALIDATE_BOOLEAN)) {
            $this->builder->whereNull('category_uuid');
        }
    }

    public function available($flag)
    {
        $this->builder->where('is_available', filter_var($flag, FILTER_VALIDATE_BOOLEAN));
    }

    public function onSale($flag)
    {
        $this->builder->where('is_on_sale', filter_var($flag, FILTER_VALIDATE_BOOLEAN));
    }

    public function recommended($flag)
    {
        $this->builder->where('is_recommended', filter_var($flag, FILTER_VALIDATE_BOOLEAN));
    }
}
