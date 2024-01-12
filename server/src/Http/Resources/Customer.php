<?php

namespace Fleetbase\Storefront\Http\Resources;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;
use Illuminate\Support\Str;

class Customer extends FleetbaseResource
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
            'id'          => $this->when(Http::isInternalRequest(), $this->id, Str::replaceFirst('contact', 'customer', $this->public_id)),
            'uuid'        => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'   => $this->when(Http::isInternalRequest(), $this->public_id),
            'internal_id' => $this->internal_id,
            'name'        => $this->name,
            'photo_url'   => $this->photo_url,
            'email'       => $this->email,
            'phone'       => $this->phone,
            'address'     => data_get($this, 'address.address'),
            'addresses'   => $this->addresses,
            'token'       => $this->when($this->token, $this->token),
            'slug'        => $this->slug,
            'created_at'  => $this->created_at,
            'updated_at'  => $this->updated_at,
        ];
    }
}
