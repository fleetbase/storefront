<?php

namespace Fleetbase\Storefront\Http\Resources;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class Catalog extends FleetbaseResource
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
            'id'                                 => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'                               => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'                          => $this->when(Http::isInternalRequest(), $this->public_id),
            'company_uuid'                       => $this->when(Http::isInternalRequest(), $this->company_uuid),
            'created_by_uuid'                    => $this->when(Http::isInternalRequest(), $this->created_by_uuid),
            'store_uuid'                         => $this->when(Http::isInternalRequest(), $this->store_uuid),
            'name'                               => $this->name,
            'description'                        => $this->description,
            'categories'                         => CatalogCategory::collection($this->categories ?? []),
            'status'                             => $this->status,
            'created_at'                         => $this->created_at,
            'updated_at'                         => $this->updated_at,
        ];
    }
}
