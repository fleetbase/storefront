<?php

namespace Fleetbase\Storefront\Support;

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Models\User;
use Fleetbase\Storefront\Models\AppReviewAccess;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Store;
use Illuminate\Support\Facades\Log;

/**
 * App Review access: how Apple and Google reviewers sign in to a store's app.
 *
 * Storefront signs customers in with a texted code, which a reviewer cannot receive. A store
 * (or network) that turns App Review access on lets the fixed review number below sign in
 * with a code its owner generated in the Console and gave to the app store. Nothing is ever
 * sent to that number, and the code works only in the storefront that issued it.
 *
 * Self-hosted installs can still configure review accounts instance-wide with
 * STOREFRONT_BYPASS_VERIFICATION_CODE and STOREFRONT_REVIEW_ACCOUNTS (config
 * storefront.storefront_app); those keep working as a fallback.
 */
class AppReview
{
    /**
     * The review identity: a number from the North American 555-0100 to 555-0199 range that
     * is reserved for fiction, so it can never reach a real person. One number serves every
     * store because customers belong to a store: each store's code signs it in to that store
     * only. libphonenumber accepts it (US, fixed line or mobile), as does the app's own check.
     */
    public const PHONE = '+12025550143';

    public const PHONE_DISPLAY = '+1 202 555 0143';

    /**
     * Digits in a generated code; the app's code screen takes six.
     */
    public const CODE_LENGTH = 6;

    /**
     * Name given to the review account when its first sign-in creates it.
     */
    public const ACCOUNT_NAME = 'App Reviewer';

    /**
     * Said on the store's new-order alert for a reviewer's order.
     */
    public const ORDER_NOTICE = 'App Review test order: placed by an app store reviewer. Do not fulfil it.';

    public const OFF_MESSAGE = 'This number is reserved for app store review sign-in, which is turned off for this store.';

    /**
     * Whether an identity is the fixed review number, however it is formatted.
     */
    public static function isReviewPhone(?string $identity): bool
    {
        if (blank($identity)) {
            return false;
        }

        return preg_replace('/\D+/', '', $identity) === preg_replace('/\D+/', '', static::PHONE);
    }

    /**
     * The App Review access record of a store or network, if it has one.
     */
    public static function accessFor(Store|Network|null $owner): ?AppReviewAccess
    {
        if (!$owner || blank($owner->uuid)) {
            return null;
        }

        try {
            return AppReviewAccess::where('owner_uuid', $owner->uuid)->first();
        } catch (\Throwable $e) {
            // Before the migration has run there is no access, not an error.
            return null;
        }
    }

    public static function isEnabledFor(Store|Network|null $owner): bool
    {
        return (bool) static::accessFor($owner)?->isUsable();
    }

    /**
     * Whether a code signs the review number in to this storefront: its access is on and the
     * code matches the one it generated. Another store's code never matches.
     */
    public static function acceptsCode(Store|Network|null $owner, ?string $identity, mixed $code): bool
    {
        if (!static::isReviewPhone($identity) || blank($code) || !is_scalar($code)) {
            return false;
        }

        $access = static::accessFor($owner);
        if (!$access || !$access->isUsable() || !$access->checkCode((string) $code)) {
            return false;
        }

        Log::info('[Storefront] App Review sign-in accepted.', [
            'storefront' => $owner->public_id ?? $owner->uuid,
        ]);

        return true;
    }

    /**
     * Whether an identity is listed as an instance-wide review account (self-hosted env
     * config) and a bypass code is configured for it.
     */
    public static function isConfiguredReviewAccount(?string $identity): bool
    {
        if (blank($identity) || blank(config('storefront.storefront_app.bypass_verification_code'))) {
            return false;
        }

        $reviewAccounts = array_map(
            static fn ($account) => strtolower(trim((string) $account)),
            (array) config('storefront.storefront_app.review_accounts', [])
        );

        return in_array(strtolower(trim($identity)), $reviewAccounts, true);
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

    /**
     * A new random code.
     */
    public static function generateCode(): string
    {
        return str_pad((string) random_int(0, (10 ** static::CODE_LENGTH) - 1), static::CODE_LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * Change a storefront's access. Turning it on without a code generates one, so it is
     * never on with nothing to sign in with; `regenerate` replaces the code. Turning it off
     * keeps the code, so turning it back on restores the same one.
     *
     * @return array{0: AppReviewAccess, 1: ?string} the access and the new plain code, if one was generated
     */
    public static function update(Store|Network $owner, ?bool $enabled, bool $regenerate = false, ?string $updatedByUuid = null): array
    {
        $access = static::accessFor($owner) ?? new AppReviewAccess([
            'company_uuid' => $owner->company_uuid,
            'owner_uuid'   => $owner->uuid,
            'owner_type'   => get_class($owner),
            'enabled'      => false,
        ]);

        if ($enabled !== null) {
            if ($enabled && !$access->enabled) {
                $access->enabled_at = now();
            }
            $access->enabled = $enabled;
        }

        $code = null;
        if ($regenerate || ($access->enabled && !$access->hasCode())) {
            $code = static::generateCode();
            $access->setPlainCode($code);
        }

        $access->updated_by_uuid = $updatedByUuid;
        $access->save();

        return [$access, $code];
    }

    /**
     * What the Console and the internal API show about a storefront's access. Never the code
     * or its hash; `code` is added by the caller only in the response that generated it.
     */
    public static function status(?AppReviewAccess $access): array
    {
        return [
            'enabled'           => (bool) $access?->enabled,
            'phone'             => static::PHONE,
            'phone_display'     => static::PHONE_DISPLAY,
            'has_code'          => (bool) $access?->hasCode(),
            'code_generated_at' => $access?->code_generated_at?->toIso8601String(),
            'enabled_at'        => $access?->enabled_at?->toIso8601String(),
        ];
    }
}
