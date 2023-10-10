<?php

namespace Fleetbase\Storefront\Http\Filter;

use Fleetbase\FleetOps\Http\Filter\OrderFilter as FleetOpsOrderFilter;

class OrderFilter extends FleetOpsOrderFilter
{
    public function queryForInternal()
    {
        $this->builder
            ->where(
                [
                    'company_uuid' => $this->session->get('company'),
                    'type' => 'storefront'
                ]
            )
            ->whereHas(
                'payload',
                function ($q) {
                    $q->where(
                        function ($q) {
                            $q->whereHas('waypoints');
                            $q->orWhereHas('pickup');
                            $q->orWhereHas('dropoff');
                        }
                    );
                    $q->with(['entities', 'waypoints', 'dropoff', 'pickup', 'return']);
                }
            )
            ->whereHas('trackingNumber')
            ->whereHas('trackingStatuses')
            ->with(
                [
                    'payload',
                    'trackingNumber',
                    'trackingStatuses'
                ]
            );
    }

    public function storefront(string $storefront)
    {
        $this->builder->where('meta->storefront_id', $storefront);
    }
}
