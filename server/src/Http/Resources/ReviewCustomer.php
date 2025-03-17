<?php

namespace Fleetbase\Storefront\Http\Resources;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;
use Illuminate\Support\Str;

class ReviewCustomer extends FleetbaseResource
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
            'id'            => $this->when(Http::isInternalRequest(), $this->id, Str::replaceFirst('contact', 'customer', $this->public_id)),
            'uuid'          => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'     => $this->when(Http::isInternalRequest(), $this->public_id),
            'name'          => $this->name,
            'email'         => $this->email,
            'phone'         => $this->phone,
            'photo_url'     => $this->photo_url,
            'reviews_count' => $this->resource->reviews()->count(),
            'uploads_count' => $this->resource->reviewUploads()->count(),
            'slug'          => $this->slug,
            'created_at'    => $this->created_at,
            'updated_at'    => $this->updated_at,
        ];
    }
}
