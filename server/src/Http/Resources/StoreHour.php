<?php

namespace Fleetbase\Storefront\Http\Resources;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class StoreHour extends FleetbaseResource
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
            'day_of_week' => $this->when(Http::isInternalRequest(), $this->day_of_week),
            'day' => $this->day_of_week,
            'start' => $this->start,
            'end' => $this->end,
            'created_at' => $this->when(Http::isInternalRequest(),  $this->created_at),
            'updated_at' => $this->when(Http::isInternalRequest(), $this->updated_at),
        ];
    }
}
