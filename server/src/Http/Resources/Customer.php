<?php

namespace Fleetbase\Storefront\Http\Resources;

use Fleetbase\FleetOps\Http\Resources\v1\Place;
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
        $this->loadMissing(['place', 'places']);

        return [
            'id'                   => $this->when(Http::isInternalRequest(), $this->id, Str::replaceFirst('contact', 'customer', $this->public_id)),
            'uuid'                 => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'            => $this->when(Http::isInternalRequest(), $this->public_id),
            'address_id'           => $this->place ? $this->place->public_id : null,
            'internal_id'          => $this->internal_id,
            'name'                 => $this->name,
            'photo_url'            => $this->photo_url,
            'email'                => $this->email,
            'phone'                => $this->phone,
            'address'              => data_get($this, 'place.address'),
            'addresses'            => $this->whenLoaded('places', Place::collection($this->places)),
            'token'                => $this->when($this->token, $this->token),
            'meta'                 => $this->meta ?? [],
            'slug'                 => $this->slug,
            'created_at'           => $this->created_at,
            'updated_at'           => $this->updated_at,
        ];
    }
}
