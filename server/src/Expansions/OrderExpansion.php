<?php

namespace Fleetbase\Storefront\Expansions;

use Fleetbase\Build\Expansion;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Store;

class OrderExpansion implements Expansion
{
    /**
     * Get the target class to expand.
     *
     * @return string|Class
     */
    public static function target()
    {
        return Order::class;
    }

    /**
     * Get the current storefront for the order created.
     */
    public static function getStorefrontAttribute(): \Closure
    {
        return function (): Store|Network|null {
            /** @var Order $this */
            $storefrontId        = $this->getMeta('storefront_id');
            $storefrontNetworkId = $this->getMeta('storefront_network_id');

            if ($storefrontId) {
                return Store::where('public_id', $storefrontId)->first();
            }

            if ($storefrontNetworkId) {
                return Network::where('public_id', $storefrontNetworkId)->first();
            }

            return null;
        };
    }
}
