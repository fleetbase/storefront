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

    public function queryForPublic()
    {
        $this->builder->where('company_uuid', $this->session->get('company'));
        $this->builder->whereHas('vehicle');
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

    /**
     * Trucks run by any store that is a member of the network.
     */
    public function network(?string $network)
    {
        $this->builder->whereHas(
            'store.networks',
            function ($query) use ($network) {
                $query->where('network_uuid', $network);
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

    public function withDeleted()
    {
        $this->builder->withTrashed();
    }
}
