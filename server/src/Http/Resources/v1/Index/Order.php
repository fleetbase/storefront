<?php

namespace Fleetbase\Storefront\Http\Resources\v1\Index;

use Fleetbase\FleetOps\Http\Resources\v1\Index\Order as FleetOpsOrderIndexResource;
use Illuminate\Contracts\Support\Arrayable;

class Order extends FleetOpsOrderIndexResource
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
        $order = parent::toArray($request);

        $order['meta'] = array_replace(
            $this->normalizeMeta(data_get($order, 'meta', [])),
            $this->storefrontOrderMeta()
        );

        return $order;
    }

    private function storefrontOrderMeta(): array
    {
        $keys = [
            'storefront',
            'storefront_id',
            'storefront_network',
            'storefront_network_id',
            'subtotal',
            'delivery_fee',
            'tip',
            'delivery_tip',
            'total',
            'currency',
            'gateway',
            'is_pickup',
            'is_master_order',
            'related_orders',
            'master_order_id',
        ];

        return array_intersect_key($this->normalizeMeta($this->resource->meta ?? []), array_flip($keys));
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
