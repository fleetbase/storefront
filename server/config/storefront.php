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
    */
    'throttle' => [
        'max_attempts' => env('STOREFRONT_THROTTLE_REQUESTS_PER_MINUTE', 600),
        'decay_minutes' => env('STOREFRONT_THROTTLE_DECAY_MINUTES', 1),
    ],
];
