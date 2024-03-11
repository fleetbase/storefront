<?php

namespace Fleetbase\Storefront\Seeders;

use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\Models\Company;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class OrderConfigSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $companies = Company::all();
        foreach ($companies as $company) {
            static::createStorefrontConfig($company);
        }
    }

    /**
     * Creates or retrieves an existing storefront configuration for a given company.
     *
     * This method checks if a storefront configuration (OrderConfig) already exists for the given company.
     * If it exists, the method returns the existing configuration. Otherwise, it creates a new configuration with
     * predefined settings for a storefront order process. The configuration includes various stages like 'created',
     * 'started', 'canceled', 'completed', etc., each defined with specific attributes like key, code, color, logic,
     * events, status, actions, details, and more. These stages help manage the order lifecycle in a storefront context.
     *
     * @param Company $company The company for which the storefront configuration is being created or retrieved.
     * @return OrderConfig The storefront order configuration associated with the specified company.
     */
    public static function createStorefrontConfig(Company $company): OrderConfig
    {
        return OrderConfig::firstOrCreate(
            [
                'company_uuid' => $company->uuid,
                'key'          => 'storefront',
                'namespace'    => 'system:order-config:storefront',
            ],
            [
                'name'         => 'Storefront',
                'key'          => 'storefront',
                'namespace'    => 'system:order-config:storefront',
                'description'  => 'Storefront order configuration for hyperlocal delivery and pickup',
                'core_service' => 1,
                'status'       => 'private',
                'version'      => '0.0.1',
                'tags'         => ['storefront', 'ecommerce', 'hyperlocal'],
                'entities'     => [],
                'meta'         => [],
                'flow'         => [
                    'created' => [
                        'key'         => 'created',
                        'code'        => 'created',
                        'color'       => '#1f2937',
                        'logic'       => [],
                        'events'      => [],
                        'status'      => 'Order Created',
                        'actions'     => [],
                        'details'     => 'New order was created.',
                        'options'     => [],
                        'complete'    => false,
                        'entities'    => [],
                        'sequence'    => 0,
                        'activities'  => ['dispatched'],
                        'internalId'  => Str::uuid(),
                        'pod_method'  => 'scan',
                        'require_pod' => false,
                    ],
                    'started' => [
                        'key'         => 'started',
                        'code'        => 'started',
                        'color'       => '#1f2937',
                        'logic'       => [],
                        'events'      => [],
                        'status'      => 'Order Started',
                        'actions'     => [],
                        'details'     => 'Order has been started',
                        'options'     => [],
                        'complete'    => false,
                        'entities'    => [],
                        'sequence'    => 0,
                        'activities'  => ['canceled', 'preparing'],
                        'internalId'  => Str::uuid(),
                        'pod_method'  => 'scan',
                        'require_pod' => false,
                    ],
                    'canceled' => [
                        'key'         => 'canceled',
                        'code'        => 'canceled',
                        'color'       => '#1f2937',
                        'logic'       => [],
                        'events'      => ['order.canceled'],
                        'status'      => 'Order canceled',
                        'actions'     => [],
                        'details'     => 'Order could not be accepted',
                        'options'     => [],
                        'complete'    => false,
                        'entities'    => [],
                        'sequence'    => 0,
                        'activities'  => [],
                        'internalId'  => Str::uuid(),
                        'pod_method'  => 'scan',
                        'require_pod' => false,
                    ],
                    'completed' => [
                        'key'         => 'completed',
                        'code'        => 'completed',
                        'color'       => '#1f2937',
                        'logic'       => [],
                        'events'      => [],
                        'status'      => 'Order completed',
                        'actions'     => [],
                        'details'     => 'Driver has completed the order',
                        'options'     => [],
                        'complete'    => true,
                        'entities'    => [],
                        'sequence'    => 0,
                        'activities'  => [],
                        'internalId'  => Str::uuid(),
                        'pod_method'  => 'scan',
                        'require_pod' => false,
                    ],
                    'picked_up' => [
                        'key'         => 'completed',
                        'code'        => 'picked_up',
                        'color'       => '#1f2937',
                        'logic'       => [],
                        'events'      => [],
                        'status'      => 'Order picked up',
                        'actions'     => [],
                        'details'     => 'Order has been picked up by customer',
                        'options'     => [],
                        'complete'    => true,
                        'entities'    => [],
                        'sequence'    => 0,
                        'activities'  => [],
                        'internalId'  => Str::uuid(),
                        'pod_method'  => 'scan',
                        'require_pod' => false,
                    ],
                    'preparing' => [
                        'key'         => 'preparing',
                        'code'        => 'preparing',
                        'color'       => '#1f2937',
                        'logic'       => [],
                        'events'      => [],
                        'status'      => 'Order is being prepared',
                        'actions'     => [],
                        'details'     => 'Order has been received by {storefront.name} and is being prepared',
                        'options'     => [],
                        'complete'    => false,
                        'entities'    => [],
                        'sequence'    => 0,
                        'activities'  => ['driver_enroute_to_store', 'pickup_ready'],
                        'internalId'  => Str::uuid(),
                        'pod_method'  => 'scan',
                        'require_pod' => false,
                    ],
                    'dispatched' => [
                        'key'         => 'dispatched',
                        'code'        => 'dispatched',
                        'color'       => '#1f2937',
                        'logic'       => [],
                        'events'      => [],
                        'status'      => 'Order Dispatched',
                        'actions'     => [],
                        'details'     => 'Order has been dispatched.',
                        'options'     => [],
                        'complete'    => false,
                        'entities'    => [],
                        'sequence'    => 0,
                        'activities'  => ['started'],
                        'internalId'  => Str::uuid(),
                        'pod_method'  => 'scan',
                        'require_pod' => false,
                    ],
                    'pickup_ready' => [
                        'key'   => 'ready',
                        'code'  => 'pickup_ready',
                        'color' => '#1f2937',
                        'logic' => [
                            [
                                'type'       => 'if',
                                'conditions' => [
                                    [
                                        'field'    => 'meta.is_pickup',
                                        'value'    => 'true',
                                        'operator' => 'equal',
                                    ],
                                ],
                            ],
                        ],
                        'events'      => [],
                        'status'      => 'Order is ready for pickup',
                        'actions'     => [],
                        'details'     => 'Order is ready to be picked up by customer',
                        'options'     => [],
                        'complete'    => false,
                        'entities'    => [],
                        'sequence'    => 0,
                        'activities'  => ['picked_up'],
                        'internalId'  => Str::uuid(),
                        'pod_method'  => 'scan',
                        'require_pod' => false,
                    ],
                    'driver_enroute' => [
                        'key'         => 'driver_enroute',
                        'code'        => 'driver_enroute',
                        'color'       => '#1f2937',
                        'logic'       => [],
                        'events'      => [],
                        'status'      => 'Driver en-route',
                        'actions'     => [],
                        'details'     => 'Driver is on the way to the customer',
                        'options'     => [],
                        'complete'    => false,
                        'entities'    => [],
                        'sequence'    => 0,
                        'activities'  => ['completed'],
                        'internalId'  => Str::uuid(),
                        'pod_method'  => 'scan',
                        'require_pod' => false,
                    ],
                    'driver_picked_up' => [
                        'key'         => 'driver_picked_up',
                        'code'        => 'driver_picked_up',
                        'color'       => '#1f2937',
                        'logic'       => [],
                        'events'      => [],
                        'status'      => 'Driver picked up',
                        'actions'     => [],
                        'details'     => 'Driver has picked up order',
                        'options'     => [],
                        'complete'    => false,
                        'entities'    => [],
                        'sequence'    => 0,
                        'activities'  => ['driver_enroute'],
                        'internalId'  => Str::uuid(),
                        'pod_method'  => 'scan',
                        'require_pod' => false,
                    ],
                    'driver_enroute_to_store' => [
                        'key'         => 'driver_enroute',
                        'code'        => 'driver_enroute_to_store',
                        'color'       => '#1f2937',
                        'logic'       => [],
                        'events'      => [],
                        'status'      => 'Driver en-route',
                        'actions'     => [],
                        'details'     => 'Driver en-route to store',
                        'options'     => [],
                        'complete'    => false,
                        'entities'    => [],
                        'sequence'    => 0,
                        'activities'  => ['driver_picked_up'],
                        'internalId'  => Str::uuid(),
                        'pod_method'  => 'scan',
                        'require_pod' => false,
                    ],
                ],
            ]
        );
    }
}
