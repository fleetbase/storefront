<?php

namespace Fleetbase\Storefront\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;

class FoodTruckController extends StorefrontController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'food_truck';

    /**
     * Additional query hook.
     */
    public function onQueryRecord(Builder $builder)
    {
        $builder->with(['vehicle']);
    }
}
