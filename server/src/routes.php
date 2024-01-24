<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::prefix(config('storefront.api.routing.prefix', 'storefront'))->namespace('Fleetbase\Storefront\Http\Controllers')->group(
    function ($router) {
        /*
        |--------------------------------------------------------------------------
        | Consumable Storefront API Routes
        |--------------------------------------------------------------------------
        |
        | End-user API routes, these are routes that the SDK and applications will interface with, and require API credentials.
        */
        Route::prefix('v1')
            ->middleware('storefront.api')
            ->namespace('v1')
            ->group(function ($router) {
                $router->get('about', 'StoreController@about');
                $router->get('locations/{id}', 'StoreController@location');
                $router->get('locations', 'StoreController@locations');
                $router->get('gateways/{id}', 'StoreController@gateway');
                $router->get('gateways', 'StoreController@gateways');
                $router->get('search', 'StoreController@search');
                $router->get('stores', 'NetworkController@stores');
                $router->get('store-locations', 'NetworkController@storeLocations');
                $router->get('tags', 'NetworkController@tags');

                // storefront/v1/checkouts
                $router->group(['prefix' => 'checkouts'], function () use ($router) {
                    $router->get('before', 'CheckoutController@beforeCheckout');
                    $router->get('capture', 'CheckoutController@captureOrder');
                });

                // storefront/v1/service-quotes
                $router->group(['prefix' => 'service-quotes'], function () use ($router) {
                    $router->get('from-cart', 'ServiceQuoteController@fromCart');
                });

                // storefront/v1/categories
                $router->group(['prefix' => 'categories'], function () use ($router) {
                    $router->get('/', 'CategoryController@query');
                });

                // storefront/v1/products
                $router->group(['prefix' => 'products'], function () use ($router) {
                    $router->get('/', 'ProductController@query');
                    $router->get('{id}', 'ProductController@find');
                });

                // storefront/v1/reviews
                $router->group(['prefix' => 'reviews'], function () use ($router) {
                    $router->get('/', 'ReviewController@query');
                    $router->get('count', 'ReviewController@count');
                    $router->get('{id}', 'ReviewController@find');
                    $router->post('/', 'ReviewController@create');
                    $router->delete('{id}', 'ReviewController@find');
                });

                // storefront/v1/customers
                $router->group(['prefix' => 'customers'], function () use ($router) {
                    $router->put('{id}', 'CustomerController@update');
                    $router->get('/', 'CustomerController@query');
                    $router->post('register-device', 'CustomerController@registerDevice');
                    $router->get('places', 'CustomerController@places');
                    $router->get('orders', 'CustomerController@orders');
                    $router->get('{id}', 'CustomerController@find');
                    $router->post('/', 'CustomerController@create');
                    $router->post('login-with-sms', 'CustomerController@loginWithPhone');
                    $router->post('verify-code', 'CustomerController@verifyCode');
                    $router->post('login', 'CustomerController@login');
                    $router->post('request-creation-code', 'CustomerController@requestCustomerCreationCode');
                });

                // hotfix! storefront-app sending customer update to /contacts/ route
                $router->put('contacts/{id}', 'CustomerController@update');

                // storefront/v1/carts
                $router->group(['prefix' => 'carts'], function () use ($router) {
                    $router->get('/', 'CartController@retrieve');
                    $router->get('{uniqueId}', 'CartController@retrieve');
                    $router->put('{cartId}/empty', 'CartController@empty');
                    $router->post('{cartId}/{productId}', 'CartController@add');
                    $router->put('{cartId}/{lineItemId}', 'CartController@update');
                    $router->delete('{cartId}/{lineItemId}', 'CartController@remove');
                    $router->delete('{cartId}', 'CartController@delete');
                });
            });
        /*
        |--------------------------------------------------------------------------
        | Internal Storefront API Routes
        |--------------------------------------------------------------------------
        |
        | Primary internal routes for console.
        */
        $router->prefix(config('storefront.api.routing.internal_prefix', 'int'))->group(
            function ($router) {
                $router->group(['prefix' => 'int/v1', 'middleware' => ['internal.cors']], function () use ($router) {
                    $router->get('networks/find/{id}', 'NetworkController@findNetwork');
                });

                $router->group(
                    ['prefix' => 'v1', 'middleware' => ['fleetbase.protected']],
                    function ($router) {
                        $router->get('/', 'ActionController@welcome');
                        $router->group(
                            ['prefix' => 'actions'],
                            function ($router) {
                                $router->get('store-count', 'ActionController@getStoreCount');
                                $router->get('metrics', 'ActionController@getMetrics');
                            }
                        );
                        $router->fleetbaseRoutes(
                            'orders',
                            function ($router, $controller) {
                                $router->post('accept', $controller('acceptOrder'));
                                $router->post('ready', $controller('markOrderAsReady'));
                                $router->post('completed', $controller('markOrderAsCompleted'));
                            }
                        );
                        $router->fleetbaseRoutes(
                            'networks',
                            function ($router, $controller) {
                                $router->delete('{id}/remove-category', $controller('deleteCategory'));
                                $router->post('{id}/set-store-category', $controller('addStoreToCategory'));
                                $router->post('{id}/add-stores', $controller('addStores'));
                                $router->post('{id}/remove-stores', $controller('removeStores'));
                                $router->post('{id}/invite', $controller('sendInvites'));
                            }
                        );
                        $router->fleetbaseRoutes('customers');
                        $router->fleetbaseRoutes('stores');
                        $router->fleetbaseRoutes('store-hours');
                        $router->fleetbaseRoutes('store-locations');
                        $router->fleetbaseRoutes(
                            'products',
                            function ($router, $controller) {
                                $router->post('process-imports', $controller('processImports'));
                            }
                        );
                        $router->fleetbaseRoutes('product-hours');
                        $router->fleetbaseRoutes('product-variants');
                        $router->fleetbaseRoutes('product-variant-options');
                        $router->fleetbaseRoutes('product-addons');
                        $router->fleetbaseRoutes('product-addon-categories');
                        $router->fleetbaseRoutes('addon-categories');
                        $router->fleetbaseRoutes('gateways');
                        $router->fleetbaseRoutes('notification-channels');
                        $router->fleetbaseRoutes('reviews');
                        $router->fleetbaseRoutes('votes');
                        $router->group(
                            [],
                            function ($router) {
                                /* Dashboard Build */
                                $router->get('dashboard', 'MetricsController@dashboard');

                                $router->group(
                                    ['prefix' => 'metrics'],
                                    function ($router) {
                                        $router->get('all', 'MetricsController@all');
                                    }
                                );
                            }
                        );
                    }
                );
            }
        );
    }
);
