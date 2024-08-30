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
    public array $guards = ['sanctum'];

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

    /**
     * Policies provided by this schema.
     */
    public array $policies = [
        [
            'name'        => 'InventoryManager',
            'description' => 'Policy for managing products and categories.',
            'permissions' => [
                'see extension',
                '* product',
                '* product-addon',
                '* product-addon-category',
                '* product-variant',
                '* product-variant-option',
                '* product-hour',
                '* product-store-location',
                '* product-category',
            ],
        ],
        [
            'name'        => 'OrderManager',
            'description' => 'Policy for managing order.',
            'permissions' => [
                'see extension',
                '* order',
            ],
        ],
        [
            'name'        => 'CustomerService',
            'description' => 'Policy for providing support to customers.',
            'permissions' => [
                'see extension',
                '* customer',
                '* order',
            ],
        ],
        [
            'name'        => 'MarketplaceManager',
            'description' => 'Policy for managing networks.',
            'permissions' => [
                'see extension',
                '* product',
                '* order',
                '* network',
                '* network-store',
                '* network-category',
            ],
        ],
    ];

    /**
     * Roles provided by this schema.
     */
    public array $roles = [
        [
            'name'        => 'Storefront Administrator',
            'description' => 'Role for managing all of storefront resources.',
            'policies'    => [
                'StorefrontFullAccess',
            ],
        ],
        [
            'name'        => 'Customer Service',
            'description' => 'Role for providing support to customers.',
            'policies'    => [
                'CustomerService',
            ],
        ],
        [
            'name'        => 'Inventory Manager',
            'description' => 'Role for managing all products.',
            'policies'    => [
                'InventoryManager',
            ],
        ],
    ];
}
