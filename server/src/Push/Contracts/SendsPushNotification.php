<?php

namespace Fleetbase\Storefront\Push\Contracts;

use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Push\PushMessage;

/**
 * Implemented by notifications delivered through the StorefrontPushChannel.
 */
interface SendsPushNotification
{
    /**
     * Build the push payload for the notifiable, or null to skip push delivery.
     */
    public function toPush($notifiable): ?PushMessage;

    /**
     * The storefronts whose push credentials may be used, most preferred first.
     *
     * For a marketplace order this is the network (whose app the customer uses) followed
     * by the store, so network apps and standalone store apps both resolve correctly.
     *
     * @return array<Store|Network>
     */
    public function pushStorefronts(): array;
}
