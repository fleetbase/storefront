<?php

namespace Fleetbase\Storefront\Http\Controllers;

use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Traits\ManagesAppReviewAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class StoreController extends StorefrontController
{
    use ManagesAppReviewAccess;

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

    /**
     * Issue the store a new storefront key. Apps using the old key stop working at once.
     *
     * @return \Illuminate\Http\Response
     */
    public function rotateKey(string $id, Request $request)
    {
        $store = Store::where('company_uuid', $request->session()->get('company'))
            ->where(fn ($query) => $query->where('uuid', $id)->orWhere('public_id', $id))
            ->first();

        if (!$store) {
            return response()->error('Store not found.', 404);
        }

        return response()->json(['status' => 'ok', 'key' => $store->rotateKey()]);
    }

    protected function appReviewOwner(string $id, Request $request): Store|Network|null
    {
        return Store::where('company_uuid', $request->session()->get('company'))
            ->where(fn ($query) => $query->where('uuid', $id)->orWhere('public_id', $id))
            ->first();
    }
}
