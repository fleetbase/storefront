<?php

namespace Fleetbase\Storefront\Http\Controllers;

use Fleetbase\Storefront\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class StoreController extends StorefrontController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'store';

    /**
     * Eager-load what the store resource always serializes, so listing stores
     * does not run logo, backdrop and rating queries for every store.
     */
    public function onQueryRecord(Builder $builder, Request $request)
    {
        $builder->with(['logo', 'backdrop'])->withAvg('reviews', 'rating');

        if ($request->filled('network') && ($request->has('with_category') || $request->inArray('with', 'category'))) {
            $builder->with('networks');
        }
    }

    public function allStores(Request $request)
    {
        $stores = Store::select(['uuid', 'name', 'description', 'created_at'])
            ->where('company_uuid', $request->session()->get('company'))
            ->get();

        return response()->json(['stores' => $stores]);
    }
}
