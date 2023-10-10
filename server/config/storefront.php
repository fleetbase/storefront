<?php

/**
 * -------------------------------------------
 * Fleetbase Core API Configuration
 * -------------------------------------------
 */
return [
    'api' => [
        'version' => '0.0.1',
        'routing' => [
            'prefix' => 'storefront',
            'internal_prefix' => 'int'
        ],
    ],
    'connection' => [
        'db' => env('STOREFRONT_DB_CONNECTION', 'storefront')
    ]
];
