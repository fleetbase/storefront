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
        'bypass_verification_code' => env('STOREFRONT_BYPASS_VERIFICATION_CODE', '999000')
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
        'max_attempts' => env('STOREFRONT_THROTTLE_REQUESTS_PER_MINUTE', 500),
        'decay_minutes' => env('STOREFRONT_THROTTLE_DECAY_MINUTES', 1),
    ],
];
