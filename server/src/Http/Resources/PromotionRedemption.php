<?php

namespace Fleetbase\Storefront\Http\Resources;

use Fleetbase\Http\Resources\FleetbaseResource;

class PromotionRedemption extends FleetbaseResource
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
            'id'                  => $this->uuid,
            'uuid'                => $this->uuid,
            'promotion_uuid'      => $this->promotion_uuid,
            'promotion_code_uuid' => $this->promotion_code_uuid,
            'customer_uuid'       => $this->customer_uuid,
            'checkout_uuid'       => $this->checkout_uuid,
            'order_uuid'          => $this->order_uuid,
            'promotion_name'      => data_get($this, 'promotion.name'),
            'promotion_type'      => data_get($this, 'promotion.type'),
            'code'                => data_get($this, 'code.code'),
            'customer_name'       => data_get($this, 'customer.name'),
            'customer_public_id'  => data_get($this, 'customer.public_id'),
            'order_public_id'     => data_get($this, 'order.public_id'),
            'order_status'        => data_get($this, 'order.status'),
            'amount'              => $this->amount,
            'currency'            => $this->currency,
            'status'              => $this->status,
            'redeemed_at'         => $this->redeemed_at,
            'created_at'          => $this->created_at,
            'updated_at'          => $this->updated_at,
        ];
    }
}
