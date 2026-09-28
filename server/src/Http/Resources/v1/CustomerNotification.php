<?php

namespace Fleetbase\Storefront\Http\Resources\v1;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Storefront\Support\CustomerNotificationPresenter;

class CustomerNotification extends FleetbaseResource
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
        return array_merge(
            ['id' => $this->id],
            CustomerNotificationPresenter::present((array) $this->data, $this->type),
            [
                'is_read'    => $this->read_at !== null,
                'read_at'    => $this->read_at,
                'created_at' => $this->created_at,
            ]
        );
    }
}
