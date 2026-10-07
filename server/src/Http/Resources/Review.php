<?php

namespace Fleetbase\Storefront\Http\Resources;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Storefront\Models\Product;
use Fleetbase\Storefront\Support\Storefront;
use Fleetbase\Support\Http;

class Review extends FleetbaseResource
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
            'id'           => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'         => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'    => $this->when(Http::isInternalRequest(), $this->public_id),
            'subject_id'   => $this->when(Http::isInternalRequest(), $this->subject?->id, $this->subject?->public_id),
            'subject_type' => $this->subject instanceof Product ? 'product' : 'store',
            'order_uuid'   => $this->when(Http::isInternalRequest(), $this->order_uuid),
            'verified'     => !empty($this->order_uuid),
            'is_mine'      => $this->customer_uuid !== null && $this->customer_uuid === static::viewerCustomerUuid(),
            'rating'       => $this->rating,
            'content'      => $this->content,
            'customer'     => new ReviewCustomer($this->customer),
            'slug'         => $this->slug,
            'photos'       => $this->mapPhotos($this->photos),
            'created_at'   => $this->created_at,
            'updated_at'   => $this->updated_at,
        ];
    }

    public function mapPhotos($photos = [])
    {
        return collect($photos)->map(function ($photo) {
            return [
                'id'       => $photo->public_id,
                'filename' => $photo->original_filename,
                'type'     => $photo->content_type,
                'caption'  => $photo->caption,
                'url'      => $photo->url,
            ];
        });
    }

    /**
     * The uuid of the customer making the request, looked up once per request so that a
     * page of reviews does not resolve the customer token for every review.
     */
    public static function viewerCustomerUuid(): ?string
    {
        $request = request();
        $key     = 'storefront.review_viewer_uuid';

        if (!$request->attributes->has($key)) {
            $request->attributes->set($key, Http::isInternalRequest() ? null : Storefront::getCustomerFromToken()?->uuid);
        }

        return $request->attributes->get($key);
    }
}
