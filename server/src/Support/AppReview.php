<?php

namespace Fleetbase\Storefront\Support;

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Models\User;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Support\AppReview as CoreAppReview;

/**
 * App Review access in Storefront: how Apple and Google reviewers sign in to a store's app.
 *
 * The mechanism is core's (Fleetbase\Support\AppReview): Storefront registers stores and
 * networks as review subjects with its review number, the owner turns access on in the
 * Console, and a store's code signs the number in to that store only. Core never texts the
 * number. This class adds what is Storefront's own: the shared review customer, marking
 * reviewers' orders, and the self-hosted env fallback keys.
 *
 * Self-hosted installs can still configure review accounts instance-wide with
 * STOREFRONT_BYPASS_VERIFICATION_CODE and STOREFRONT_REVIEW_ACCOUNTS (config
 * storefront.storefront_app); those keep working as a fallback.
 */
class AppReview
{
    /**
     * Storefront's review number: from the North American 555-0100 to 555-0199 range that is
     * reserved for fiction, so it can never reach a real person. One number serves every store
     * because customers belong to a store: each store's code signs it in to that store only.
     * Navigator registers a number of its own, since drivers and customers are different users.
     * libphonenumber accepts it (US, fixed line or mobile), as does the app's own check.
     */
    public const PHONE = '+12025550143';

    public const STORE_TYPE   = 'storefront-store';
    public const NETWORK_TYPE = 'storefront-network';

    /**
     * Name given to the review account when its first sign-in creates it.
     */
    public const ACCOUNT_NAME = 'App Reviewer';

    /**
     * Said on the store's new-order alert for a reviewer's order.
     */
    public const ORDER_NOTICE = 'App Review test order: placed by an app store reviewer. Do not fulfil it.';

    public const OFF_MESSAGE = 'This number is reserved for app store review sign-in, which is turned off for this store.';

    protected const CODE_KEY     = 'storefront.storefront_app.bypass_verification_code';
    protected const ACCOUNTS_KEY = 'storefront.storefront_app.review_accounts';

    /**
     * Register stores and networks with core as App Review subjects.
     */
    public static function register(): void
    {
        CoreAppReview::register(static::STORE_TYPE, [
            'model'           => Store::class,
            'phone'           => static::PHONE,
            'name'            => 'Storefront',
            'permission'      => 'storefront manage-app-review store',
            'view_permission' => 'storefront view store',
        ]);
        CoreAppReview::register(static::NETWORK_TYPE, [
            'model'           => Network::class,
            'phone'           => static::PHONE,
            'name'            => 'Storefront',
            'permission'      => 'storefront manage-app-review network',
            'view_permission' => 'storefront view network',
        ]);
    }

    /**
     * Whether an identity is Storefront's review number, however it is formatted.
     */
    public static function isReviewPhone(?string $identity): bool
    {
        if (blank($identity)) {
            return false;
        }

        return preg_replace('/\D+/', '', $identity) === preg_replace('/\D+/', '', static::PHONE);
    }

    public static function isEnabledFor(Store|Network|null $owner): bool
    {
        return CoreAppReview::isEnabledFor($owner);
    }

    /**
     * Whether a code signs the review number in to this storefront: its access is on and the
     * code matches the one it generated. Another store's code never matches.
     */
    public static function acceptsCode(Store|Network|null $owner, ?string $identity, mixed $code): bool
    {
        return static::isReviewPhone($identity) && CoreAppReview::acceptsCode($owner, static::PHONE, $code);
    }

    /**
     * Whether an identity is listed as an instance-wide review account (self-hosted env
     * config) and a bypass code is configured for it.
     */
    public static function isConfiguredReviewAccount(?string $identity): bool
    {
        return CoreAppReview::isConfiguredAccount(static::CODE_KEY, static::ACCOUNTS_KEY, $identity);
    }

    /**
     * Whether a code is the instance-wide bypass code for a listed review account.
     */
    public static function configuredAccountMatches(?string $identity, mixed $code): bool
    {
        return CoreAppReview::configuredAccountMatches(static::CODE_KEY, static::ACCOUNTS_KEY, $identity, $code, 'storefront');
    }

    /**
     * Whether a customer is an app reviewer: signed in as the review number, or as an
     * instance-wide review account.
     */
    public static function isReviewCustomer(mixed $customer): bool
    {
        if (!$customer instanceof Contact) {
            return false;
        }

        $identities = [$customer->phone, $customer->email];

        try {
            $user = $customer->user_uuid ? User::where('uuid', $customer->user_uuid)->withoutGlobalScopes()->first() : null;
        } catch (\Throwable $e) {
            $user = null;
        }

        if ($user) {
            $identities[] = $user->phone;
            $identities[] = $user->email;
        }

        foreach (array_filter($identities) as $identity) {
            if (static::isReviewPhone($identity) || static::isConfiguredReviewAccount($identity)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Order meta with the App Review mark added when a reviewer placed the order, so the
     * store can see in the Console that it is a test and not fulfil it.
     */
    public static function markOrderMeta(array $meta, mixed $customer): array
    {
        if (static::isReviewCustomer($customer)) {
            $meta['app_review'] = true;
        }

        return $meta;
    }

    public static function isReviewOrder(?Order $order): bool
    {
        return $order !== null && $order->getMeta('app_review') === true;
    }

    /**
     * The shared review account, created on its first sign-in. It is a customer user like
     * any other; each store it signs in to gets its own customer contact.
     */
    public static function user(Store|Network $owner): User
    {
        $user = User::where('phone', static::PHONE)->whereNull('deleted_at')->withoutGlobalScopes()->first();
        if ($user) {
            return $user;
        }

        $user = User::create([
            'company_uuid'      => $owner->company_uuid,
            'name'              => static::ACCOUNT_NAME,
            'phone'             => static::PHONE,
            'phone_verified_at' => now(),
        ]);
        $user->setUserType('customer');

        return $user;
    }
}
