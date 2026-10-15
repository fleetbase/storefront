<?php

namespace Fleetbase\Storefront\Http\Resources\Index;

use Fleetbase\FleetOps\Http\Resources\v1\Index\Order as FleetOpsOrderIndexResource;
use Fleetbase\Storefront\Models\FoodTruck;
use Illuminate\Contracts\Support\Arrayable;

class Order extends FleetOpsOrderIndexResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     */
    public function toArray($request): array
    {
        $data       = parent::toArray($request);
        $parentMeta = $this->normalizeMeta(data_get($data, 'meta', []));

        $data['customer_name']      = $this->customer_name;
        $data['transaction_amount'] = $this->transaction_amount;
        $data['meta']               = $this->storefrontOrderMeta();

        if (array_key_exists('_index_resource', $parentMeta)) {
            $data['meta']['_index_resource'] = $parentMeta['_index_resource'];
        }

        return $data;
    }

    private function storefrontOrderMeta(): array
    {
        $keys = [
            'storefront',
            'storefront_id',
            'storefront_network',
            'storefront_network_id',
            'checkout_id',
            'subtotal',
            'delivery_fee',
            'tip',
            'delivery_tip',
            'total',
            'discount',
            'promotions',
            'payment_status',
            'payment',
            'currency',
            'gateway',
            'is_pickup',
            'is_booking',
            'booking_at',
            // Start, end and length: a list refresh pushes into the record the order panel shows.
            'booking',
            'booking_has_items',
            'is_master_order',
            'related_orders',
            'master_order_id',
            // An order placed at a food truck: checkout stamps the truck's public id.
            'food_truck_id',
        ];

        $meta = array_intersect_key($this->normalizeMeta($this->resource->meta ?? []), array_flip($keys));

        if (isset($meta['storefront']) && (is_array($meta['storefront']) || is_object($meta['storefront']))) {
            $storefrontKeys     = ['id', 'public_id', 'name', 'logo_url', 'is_store', 'is_network'];
            $meta['storefront'] = array_intersect_key($this->normalizeMeta($meta['storefront']), array_flip($storefrontKeys));
        }

        if (!empty($meta['food_truck_id'])) {
            $meta['food_truck'] = FoodTruck::summaryFor($meta['food_truck_id'], $this->resource->company_uuid);
        }

        return $meta;
    }

    private function normalizeMeta($meta): array
    {
        if ($meta instanceof Arrayable) {
            $meta = $meta->toArray();
        }

        if (is_object($meta)) {
            $meta = (array) $meta;
        }

        return is_array($meta) ? $meta : [];
    }
}
