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
}
