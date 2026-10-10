<?php

namespace Fleetbase\Storefront\Http\Controllers\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Storefront\Http\Resources\FoodTruck as FoodTruckResource;
use Fleetbase\Storefront\Models\FoodTruck;
use Fleetbase\Storefront\Models\Network;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
        $limit    = $request->input('limit', false);
        $offset   = $request->input('offset', false);
        $storeIds = static::storeUuidsForStorefront();
        if (empty($storeIds)) {
            return FoodTruckResource::collection([]);
        }

        $results = FoodTruck::queryWithRequestCached($request, function (&$query) use ($limit, $offset, $storeIds) {
            $query->whereIn('store_uuid', $storeIds)->with(['store', 'vehicle', 'zone', 'serviceArea', 'catalogs']);

            if ($limit) {
                $query->limit($limit);
            }

            if ($offset) {
                $query->offset($offset);
            }
        });

        return FoodTruckResource::collection($results);
    }

    /**
     * The stores whose food trucks this storefront shows: a store's own, or in a network
     * every member store's, so a network can run trucks alongside its stores.
     *
     * @return array<string>
     */
    protected static function storeUuidsForStorefront(): array
    {
        if (session('storefront_store')) {
            return [session('storefront_store')];
        }

        if (session('storefront_network')) {
            $network = Network::where('uuid', session('storefront_network'))->first();

            return $network ? $network->stores()->pluck('stores.uuid')->all() : [];
        }

        return [];
    }

    /**
     * Finds a single Storefront FoodTruck resources.
     *
     * @return \Fleetbase\Http\Resources\EntityCollection
     */
    public function find($id)
    {
        // find for the food truck
        try {
            $foodTruck = FoodTruck::findRecordOrFail($id);
        } catch (ModelNotFoundException $exception) {
            return response()->error('Food Truck resource not found.');
        }

        // Only a truck of this storefront (its own, or a network member store's).
        if (!in_array($foodTruck->store_uuid, static::storeUuidsForStorefront(), true)) {
            return response()->error('Food Truck resource not found.', 404);
        }

        $foodTruck->loadMissing(['store', 'vehicle', 'zone', 'serviceArea', 'catalogs']);

        // response the product resource
        return new FoodTruckResource($foodTruck);
    }
}
