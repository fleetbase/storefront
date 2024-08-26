<?php

namespace Fleetbase\Storefront\Auth\Schemas;

class Storefront
{
    /**
     * The permission schema Name.
     */
    public string $name = 'storefront';

    /**
     * The permission schema Polict Name.
     */
    public string $policyName = 'Storefront';

    /**
     * Guards these permissions should apply to.
     */
    public array $guards = ['web', 'api'];

    /**
     * The permission schema resources.
     */
    public array $resources = [
        [
            'name'    => 'order',
            'actions' => ['accept', 'mark-as-ready', 'mark-as-completed', 'reject', 'export', 'import'],
        ],
        [
            'name'    => 'customer',
            'actions' => ['export'],
        ],
        [
            'name'    => 'product',
            'actions' => ['import', 'export'],
        ],
        [
            'name'    => 'product-addon',
            'actions' => [],
        ],
        [
            'name'    => 'product-addon-category',
            'actions' => [],
        ],
        [
            'name'    => 'product-hour',
            'actions' => [],
        ],
        [
            'name'    => 'product-store-location',
            'actions' => [],
        ],
        [
            'name'    => 'product-variant',
            'actions' => [],
        ],
        [
            'name'    => 'product-variant-option',
            'actions' => [],
        ],
        [
            'name'    => 'product-category',
            'actions' => [],
        ],
        [
            'name'    => 'gateway',
            'actions' => [],
        ],
        [
            'name'    => 'notification-channel',
            'actions' => [],
        ],
        [
            'name'    => 'network',
            'actions' => [],
        ],
        [
            'name'    => 'network-store',
            'actions' => [],
        ],
        [
            'name'    => 'network-category',
            'actions' => [],
        ],
        [
            'name'    => 'store',
            'actions' => [],
        ],
        [
            'name'    => 'store-location',
            'actions' => [],
        ],
        [
            'name'    => 'store-hour',
            'actions' => [],
        ],
        [
            'name'           => 'settings',
            'action'         => ['import'],
            'remove_actions' => ['delete', 'export', 'list', 'create'],
        ],
    ];
}
