<?php

namespace Fleetbase\Storefront\Support;

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\Models\Company;
use Fleetbase\Storefront\Models\Checkout;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Support\SocketCluster\SocketPrincipal;
use Fleetbase\Support\SocketCluster\SocketToken;

/**
 * Storefront's part of realtime socket authentication.
 *
 * Mints the two principals storefront owns — `customer` (a signed-in storefront
 * customer) and `checkout` (whoever started a checkout, guest or not, limited by
 * `scp` to that checkout's channel) — and authorizes the two channel prefixes it
 * owns: `storefront.{store|network}` and `checkout.{checkout}`.
 *
 * Storefront keys have no test mode, so every storefront principal is `live`.
 */
class StorefrontSocket
{
    /**
     * Storefront keys carry no live/test distinction.
     */
    public const ENV = 'live';

    /**
     * Builds the `customer` principal for a customer signed in to a store or network.
     */
    public static function customerPrincipal(Contact $customer, Store|Network $storefront): SocketPrincipal
    {
        return SocketPrincipal::fromClaims([
            'kind' => 'customer',
            'sub'  => $customer->uuid,
            'cid'  => $storefront->company_uuid,
            'cpid' => static::companyPublicId($storefront->company_uuid),
            'env'  => static::ENV,
            // The user uuid lets the customer join chats they take part in, such as their
            // order chat with the driver: core authorizes chat channels by participant user.
            'ids'  => array_values(array_filter([$customer->uuid, $customer->public_id, $customer->user_uuid])),
            'adm'  => false,
            'scp'  => null,
            'sid'  => $storefront->uuid,
        ]);
    }

    /**
     * Builds the `checkout` principal: it may only ever subscribe to its own checkout channel.
     */
    public static function checkoutPrincipal(Checkout $checkout): SocketPrincipal
    {
        return SocketPrincipal::fromClaims([
            'kind' => 'checkout',
            'sub'  => $checkout->uuid,
            'cid'  => $checkout->company_uuid,
            'cpid' => static::companyPublicId($checkout->company_uuid),
            'env'  => static::ENV,
            'ids'  => [$checkout->uuid, $checkout->public_id],
            'adm'  => false,
            'scp'  => [static::checkoutChannel($checkout)],
            'sid'  => $checkout->store_uuid ?? $checkout->network_uuid,
        ]);
    }

    /**
     * Mints the socket token returned with an initialized checkout, or null while socket auth is disabled.
     */
    public static function checkoutToken(Checkout $checkout): ?array
    {
        if (!SocketToken::enabled()) {
            return null;
        }

        return SocketToken::issue(static::checkoutPrincipal($checkout));
    }

    /**
     * The channel checkout progress is published on.
     */
    public static function checkoutChannel(Checkout $checkout): string
    {
        return 'checkout.' . $checkout->public_id;
    }

    /**
     * Registers storefront's channel resolvers with core-api's socket channel registry.
     *
     * @param \Fleetbase\Support\SocketCluster\SocketChannelRegistry $registry
     */
    public static function registerChannels($registry): void
    {
        $registry->register('storefront', \Closure::fromCallable([static::class, 'authorizeStorefront']));
        $registry->register('checkout', \Closure::fromCallable([static::class, 'authorizeCheckout']));
    }

    /**
     * `storefront.{id}` — id is a store or network uuid, public id or key.
     *
     * Console users and API credentials may subscribe to their own company's
     * storefronts; a customer only to the storefront their token was minted for.
     */
    public static function authorizeStorefront(SocketPrincipal $principal, string $id, string $channel): bool
    {
        $storefront = static::findStorefront($id);

        if (!$storefront || !$storefront->company_uuid || $storefront->company_uuid !== $principal->cid) {
            return false;
        }

        if ($principal->isCompanyScoped()) {
            return true;
        }

        if ($principal->kind === 'customer') {
            return $principal->sid !== null && $storefront->uuid === $principal->sid;
        }

        return false;
    }

    /**
     * `checkout.{id}` — id is a checkout uuid or public id.
     *
     * Console users and API credentials may subscribe to their own company's
     * checkouts; a customer only to checkouts they own. A `checkout` principal never
     * reaches here: its `scp` already limits it to its own channel.
     */
    public static function authorizeCheckout(SocketPrincipal $principal, string $id, string $channel): bool
    {
        $checkout = Checkout::select(['uuid', 'public_id', 'company_uuid', 'owner_uuid'])
            ->where(function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('public_id', $id);
            })
            ->first();

        if (!$checkout || !$checkout->company_uuid || $checkout->company_uuid !== $principal->cid) {
            return false;
        }

        if ($principal->isCompanyScoped()) {
            return true;
        }

        if ($principal->kind === 'customer') {
            return $checkout->owner_uuid !== null && $checkout->owner_uuid === $principal->sub;
        }

        return false;
    }

    /**
     * Finds a store, then a network, by uuid, public id or key.
     */
    protected static function findStorefront(string $id): Store|Network|null
    {
        foreach ([Store::class, Network::class] as $model) {
            $storefront = $model::select(['uuid', 'company_uuid'])
                ->where(function ($query) use ($id) {
                    $query->where('uuid', $id)->orWhere('public_id', $id)->orWhere('key', $id);
                })
                ->first();

            if ($storefront) {
                return $storefront;
            }
        }

        return null;
    }

    protected static function companyPublicId(?string $companyUuid): ?string
    {
        if (!$companyUuid) {
            return null;
        }

        return Company::where('uuid', $companyUuid)->value('public_id');
    }
}
