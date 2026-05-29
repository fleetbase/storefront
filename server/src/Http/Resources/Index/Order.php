<?php

namespace Fleetbase\Storefront\Http\Resources\Index;

use Fleetbase\FleetOps\Http\Resources\v1\Index\Order as FleetOpsOrderIndexResource;
use Fleetbase\FleetOps\Support\Utils;

class Order extends FleetOpsOrderIndexResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     */
    public function toArray($request): array
    {
        $data = parent::toArray($request);

        $data['customer_name']      = $this->customer_name;
        $data['transaction_amount'] = $this->transaction_amount;
        $data['meta']               = data_get($this, 'meta', Utils::createObject());

        return $data;
    }
}
