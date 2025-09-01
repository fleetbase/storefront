<?php

namespace Fleetbase\Storefront\Http\Resources;

use Fleetbase\FleetOps\Http\Resources\v1\Place;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;
use Fleetbase\Support\Utils;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class Customer extends FleetbaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @param Request $request
     *
     * @return array
     */
    public function toArray($request)
    {
        $this->loadMissing(['place', 'places']);

        return [
            'id'                           => $this->when(Http::isInternalRequest(), $this->id, Str::replaceFirst('contact', 'customer', $this->public_id)),
            'uuid'                         => $this->when(Http::isInternalRequest(), $this->uuid),
            'user_uuid'                    => $this->when(Http::isInternalRequest(), $this->user_uuid),
            'company_uuid'                 => $this->when(Http::isInternalRequest(), $this->company_uuid),
            'place_uuid'                   => $this->when(Http::isInternalRequest(), $this->place_uuid),
            'photo_uuid'                   => $this->when(Http::isInternalRequest(), $this->photo_uuid),
            'public_id'                    => $this->when(Http::isInternalRequest(), $this->public_id),
            'address_id'                   => $this->place ? $this->place->public_id : null,
            'internal_id'                  => $this->internal_id,
            'name'                         => $this->name,
            'title'                        => $this->title,
            'photo_url'                    => $this->photo_url,
            'email'                        => $this->email,
            'phone'                        => $this->phone,
            'address'                      => data_get($this, 'place.address'),
            'addresses'                    => $this->whenLoaded('places', Place::collection($this->places)),
            'token'                        => $this->when($this->token, $this->token),
            'orders'                       => $this->getCustomerOrderCount($request),
            'meta'                         => data_get($this, 'meta', Utils::createObject()),
            'slug'                         => $this->slug,
            'created_at'                   => $this->created_at,
            'updated_at'                   => $this->updated_at,
        ];
    }

    private function getCustomerOrderCount(Request $request): int
    {
        $storeId   = $request->input('storefront') ?? $request->session()->get('storefront_id');
        $networkId = $request->input('network') ?? $request->session()->get('network_id');

        if ($storeId) {
            return Order::where(['customer_uuid' => $this->uuid, 'meta->storefront_id' => $storeId])->count();
        }

        if ($networkId) {
            return Order::where(['customer_uuid' => $this->uuid, 'meta->storefront_network_id' => $networkId])->count();
        }

        return 0;
    }
}
