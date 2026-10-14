<?php

/**
 * -------------------------------------------
 * Fleetbase Core API Configuration
 * -------------------------------------------
 */
return [
    /*
    |--------------------------------------------------------------------------
    | API Config
    |--------------------------------------------------------------------------
    */
    'api' => [
        'version' => '0.0.1',
        'routing' => [
            'prefix' => 'storefront',
            'internal_prefix' => 'int'
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Storefront App
    |--------------------------------------------------------------------------
    */
    'storefront_app' => [
        /*
        | App store reviewers cannot receive our SMS or email, so a fixed verification
        | code has to keep working in production for them. Both values are required for
        | a bypass to be possible, and neither has a default — an unconfigured install
        | has no bypass at all.
        |
        | The code alone is NOT sufficient: it is only accepted for an identity listed
        | in review_accounts. Previously any identity was accepted, so knowing the code
        | was enough to authenticate as any customer.
        |
        |   STOREFRONT_BYPASS_VERIFICATION_CODE=<a secret, rotated code>
        |   STOREFRONT_REVIEW_ACCOUNTS=apple-review@example.com,+15555550100
        */
        'bypass_verification_code' => env('STOREFRONT_BYPASS_VERIFICATION_CODE'),
        'review_accounts'          => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('STOREFRONT_REVIEW_ACCOUNTS', ''))
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Push Notifications
    |--------------------------------------------------------------------------
    |
    | android_channel_id: the Android notification channel storefront pushes are
    | posted to. Leave empty to use the app's default FCM channel. A notification
    | channel's own `android_channel_id` setting takes precedence.
    */
    'push' => [
        'android_channel_id' => env('STOREFRONT_PUSH_ANDROID_CHANNEL_ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    */
    'connection' => [
        'db' => env('STOREFRONT_DB_CONNECTION', 'storefront')
    ],

    /*
    |--------------------------------------------------------------------------
    | Throttle/Rate-Limiting
    |--------------------------------------------------------------------------
    |
    | Requests a single device (store key + client IP) may make to the public
    | Storefront API per decay window. A customer app browsing, carting and
    | checking out stays well under 120 a minute; raise it per deployment with
    | STOREFRONT_THROTTLE_REQUESTS_PER_MINUTE if an integration needs more.
    */
    'throttle' => [
        'max_attempts'  => (int) env('STOREFRONT_THROTTLE_REQUESTS_PER_MINUTE', 120),
        'decay_minutes' => (int) env('STOREFRONT_THROTTLE_DECAY_MINUTES', 1),

        // The endpoints that send a text message (creation code, SMS login, phone
        // verification, account closure) get their own, much smaller bucket on top.
        'sms_max_attempts'  => (int) env('STOREFRONT_THROTTLE_SMS_REQUESTS_PER_MINUTE', 5),
        'sms_decay_minutes' => (int) env('STOREFRONT_THROTTLE_SMS_DECAY_MINUTES', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Verification Codes
    |--------------------------------------------------------------------------
    |
    | resend_seconds: a creation-code request for an identity that was sent a
    | code this recently answers "ok" without sending another message. The first
    | code is still valid; a second one would only cost money. 0 disables it.
    */
    'verification' => [
        'resend_seconds' => (int) env('STOREFRONT_VERIFICATION_RESEND_SECONDS', 60),
    ],
];
