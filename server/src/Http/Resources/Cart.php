<?php

namespace Fleetbase\Storefront\Http\Resources;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Storefront\Models\Product;
use Fleetbase\Support\Http;

class Cart extends FleetbaseResource
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
            'id'                 => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'               => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'          => $this->when(Http::isInternalRequest(), $this->public_id),
            'company_uuid'       => $this->when(Http::isInternalRequest(), $this->company_uuid),
            'user_uuid'          => $this->when(Http::isInternalRequest(), $this->user_uuid),
            'checkout_uuid'      => $this->when(Http::isInternalRequest(), $this->checkout_uuid),
            'checkout_uuid'      => $this->when(Http::isInternalRequest(), $this->checkout_uuid),
            'customer_id'        => $this->customer_id,
            'currency'           => $this->currency,
            'subtotal'           => $this->subtotal,
            'total_items'        => $this->total_items,
            'total_unique_items' => $this->total_unique_items,
            'items'              => $this->getCartItems(),
            'events'             => $this->events ?? [],
            'discount_code'      => $this->discount_code,
            'expires_at'         => $this->expires_at,
            'created_at'         => $this->created_at,
            'updated_at'         => $this->updated_at,
        ];
    }

    public function getCartItems()
    {
        $items = $this->items ?? [];

        return array_map(function ($cartItem) {
            $product = Product::select(['public_id', 'primary_image_uuid', 'name', 'description'])->where('public_id', data_get($cartItem, 'product_id'))->first();
            if ($product) {
                data_set($cartItem, 'name', $product->name);
                data_set($cartItem, 'description', $product->description);
                data_set($cartItem, 'product_image_url', $product->primary_image_url);
            }

            return $cartItem;
        }, $items);
    }
}
