<?php

namespace Fleetbase\Storefront\Http\Resources;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Storefront\Models\PromotionRedemption;
use Fleetbase\Support\Http;

class PromotionCode extends FleetbaseResource
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
            'id'             => $this->when(Http::isInternalRequest(), $this->uuid, $this->public_id),
            'uuid'           => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'      => $this->when(Http::isInternalRequest(), $this->public_id),
            'promotion_uuid' => $this->when(Http::isInternalRequest(), $this->promotion_uuid),
            'customer_uuid'  => $this->when(Http::isInternalRequest(), $this->customer_uuid),
            'code'           => $this->code,
            'status'         => $this->status,
            'usage_limit'    => $this->usage_limit,
            'times_used'     => PromotionRedemption::counting()->where('promotion_code_uuid', $this->uuid)->count(),
            'expires_at'     => $this->expires_at,
            'created_at'     => $this->created_at,
            'updated_at'     => $this->updated_at,
        ];
    }
}
