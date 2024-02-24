<?php

namespace Fleetbase\Storefront\Expansions;

use Fleetbase\Build\Expansion;

class OrderFilterExpansion implements Expansion
{
    /**
     * Get the target class to expand.
     *
     * @return string|Class
     */
    public static function target()
    {
        return \Fleetbase\FleetOps\Http\Filter\OrderFilter::class;
    }

    /**
     * Filter orders by the storefront id.
     *
     * @return \Closure
     */
    public static function storefront()
    {
        return function (?string $storefront) {
            /* @var \Fleetbase\FleetOps\Http\Filter\OrderFilter $this */
            $this->builder->where('meta->storefront_id', $storefront);
        };
    }
}
