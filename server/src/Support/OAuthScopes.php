<?php

namespace Fleetbase\Storefront\Support;

use Fleetbase\Auth\OAuthServer\OAuthScopes as CoreOAuthScopes;

/**
 * Storefront's scopes for "Continue with Fleetbase" (core-api's OAuth provider).
 *
 * An app asking for any of these makes the user pick an organization they administer, and its
 * tokens only ever reach that organization's stores and networks.
 */
class OAuthScopes
{
    public const READ          = 'storefront:read';
    public const REVIEW_ACCESS = 'storefront:review-access';
    public const APP_KEY       = 'storefront:app-key';

    public static function register(): void
    {
        CoreOAuthScopes::register(static::READ, [
            'description'  => "See {organization}'s storefronts and networks, with their public keys",
            'organization' => true,
        ]);

        CoreOAuthScopes::register(static::REVIEW_ACCESS, [
            'description'         => "Turn App Review sign-in on or off for {organization}'s storefronts",
            'organization'        => true,
            'review_access_types' => [AppReview::STORE_TYPE, AppReview::NETWORK_TYPE],
        ]);

        CoreOAuthScopes::register(static::APP_KEY, [
            'description'  => 'Create an API key for your Storefront app in {organization}',
            'organization' => true,
        ]);
    }
}
