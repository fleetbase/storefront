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

    /**
     * Orders placed by one customer; accepts the contact uuid or public id.
     */
    public function customer(string $customer)
    {
        // The customer relation is polymorphic, so resolve a public id to the uuid first.
        $uuid = \Illuminate\Support\Str::isUuid($customer) ? $customer : \Fleetbase\FleetOps\Models\Contact::where('public_id', $customer)->value('uuid');
        $this->builder->where('customer_uuid', $uuid ?? $customer);
    }

    public function customerUuid(string $customer)
    {
        $this->builder->where('customer_uuid', $customer);
    }

    public function storefront(string $storefront)
    {
        $this->builder->where('meta->storefront_id', $storefront);
    }

    /**
     * Orders placed through a network: the store is always `storefront_id`, the network
     * that sold it is `storefront_network_id`.
     */
    public function network(string $network)
    {
        $this->builder->where('meta->storefront_network_id', $network);
    }
}
