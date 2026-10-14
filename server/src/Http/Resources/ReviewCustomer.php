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
        $internal = Http::isInternalRequest();

        // Reviews are public: customers see each other's first name and last initial only,
        // never their contact details.
        return [
            'id'            => $this->when(Http::isInternalRequest(), $this->id, Str::replaceFirst('contact', 'customer', $this->public_id)),
            'uuid'          => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'     => $this->when(Http::isInternalRequest(), $this->public_id),
            'name'          => $internal ? $this->name : static::publicName($this->name),
            'email'         => $this->when($internal, $this->email),
            'phone'         => $this->when($internal, $this->phone),
            'photo_url'     => $this->photo_url,
            'reviews_count' => $this->resource->reviews()->count(),
            'uploads_count' => $this->resource->reviewUploads()->count(),
            'slug'          => $this->slug,
            'created_at'    => $this->created_at,
            'updated_at'    => $this->updated_at,
        ];
    }

    /**
     * "Ada B." from "Ada Buyer"; a single name is shown as is.
     */
    public static function publicName(?string $name): ?string
    {
        $parts = preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY);
        if (!$parts) {
            return null;
        }

        if (count($parts) === 1) {
            return $parts[0];
        }

        return $parts[0] . ' ' . mb_strtoupper(mb_substr(end($parts), 0, 1)) . '.';
    }
}
