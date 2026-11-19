<?php

namespace Fleetbase\Storefront\Push\Contracts;

use Fleetbase\Push\Contracts\SendsToPushApps;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Store;

/**
 * A Storefront notification sent through the StorefrontPushAppChannel.
 */
interface SendsStorefrontPush extends SendsToPushApps
{
    /**
     * The storefronts the notification is about, most preferred first. A device that
     * registered before its storefront had a push app is sent through the first of these
     * that has one.
     *
     * For a marketplace order this is the network (whose app the customer uses) followed by
     * the store.
     *
     * @return array<Store|Network>
     */
    public function pushStorefronts(): array;
}
