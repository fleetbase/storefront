<?php

namespace Fleetbase\Storefront\Push;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\NotificationChannel;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Support\Storefront;
use Illuminate\Support\Collection;

/**
 * Resolves which storefront notification channels (push credentials) to use.
 */
class PushCredentialResolver
{
    /**
     * Storefronts an order's push notifications may be sent through, most preferred first.
     *
     * Network (marketplace) orders are placed from the network's app, so the network's
     * channels are preferred over the individual store's.
     *
     * @return array<Store|Network>
     */
    public static function storefrontsForOrder(Order $order): array
    {
        $storefronts = [];

        foreach (['storefront_network_id', 'storefront_id'] as $metaKey) {
            $id = $order->getMeta($metaKey);
            if (!$id) {
                continue;
            }

            $storefront = Storefront::findAbout($id);
            if ($storefront && !collect($storefronts)->contains(fn ($existing) => $existing->uuid === $storefront->uuid)) {
                $storefronts[] = $storefront;
            }
        }

        return $storefronts;
    }

    /**
     * Candidate channels for a scheme, in the order they should be tried.
     *
     * Channels owned by the storefront app the device registered from come first, then
     * channels owned by each storefront in preference order (oldest first within a
     * storefront, matching the historical behavior of using the first configured channel).
     *
     * @param array<Store|Network> $storefronts
     *
     * @return Collection<int, NotificationChannel>
     */
    public function channels(string $scheme, array $storefronts, ?string $appIdentifier = null): Collection
    {
        $ownerUuids = collect($storefronts)->pluck('uuid')->filter()->values();
        if ($ownerUuids->isEmpty()) {
            return collect();
        }

        $channels = NotificationChannel::query()
            ->where('scheme', $scheme)
            ->where(function ($query) use ($ownerUuids, $appIdentifier) {
                $query->whereIn('owner_uuid', $ownerUuids->all());
                if ($appIdentifier) {
                    $query->orWhere('owner_uuid', $appIdentifier);
                }
            })
            ->orderBy('created_at')
            ->get();

        return $channels
            ->sortBy(function (NotificationChannel $channel) use ($ownerUuids, $appIdentifier) {
                $appRank   = $appIdentifier && $channel->owner_uuid === $appIdentifier ? 0 : 1;
                $ownerRank = $ownerUuids->search($channel->owner_uuid);

                return sprintf('%d-%03d', $appRank, $ownerRank === false ? 999 : $ownerRank);
            })
            ->values();
    }
}
