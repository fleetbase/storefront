<?php

$storefrontFlow = [
    'created' => [
        'sequence' => 0,
        'color' => 'green',
        'events' => [
            [
                'status' => 'Order is being prepared',
                'details' => 'Order has been received by {storefront.name} and is being prepared',
                'code' => 'preparing',
            ],
            [
                'status' => 'Order canceled',
                'details' => 'Order could not be accepted',
                'code' => 'canceled',
            ],
        ],
    ],
    'preparing' => [
        'sequence' => 1,
        'color' => 'orange',
        'events' => [
            [
                'if' => [['meta.is_pickup', '=', true]],
                'status' => 'Order is ready for pickup',
                'details' => 'Order is ready to be picked up by customer.',
                'code' => 'ready',
            ],
            [
                'status' => 'Order dispatched',
                'details' => 'Order has been dispatched to driver',
                'code' => 'dispatched',
            ],
        ]
    ],
    'ready' => [
        'sequence' => 2,
        'color' => 'green',
        'events' => [
            [
                'status' => 'Order picked up',
                'details' => 'Order has been picked up by customer.',
                'code' => 'completed',
            ]
        ]
    ],
    'dispatched' => [
        'sequence' => 2,
        'color' => 'blue',
        'events' => [
            [
                'status' => 'Driver en-route',
                'details' => 'Driver en-route to location',
                'code' => 'driver_enroute',
            ]
        ]
    ],
    'driver_enroute' => [
        'sequence' => 3,
        'color' => 'green',
        'events' => [
            [
                'status' => 'Order completed',
                'details' => 'Driver has completed order',
                'code' => 'completed',
            ],
        ]
    ],
];


return [
    /*
    |--------------------------------------------------------------------------
    | API Events
    |--------------------------------------------------------------------------
    */

    'events' => [],

    /*
    |--------------------------------------------------------------------------
    | API Resource Types
    |--------------------------------------------------------------------------
    */
    
    'types' => [
        'order' => [
            [
                'name' => 'Storefront',
                'description' => 'Operational flow for storefront apps.',
                'key' => 'storefront',
                'meta_type' => 'order_config',
                'meta' => [
                    'flow' => $storefrontFlow,
                ],
            ],
        ]
    ]
];
