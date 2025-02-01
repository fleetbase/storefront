<?php

namespace Fleetbase\Storefront\Http\Resources;

use Fleetbase\FleetOps\Http\Resources\v1\ServiceArea as ServiceAreaResource;
use Fleetbase\FleetOps\Http\Resources\v1\Vehicle as VehicleResource;
use Fleetbase\FleetOps\Http\Resources\v1\Zone as ZoneResource;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class FoodTruck extends FleetbaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id'                            => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'                          => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'                     => $this->when(Http::isInternalRequest(), $this->public_id),
            'company_uuid'                  => $this->when(Http::isInternalRequest(), $this->company_uuid),
            'created_by_uuid'               => $this->when(Http::isInternalRequest(), $this->created_by_uuid),
            'store_uuid'                    => $this->when(Http::isInternalRequest(), $this->store_uuid),
            'service_area_uuid'             => $this->when(Http::isInternalRequest(), $this->service_area_uuid),
            'zone_uuid'                     => $this->when(Http::isInternalRequest(), $this->zone_uuid),
            'vehicle_uuid'                  => $this->when(Http::isInternalRequest(), $this->vehicle_uuid),
            'vehicle'                       => $this->vehicle ? new VehicleResource($this->vehicle) : null,
            'service_area'                  => $this->serviceArea ? new ServiceAreaResource($this->serviceArea) : null,
            'zone'                          => $this->zone ? new ZoneResource($this->zone) : null,
            'catalogs'                      => Catalog::collection($this->catalogs ?? []),
            'online'                        => $this->vehicle ? $this->vehicle->online : false,
            'status'                        => $this->status,
            'created_at'                    => $this->created_at,
            'updated_at'                    => $this->updated_at,
        ];
    }
}
