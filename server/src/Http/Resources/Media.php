<?php

namespace Fleetbase\Storefront\Http\Resources;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class Media extends FleetbaseResource
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
            'filename' => data_get($this, 'original_filename'),
            'type' => data_get($this, 'content_type'),
            'caption' => data_get($this, 'caption'),
            'url' => data_get($this, 'url'),
            'created_at' => $this->when(Http::isInternalRequest(),  $this->created_at),
            'updated_at' => $this->when(Http::isInternalRequest(), $this->updated_at),
        ];
    }
}
