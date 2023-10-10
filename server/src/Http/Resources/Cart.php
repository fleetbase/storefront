<?php

namespace Fleetbase\Storefront\Http\Resources;

use Fleetbase\Http\Resources\FleetbaseResource;

class Cart extends FleetbaseResource
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
            'id' => $this->public_id,
            'currency' => $this->currency,
            'subtotal' => $this->subtotal,
            'total_items' => $this->total_items,
            'total_unique_items' => $this->total_unique_items,
            'items' => $this->items ?? [],
            'events' => $this->events ?? [],
            'discount_code' => $this->discount_code,
            'expires_at' => $this->expires_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
