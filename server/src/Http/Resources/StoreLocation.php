<?php

namespace Fleetbase\Storefront\Http\Resources;

use Fleetbase\FleetOps\Http\Resources\v1\Place;
use Fleetbase\FleetOps\Http\Resources\Internal\v1\Place as InternalPlace;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class StoreLocation extends FleetbaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id' => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid' => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id' => $this->when(Http::isInternalRequest(), $this->public_id),
            'store' => data_get($this, 'store.public_id'),
            'store_data' => $this->when($request->boolean('with_store'), new Store($this->store)),
            'name' => $this->name,
            'place' =>  $this->when(Http::isInternalRequest(), new InternalPlace($this->place), new Place($this->place)),
            'hours' => StoreHour::collection($this->hours),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
