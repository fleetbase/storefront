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
        $data                       = $this->withoutCustomerPlaces(json_decode(json_encode($this->filter(parent::toArray($request))), true) ?? []);
        $data['customer_name']      = $this->customer_name;
        $data['transaction_amount'] = $this->transaction_amount;
        $data['meta']               = $this->storefrontOrderMeta();

        if ($this->resource->relationLoaded('transaction') && $this->transaction) {
            $data['transaction'] = [
                'id'                => $this->transaction->uuid,
                'uuid'              => $this->transaction->uuid,
                'public_id'         => $this->transaction->public_id ?? null,
                'amount'            => $this->transaction->amount ?? $this->transaction_amount,
                'currency'          => $this->transaction->currency ?? data_get($data, 'meta.currency'),
                'status'            => $this->transaction->status ?? null,
                'settlement_status' => $this->transaction->settlement_status ?? null,
                'gateway'           => $this->transaction->gateway ?? data_get($data, 'meta.gateway'),
                'created_at'        => $this->transaction->created_at,
                'updated_at'        => $this->transaction->updated_at,
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
            'discount',
            'promotions',
            'payment_status',
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

        if (isset($meta['storefront']) && (is_array($meta['storefront']) || is_object($meta['storefront']))) {
            $storefrontKeys     = ['id', 'public_id', 'name', 'logo_url', 'is_store', 'is_network'];
            $meta['storefront'] = array_intersect_key($this->normalizeMeta($meta['storefront']), array_flip($storefrontKeys));
        }

        return $meta;
    }

    /**
     * A customer's places carry their kind in `type` (apartment, house, office...), which the
     * console reads as a model name, and without it they cannot be identified at all. The
     * customer copies in this order (its own and each item's) leave their places out.
     */
    private function withoutCustomerPlaces(array $data): array
    {
        // The order views don't use the customer's address book, so it is left out entirely.
        $strip = function ($customer) {
            if (is_array($customer)) {
                unset($customer['place'], $customer['places']);
            }

            return $customer;
        };

        if (isset($data['customer'])) {
            $data['customer'] = $strip($data['customer']);
        }
        if (is_array(data_get($data, 'payload.entities'))) {
            foreach ($data['payload']['entities'] as $index => $entity) {
                if (is_array($entity) && isset($entity['customer'])) {
                    $data['payload']['entities'][$index]['customer'] = $strip($entity['customer']);
                }
            }
        }

        return $data;
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
