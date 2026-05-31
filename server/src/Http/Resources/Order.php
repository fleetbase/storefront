<?php

namespace Fleetbase\Storefront\Http\Resources;

use Fleetbase\FleetOps\Http\Resources\v1\Order as FleetOpsOrderResource;
use Illuminate\Contracts\Support\Arrayable;

class Order extends FleetOpsOrderResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     */
    public function toArray($request): array
    {
        $data = parent::toArray($request);
        $meta = $this->normalizeMeta(data_get($data, 'meta', []));

        $data['customer_name']      = $this->customer_name;
        $data['transaction_amount'] = $this->transaction_amount;
        $data['meta']               = array_replace($meta, $this->storefrontOrderMeta());

        if ($this->resource->relationLoaded('transaction') && $this->transaction) {
            $data['transaction'] = [
                'id'          => $this->transaction->uuid,
                'uuid'        => $this->transaction->uuid,
                'public_id'   => $this->transaction->public_id ?? null,
                'amount'      => $this->transaction->amount ?? $this->transaction_amount,
                'currency'    => $this->transaction->currency ?? data_get($data, 'meta.currency'),
                'status'      => $this->transaction->status ?? null,
                'gateway'     => $this->transaction->gateway ?? data_get($data, 'meta.gateway'),
                'created_at'  => $this->transaction->created_at,
                'updated_at'  => $this->transaction->updated_at,
            ];
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
            'checkout_id',
            'cart_id',
        ];

        $meta = array_intersect_key($this->normalizeMeta($this->resource->meta ?? []), array_flip($keys));

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
