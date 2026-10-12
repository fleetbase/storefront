<?php

namespace Fleetbase\Storefront\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;

/**
 * Redemptions the API already records for every promotion use; this is their first screen.
 */
class PromotionRedemptionController extends StorefrontController
{
    public $resource = 'promotion_redemption';

    public function onQueryRecord(Builder $builder)
    {
        $builder->with(['promotion', 'code', 'customer', 'order']);
    }
}
