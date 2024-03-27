<?php

namespace Fleetbase\Storefront\Http\Resources;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class NotificationChannel extends FleetbaseResource
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
            'id'             => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'           => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'      => $this->when(Http::isInternalRequest(), $this->public_id),
            'name'           => $this->name,
            'scheme'         => $this->scheme,
            'options'        => $this->options,
            'is_apn_gateway' => $this->is_apn_gateway,
            'is_fcm_gateway' => $this->is_fcm_gateway,
            'created_at'     => $this->created_at,
            'updated_at'     => $this->updated_at,
        ];
    }
}
