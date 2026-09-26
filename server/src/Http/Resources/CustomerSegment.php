<?php

namespace Fleetbase\Storefront\Http\Resources;

use Fleetbase\Http\Resources\FleetbaseResource;

class CustomerSegment extends FleetbaseResource
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
            'id'          => $this->uuid,
            'uuid'        => $this->uuid,
            'public_id'   => $this->public_id,
            'owner_uuid'  => $this->owner_uuid,
            'owner_type'  => $this->owner_type,
            'name'        => $this->name,
            'description' => $this->description,
            'rules'       => $this->rules ?? (object) [],
            'meta'        => $this->meta,
            'created_at'  => $this->created_at,
            'updated_at'  => $this->updated_at,
        ];
    }
}
