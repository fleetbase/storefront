<?php

namespace Fleetbase\Storefront\Models;

use Fleetbase\Traits\HasUuid;

/**
 * The Sign in with Apple refresh token a customer's app was given at sign-in, kept so it can
 * be revoked with Apple when the customer deletes their account (App Review 5.1.1(v)).
 * One per customer per app; `client_id` is the app's bundle ID.
 */
class AppleSignInToken extends StorefrontModel
{
    use HasUuid;

    protected $table = 'apple_sign_in_tokens';

    protected $fillable = ['company_uuid', 'customer_uuid', 'user_uuid', 'apple_user_id', 'client_id', 'refresh_token'];

    protected $casts = [
        'refresh_token' => 'encrypted',
    ];

    protected $hidden = ['refresh_token'];
}
