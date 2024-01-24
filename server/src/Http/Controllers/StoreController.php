<?php

namespace Fleetbase\Storefront\Http\Controllers;

use Fleetbase\Storefront\Models\Store;
use Illuminate\Http\Request;

class StoreController extends StorefrontController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'store';

    public function allStores(Request $request)
    {
        $stores = Store::select(['uuid', 'name', 'description', 'created_at'])
            ->withoutRelations()->where('company_uuid', $request->session()->get('company'))
            ->get();

        return response()->json(['stores' => $stores]);
    }
}
