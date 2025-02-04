<?php

namespace Fleetbase\Storefront\Http\Controllers\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Storefront\Http\Resources\FoodTruck as FoodTruckResource;
use Fleetbase\Storefront\Models\FoodTruck;
use Illuminate\Http\Request;

class FoodTruckController extends Controller
{
    /**
     * Query for Food Truck resources.
     *
     * @return \Illuminate\Http\Response
     */
    public function query(Request $request)
    {
        $limit   = $request->input('limit', false);
        $offset  = $request->input('offset', false);
        $results = [];

        if (session('storefront_store')) {
            $results = FoodTruck::queryWithRequest($request, function (&$query) use ($limit, $offset) {
                $query->where('store_uuid', session('storefront_store'));

                if ($limit) {
                    $query->limit($limit);
                }

                if ($offset) {
                    $query->offset($offset);
                }
            });
        }

        return FoodTruckResource::collection($results);
    }
}
