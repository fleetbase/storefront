<?php

namespace Fleetbase\Storefront\Http\Filter;

use Fleetbase\FleetOps\Http\Filter\OrderFilter as FleetOpsOrderFilter;
use Fleetbase\FleetOps\Models\ServiceArea;

class FoodTruckFilter extends FleetOpsOrderFilter
{
    public function queryForInternal()
    {
        $this->builder->where('company_uuid', $this->session->get('company'));
    }

    public function storefront($storefront)
    {
        $this->builder->whereHas(
            'store',
            function ($query) use ($storefront) {
                $query->where('public_id', $storefront);
            }
        );
    }

    public function serviceArea(string $serviceAreaId)
    {
        $matchingServiceAreaIds = ServiceArea::on(config('fleetbase.connection.db'))
            ->where(function ($query) use ($serviceAreaId) {
                $query->where('public_id', $serviceAreaId)
                    ->orWhere('uuid', $serviceAreaId);
            })
            ->pluck('uuid')
            ->toArray();

        $this->builder->whereIn('service_area_uuid', $matchingServiceAreaIds);
    }
}
