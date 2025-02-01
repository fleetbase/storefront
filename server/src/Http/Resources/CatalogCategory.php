<?php

namespace Fleetbase\Storefront\Http\Resources;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class CatalogCategory extends FleetbaseResource
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
            'parent_uuid'                        => $this->when(Http::isInternalRequest(), $this->parent_uuid),
            'store_uuid'                         => $this->when(Http::isInternalRequest(), $this->store_uuid),
            'owner_uuid'                         => $this->when(Http::isInternalRequest(), $this->owner_uuid),
            'name'                               => $this->name,
            'description'                        => $this->description,
            'tags'                               => $this->tags ?? [],
            'meta'                               => $this->meta ?? [],
            'products'                           => CatalogProduct::collection($this->products ?? []),
            'for'                                => $this->for,
            'order'                              => $this->order,
            'created_at'                         => $this->created_at,
            'updated_at'                         => $this->updated_at,
        ];
    }
}
