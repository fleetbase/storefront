<?php

namespace Fleetbase\Storefront\Http\Filter;

use Fleetbase\FleetOps\Http\Filter\OrderFilter as FleetOpsOrderFilter;

class OrderFilter extends FleetOpsOrderFilter
{
    public function queryForInternal()
    {
        $this->builder->where('company_uuid', $this->session->get('company'));
        $this->builder->whereNotNull('meta->storefront_id');

        // replace ambiguous whereRelation with qualified whereHas to avoid alias clashes
        $this->builder->whereHas('payload', function ($payloadQuery) {
            $payloadQuery->where(function ($q) {
                $q->orWhereHas('pickup', function ($p) {
                    $p->whereNotNull('places.uuid');
                });
                $q->orWhereHas('dropoff', function ($d) {
                    $d->whereNotNull('places.uuid');
                });
            });
        });

        // ensure associated tracking data exists
        $this->builder->whereHas('trackingNumber', function ($q) {
            $q->select('uuid');
        });

        $this->builder->whereHas('trackingStatuses', function ($q) {
            $q->select('uuid');
        });

        // eager load main relationships to reduce N+1 overhead
        $this->builder->with([
            'payload.entities',
            'payload.waypoints',
            'payload.pickup',
            'payload.dropoff',
            'payload.return',
            'trackingNumber',
            'trackingStatuses',
            'driverAssigned',
        ]);
    }

    public function storefront(string $storefront)
    {
        $this->builder->where('meta->storefront_id', $storefront);
    }
}
