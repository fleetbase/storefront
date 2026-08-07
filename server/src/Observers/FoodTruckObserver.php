<?php

namespace Fleetbase\Storefront\Observers;

use Fleetbase\Storefront\Models\FoodTruck;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

class FoodTruckObserver
{
    /**
     * Handle the FoodTruck "saved" event.
     *
     * @param FoodTruck $foodTruck the FoodTruck that was saved
     */
    public function saved(FoodTruck $foodTruck): void
    {
        try {
            $catalogs = Request::input('foodTruck.catalogs', []);
            $foodTruck->setCatalogs($catalogs);
        } catch (\Throwable $e) {
            Log::error('Unable to synchronize food truck catalogs.', [
                'food_truck_uuid' => $foodTruck->uuid,
                'error'           => $e->getMessage(),
            ]);
        }
    }
}
