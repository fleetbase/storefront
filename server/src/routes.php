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
        | Public/Callback Receivable Storefront API Routes
        |--------------------------------------------------------------------------
        |
        | End-user API routes, these are routes that the SDK and applications will interface with, and DO NOT require API credentials.
        */
        Route::group(['prefix' => 'v1', 'namespace' => 'v1'], function ($router) {
            // storefront/v1/checkouts
            $router->group(['prefix' => 'checkouts'], function () use ($router) {
                $router->match(['get', 'post'], 'capture-qpay', 'CheckoutController@captureQpayCallback');
            });
        });

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
                $router->get('lookup/{id}', 'StoreController@lookup');
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
                    $router->get('status', 'CheckoutController@getCheckoutStatus');
                    $router->post('capture', 'CheckoutController@captureOrder');
                    $router->post('stripe-setup-intent', 'CheckoutController@createStripeSetupIntentForCustomer');
                    $router->put('stripe-update-payment-intent', 'CheckoutController@updateStripePaymentIntent');
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
                    $router->post('/', 'ProductController@create');
                    $router->put('{id}', 'ProductController@update');
                });

                // storefront/v1/food-trucks
                $router->group(['prefix' => 'food-trucks'], function () use ($router) {
                    $router->get('/', 'FoodTruckController@query');
                    $router->get('{id}', 'FoodTruckController@find');
                });

                // storefront/v1/reviews
                $router->group(['prefix' => 'reviews'], function () use ($router) {
                    $router->get('/', 'ReviewController@query');
                    $router->get('count', 'ReviewController@count');
                    $router->get('eligibility', 'ReviewController@eligibility');
                    $router->get('{id}', 'ReviewController@find');
                    $router->post('/', 'ReviewController@create');
                    $router->delete('{id}', 'ReviewController@delete');
                });

                // storefront/v1/orders
                $router->group(['prefix' => 'orders'], function () use ($router) {
                    $router->put('picked-up', 'OrderController@completeOrderPickup');
                    $router->post('receipt', 'OrderController@getReceipt');
                    $router->get('{id}/activity-flow', 'OrderController@getActivityFlow');
                    $router->get('{id}/chat', 'OrderChatController@show');
                    $router->get('{id}/chat/messages', 'OrderChatController@messages');
                    $router->post('{id}/chat/messages', 'OrderChatController@send');
                    $router->post('{id}/chat/read', 'OrderChatController@read');
                });

                // storefront/v1/promotions
                $router->group(['prefix' => 'promotions'], function () use ($router) {
                    $router->get('/', 'PromotionController@query');
                    $router->get('{id}', 'PromotionController@find');
                });

                // storefront/v1/notifications
                $router->group(['prefix' => 'notifications'], function () use ($router) {
                    $router->get('/', 'NotificationController@query');
                    $router->get('unread-count', 'NotificationController@unreadCount');
                    $router->get('preferences', 'NotificationController@getPreferences');
                    $router->put('preferences', 'NotificationController@updatePreferences');
                    $router->put('read-all', 'NotificationController@markAllAsRead');
                    $router->get('{id}', 'NotificationController@find');
                    $router->put('{id}/read', 'NotificationController@markAsRead');
                    $router->delete('{id}', 'NotificationController@delete');
                });

                // storefront/v1/notifications
                $router->group(['prefix' => 'notifications'], function () use ($router) {
                    $router->get('/', 'NotificationController@query');
                    $router->get('unread-count', 'NotificationController@unreadCount');
                    $router->get('preferences', 'NotificationController@getPreferences');
                    $router->put('preferences', 'NotificationController@updatePreferences');
                    $router->put('read-all', 'NotificationController@markAllAsRead');
                    $router->get('{id}', 'NotificationController@find');
                    $router->put('{id}/read', 'NotificationController@markAsRead');
                    $router->delete('{id}', 'NotificationController@delete');
                });

                // storefront/v1/customers
                $router->group(['prefix' => 'customers'], function () use ($router) {
                    $router->put('{id}', 'CustomerController@update');
                    $router->get('/', 'CustomerController@query');
                    $router->post('register-device', 'CustomerController@registerDevice');
                    $router->post('unregister-device', 'CustomerController@unregisterDevice');
                    $router->post('socket-token', 'CustomerController@socketToken');
                    $router->get('places', 'CustomerController@places');
                    $router->get('orders', 'CustomerController@orders');
                    $router->get('{id}', 'CustomerController@find');
                    $router->post('/', 'CustomerController@create');
                    $router->post('login-with-sms', 'CustomerController@loginWithPhone');
                    $router->post('login-with-apple', 'CustomerController@loginWithApple');
                    $router->post('login-with-facebook', 'CustomerController@loginWithFacebook');
                    $router->post('login-with-google', 'CustomerController@loginWithGoogle');
                    $router->post('verify-code', 'CustomerController@verifyCode');
                    $router->post('login', 'CustomerController@login');
                    $router->post('request-creation-code', 'CustomerController@requestCustomerCreationCode');
                    $router->post('stripe-ephemeral-key', 'CustomerController@getStripeEphemeralKey');
                    $router->post('stripe-setup-intent', 'CustomerController@getStripeSetupIntent');
                    $router->post('account-closure', 'CustomerController@startAccountClosure');
                    $router->post('confirm-account-closure', 'CustomerController@confirmAccountClosure');
                    $router->post('request-phone-verification', 'CustomerController@requestPhoneVerification');
                    $router->post('verify-phone-number', 'CustomerController@verifyPhoneNumber');
                });

                // hotfix! storefront-app sending customer update to /contacts/ route
                $router->put('contacts/{id}', 'CustomerController@update');

                // storefront/v1/carts
                $router->group(['prefix' => 'carts'], function () use ($router) {
                    $router->get('/', 'CartController@retrieve');
                    $router->get('{uniqueId}', 'CartController@retrieve');
                    $router->put('{cartId}/empty', 'CartController@empty');
                    $router->get('{cartId}/promotions', 'CartController@promotions');
                    $router->post('{cartId}/promo-code', 'CartController@applyPromotionCode');
                    $router->delete('{cartId}/promo-code/{code}', 'CartController@removePromotionCode');
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
                $router->group(['prefix' => 'v1'], function () use ($router) {
                    $router->get('networks/find/{id}', 'NetworkController@findNetwork');
                });

                $router->group(
                    ['prefix' => 'v1', 'middleware' => ['fleetbase.protected']],
                    function ($router) {
                        $router->get('search', 'SearchController@search');

                        $router->group(
                            ['prefix' => 'actions'],
                            function ($router) {
                                $router->get('store-count', 'ActionController@getStoreCount');
                                $router->get('metrics', 'ActionController@getMetrics');
                                $router->post('send-push-notification', 'ActionController@sendPushNotification');
                            }
                        );
                        $router->group(
                            ['prefix' => 'analytics'],
                            function ($router) {
                                $router->get('overview', 'AnalyticsController@overview');
                                $router->get('revenue-trend', 'AnalyticsController@revenueTrend');
                                $router->get('orders-by-status', 'AnalyticsController@ordersByStatus');
                                $router->get('top-products', 'AnalyticsController@topProducts');
                                $router->get('customer-insights', 'AnalyticsController@customerInsights');
                            }
                        );
                        $router->fleetbaseRoutes(
                            'orders',
                            function ($router, $controller) {
                                $router->post('accept', $controller('acceptOrder'));
                                $router->post('ready', $controller('markOrderAsReady'));
                                $router->post('preparing', $controller('markOrderAsPreparing'));
                                $router->post('completed', $controller('markOrderAsCompleted'));
                                $router->patch('cancel', $controller('rejectOrder'));
                                $router->post('unassign-driver', $controller('unassignDriver'));
                            }
                        );
                        $router->fleetbaseRoutes(
                            'networks',
                            function ($router, $controller) {
                                $router->delete('{id}/remove-category', $controller('deleteCategory'));
                                $router->post('{id}/set-store-category', $controller('addStoreToCategory'));
                                $router->post('{id}/remove-store-category', $controller('removeStoreCategory'));
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
                                $router->post('create-entities', $controller('createEntities'));
                            }
                        );
                        $router->fleetbaseRoutes('product-hours');
                        $router->fleetbaseRoutes('product-variants');
                        $router->fleetbaseRoutes('product-variant-options');
                        $router->fleetbaseRoutes('product-addons');
                        $router->fleetbaseRoutes('product-addon-categories');
                        $router->fleetbaseRoutes('addon-categories');
                        $router->fleetbaseRoutes('gateways');
                        $router->fleetbaseRoutes(
                            'notification-channels',
                            function ($router, $controller) {
                                $router->post('{id}/test', $controller('testPush'));
                            }
                        );
                        $router->fleetbaseRoutes(
                            'promotions',
                            function ($router, $controller) {
                                $router->post('{id}/generate-codes', $controller('generateCodes'));
                                $router->post('{id}/announce', $controller('announce'));
                            }
                        );
                        $router->fleetbaseRoutes('promotion-codes');
                        $router->fleetbaseRoutes(
                            'customer-segments',
                            function ($router, $controller) {
                                $router->post('preview', $controller('previewRules'));
                                $router->get('{id}/preview', $controller('preview'));
                            }
                        );
                        $router->fleetbaseRoutes(
                            'campaigns',
                            function ($router, $controller) {
                                $router->post('{id}/send', $controller('send'));
                                $router->post('{id}/cancel', $controller('cancel'));
                                $router->get('{id}/audience', $controller('audience'));
                            }
                        );
                        $router->fleetbaseRoutes('reviews');
                        $router->fleetbaseRoutes('votes');
                        $router->fleetbaseRoutes('food-trucks');
                        $router->fleetbaseRoutes('catalogs');
                        $router->fleetbaseRoutes('catalog-categories');
                        $router->fleetbaseRoutes('catalog-hours');
                        $router->group(
                            [],
                            function ($router) {
                                $router->group(
                                    ['prefix' => 'metrics'],
                                    function ($router) {
                                        $router->get('/', 'MetricsController@all');
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
