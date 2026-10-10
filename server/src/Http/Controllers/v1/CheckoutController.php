<?php

namespace Fleetbase\Storefront\Http\Controllers\v1;

use Fleetbase\FleetOps\Http\Resources\v1\Order as OrderResource;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\Transaction;
use Fleetbase\Models\TransactionItem;
use Fleetbase\Storefront\Http\Requests\CaptureOrderRequest;
use Fleetbase\Storefront\Http\Requests\CreateStripeSetupIntentRequest;
use Fleetbase\Storefront\Http\Requests\InitializeCheckoutRequest;
use Fleetbase\Storefront\Models\Cart;
use Fleetbase\Storefront\Models\Checkout;
use Fleetbase\Storefront\Http\Middleware\SetStorefrontSession;
use Fleetbase\Storefront\Models\Customer;
use Fleetbase\Storefront\Models\FoodTruck;
use Fleetbase\Storefront\Models\Gateway;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Product;
use Illuminate\Support\Carbon;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Models\StoreLocation;
use Fleetbase\Storefront\Promotions\PromotionContext;
use Fleetbase\Storefront\Promotions\PromotionEngine;
use Fleetbase\Storefront\Promotions\PromotionRedemptions;
use Fleetbase\Storefront\Promotions\PromotionResult;
use Fleetbase\Storefront\Promotions\PromotionUnavailableException;
use Fleetbase\Storefront\Support\QPay;
use Fleetbase\Storefront\Support\Storefront;
use Fleetbase\Storefront\Support\StorefrontSocket;
use Fleetbase\Storefront\Support\StripeUtils;
use Fleetbase\Support\SocketCluster\SocketClusterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Stripe\Exception\AuthenticationException as StripeAuthenticationException;
use Stripe\Exception\InvalidRequestException;

class CheckoutController extends Controller
{
    private const STRIPE_AUTHENTICATION_ERROR = 'Stripe gateway authentication failed. Verify the configured secret key.';

    private static function hasStripeSecret(Gateway $gateway): bool
    {
        $secretKey = data_get($gateway, 'config.secret_key');

        return is_string($secretKey) && trim($secretKey) !== '';
    }

    private static function stripeAuthenticationError(Gateway $gateway, string $operation)
    {
        Log::warning('[Storefront] Stripe gateway authentication failed.', [
            'gateway_uuid' => $gateway->uuid,
            'sandbox'      => $gateway->sandbox,
            'operation'    => $operation,
            'exception'    => StripeAuthenticationException::class,
        ]);

        return response()->apiError(self::STRIPE_AUTHENTICATION_ERROR);
    }

    protected static function qpayForGateway(Gateway $gateway): QPay
    {
        return QPay::instance(
            $gateway->config->username,
            $gateway->config->password,
            $gateway->callback_url
        );
    }

    protected function autoAcceptOrder(Order $order): void
    {
        Storefront::autoAcceptOrder($order);
    }

    protected function autoDispatchOrder(Order $order): void
    {
        Storefront::autoDispatchOrder($order);
    }

    protected function createIntegratedVendorOrder(ServiceQuote $serviceQuote, Request $request)
    {
        return $serviceQuote->integratedVendor->api()->createOrderFromServiceQuote($serviceQuote, $request);
    }

    protected function createIntegratedVendorOrderSafely(ServiceQuote $serviceQuote, Request $request): array
    {
        try {
            return [
                'order' => $this->createIntegratedVendorOrder($serviceQuote, $request),
                'error' => null,
            ];
        } catch (\Exception $e) {
            return [
                'order' => null,
                'error' => response()->apiError($e->getMessage()),
            ];
        }
    }

    protected function resolveStoreLocationOrigin($origin, Cart $cart)
    {
        if ($origin) {
            return $origin;
        }

        $storeLocation = collect($cart->items)->map(function ($cartItem) {
            $storeLocationId = $cartItem->store_location_id ?? null;

            if (!$storeLocationId) {
                $store = Store::where('public_id', $cartItem->store_id)->first();

                if ($store) {
                    $storeLocationId = Utils::get($store, 'locations.0.public_id');
                }
            }

            return $storeLocationId;
        })->unique()->filter()->map(function ($storeLocationId) {
            return StoreLocation::where('public_id', $storeLocationId)->first();
        })->first();

        return $storeLocation ? $storeLocation->place_uuid : null;
    }

    protected function validateMarketplaceCart(Cart $cart)
    {
        $networkUuid = session('storefront_network');
        if (!$networkUuid) {
            return null;
        }

        $items = collect($cart->items);
        if ($items->isEmpty()) {
            return response()->apiError('The cart is empty.', 422);
        }

        $storeIds = $items->pluck('store_id')->filter()->unique()->values();
        if ($storeIds->count() !== $items->pluck('store_id')->unique()->count()) {
            return response()->apiError('Every marketplace cart item must identify its store.', 422);
        }

        $stores = Store::whereIn('public_id', $storeIds)
            ->whereHas('networks', fn ($query) => $query->where('network_uuid', $networkUuid))
            ->get()
            ->keyBy('public_id');

        if ($stores->count() !== $storeIds->count()) {
            return response()->apiError('The cart contains a store outside this marketplace.', 403);
        }

        if ($stores->contains(fn (Store $store) => !$store->online)) {
            return response()->apiError('A store in this cart is currently offline.', 422);
        }

        $network          = Network::select(['uuid', 'options'])->where('uuid', $networkUuid)->first();
        $multiCartEnabled = data_get($network, 'options.multi_cart_enabled') === true;
        if ($storeIds->count() > 1 && !$multiCartEnabled) {
            return response()->apiError('This marketplace only supports one store per cart.', 422);
        }

        $products = Product::whereIn('public_id', $items->pluck('product_id')->filter()->unique())
            ->whereIn('store_uuid', $stores->pluck('uuid'))
            ->where('is_available', 1)
            ->where('status', 'published')
            ->get()
            ->keyBy('public_id');
        $locations = StoreLocation::whereIn('public_id', $items->pluck('store_location_id')->filter()->unique())
            ->whereIn('store_uuid', $stores->pluck('uuid'))
            ->get()
            ->keyBy('public_id');

        foreach ($items as $item) {
            $store    = $stores->get($item->store_id ?? null);
            $product  = $products->get($item->product_id ?? null);
            $location = $locations->get($item->store_location_id ?? null);
            if (!$store || !$product || $product->store_uuid !== $store->uuid) {
                return response()->apiError('A product in this cart is no longer available from its store.', 422);
            }
            if (!$location || $location->store_uuid !== $store->uuid) {
                return response()->apiError('A store location in this cart is no longer valid.', 422);
            }
        }

        if ($products->pluck('currency')->filter()->unique()->count() > 1) {
            return response()->apiError('Marketplace carts cannot combine different currencies.', 422);
        }

        return null;
    }

    protected function resolveFoodTruck(Cart $cart): ?FoodTruck
    {
        return collect($cart->items)
            ->map(fn ($cartItem) => data_get($cartItem, 'food_truck_id'))
            ->unique()
            ->filter()
            ->map(fn ($foodTruckId) => FoodTruck::where('public_id', $foodTruckId)->with(['zone', 'serviceArea'])->first())
            ->first();
    }

    protected function resolveFoodTruckOrigin(?FoodTruck $foodTruck): ?array
    {
        if (!$foodTruck || !$foodTruck->vehicle) {
            return null;
        }

        return [
            'name'     => $foodTruck->name,
            'street1'  => data_get($foodTruck, 'zone.name'),
            'city'     => data_get($foodTruck, 'serviceArea.name'),
            'country'  => data_get($foodTruck, 'serviceArea.country'),
            'location' => $foodTruck->vehicle->location,
        ];
    }

    protected function applyFoodTruckOrderData(?FoodTruck $foodTruck, array $orderMeta, array $orderInput): array
    {
        if (!$foodTruck) {
            return [$orderMeta, $orderInput];
        }

        $orderMeta['food_truck_id'] = $foodTruck->public_id;
        $driverAssigned             = $foodTruck->getDriverAssigned();
        if ($driverAssigned) {
            $orderInput['driver_assigned_uuid'] = $driverAssigned->uuid;
        }

        return [$orderMeta, $orderInput];
    }

    /**
     * Resolves the customer a checkout is for.
     *
     * The customer used to be taken from the request body and trusted. A storefront
     * key is client-side by nature — it ships inside the storefront app — and customer
     * public ids appear in ordinary API responses, so anyone holding a key could check
     * out as an arbitrary customer simply by passing their id.
     *
     * When a Customer-Token is present it now wins, and a body parameter naming a
     * different customer is refused rather than silently honoured. Guest checkout is
     * unaffected: with no token the body parameter is still used, since a guest has no
     * token to present.
     *
     * @return Customer|JsonResponse|null
     */
    protected static function resolveCheckoutCustomer(?string $customerId)
    {
        $authenticated = Storefront::getCustomerFromToken();

        // Guest checkout — no token to check against.
        if (!$authenticated) {
            return $customerId ? Customer::findFromCustomerId($customerId) : null;
        }

        if ($customerId) {
            // A storefront customer is stored as a Contact, and findFromCustomerId()
            // rewrites a customer_ prefix to contact_ before looking it up. Compare in
            // that same space, or a caller's own customer_xxxx would never match the
            // contact_xxxx on their record and every authenticated checkout would 403.
            $normalized = Str::startsWith($customerId, 'customer')
                ? Str::replaceFirst('customer', 'contact', $customerId)
                : $customerId;

            if ($authenticated->public_id !== $normalized) {
                return response()->apiError('Customer does not match the authenticated session.', 403);
            }
        }

        return Customer::findFromCustomerId($authenticated->public_id) ?? $authenticated;
    }

    public function beforeCheckout(InitializeCheckoutRequest $request)
    {
        $gatewayCode      = $request->input('gateway');
        $customerId       = $request->input('customer');
        $cartId           = $request->input('cart');
        $serviceQuoteId   = $request->or(['serviceQuote', 'service_quote']);
        $isCashOnDelivery = $request->input('cash') || $gatewayCode === 'cash';
        $isPickup         = $request->input('pickup', false);
        $tip              = $request->input('tip', false);
        $deliveryTip      = $request->or(['deliveryTip', 'delivery_tip'], false);

        // create checkout options
        $checkoutOptions = Utils::createObject([
            'is_pickup'    => $isPickup,
            'is_cod'       => $isCashOnDelivery,
            'tip'          => $tip,
            'delivery_tip' => $deliveryTip,
        ]);

        // find and validate cart session
        $cart           = Cart::retrieve($cartId);
        $cartValidation = $this->validateMarketplaceCart($cart);
        if ($cartValidation) {
            return $cartValidation;
        }
        $gateway      = Storefront::findGateway($gatewayCode);
        $customer     = static::resolveCheckoutCustomer($customerId);
        if ($customer instanceof JsonResponse) {
            return $customer;
        }
        $serviceQuote = ServiceQuote::select(['amount', 'meta', 'uuid', 'public_id'])->where('public_id', $serviceQuoteId)->first();

        // price promotions onto the checkout
        $promotionError = static::applyPromotions($cart, $serviceQuote, $checkoutOptions, $customer, $request);
        if ($promotionError) {
            return $promotionError;
        }

        // handle cash orders
        if ($isCashOnDelivery) {
            return static::initializeCashCheckout($customer, $gateway, $serviceQuote, $cart, $checkoutOptions, $request);
        }

        if (!$gateway) {
            return response()->apiError('No gateway configured!');
        }

        // handle checkout initialization based on gateway
        if ($gateway->isStripeGateway) {
            return static::initializeStripeCheckout($customer, $gateway, $serviceQuote, $cart, $checkoutOptions, $request);
        }

        // handle checkout initialization based on gateway
        if ($gateway->isQPayGateway) {
            return static::initializeQPayCheckout($customer, $gateway, $serviceQuote, $cart, $checkoutOptions, $request);
        }

        return response()->apiError('Unable to initialize checkout!');
    }

    public static function initializeCashCheckout(Contact $customer, Gateway $gateway, ?ServiceQuote $serviceQuote, Cart $cart, $checkoutOptions, $request)
    {
        // check if pickup order
        $isPickup = $checkoutOptions->is_pickup;

        // get amount/subtotal
        $amount   = static::calculateCheckoutAmount($cart, $serviceQuote, $checkoutOptions);
        $currency = $cart->getCurrency();

        // get store id if applicable
        $storeId = session('storefront_store');

        if (!$storeId) {
            $storeIds = collect($cart->items)->map(function ($cartItem) {
                return $cartItem->store_id;
            })->unique()->filter();

            if ($storeIds->count() === 1) {
                $publicStoreId = $storeIds->first();

                if (Str::startsWith($publicStoreId, 'store_')) {
                    $storeId = Store::select('uuid')->where('public_id', $publicStoreId)->first()->uuid;
                }
            }
        }

        // create checkout token
        $checkout = Checkout::create([
            'company_uuid'       => session('company'),
            'store_uuid'         => $storeId,
            'network_uuid'       => session('storefront_network'),
            'cart_uuid'          => $cart->uuid,
            'gateway_uuid'       => $gateway->uuid ?? null,
            'service_quote_uuid' => $serviceQuote?->uuid,
            'owner_uuid'         => $customer->uuid,
            'owner_type'         => 'fleet-ops:contact',
            'amount'             => $amount,
            'currency'           => $currency,
            'is_cod'             => true,
            'is_pickup'          => $isPickup,
            'options'            => $checkoutOptions,
            'cart_state'         => $cart->toArray(),
        ]);

        // `checkout` is the chkt_* public id and `token` is a separate checkout_* value.
        // GET /checkouts/status needs BOTH, and only initializeQPayCheckout was returning
        // the id — so a cash or card client could never reach its own checkout's status.
        // The checkout is discarded instead if one of its promotions ran out meanwhile.
        return static::reservePromotions($checkout, $checkoutOptions, $customer) ?? static::checkoutResponse($checkout, [
            'checkout' => $checkout->public_id,
            'token'    => $checkout->token,
        ]);
    }

    public static function initializeStripeCheckout(Contact $customer, Gateway $gateway, ?ServiceQuote $serviceQuote, Cart $cart, $checkoutOptions, $request)
    {
        // check if pickup order
        $isPickup = $checkoutOptions->is_pickup;

        // get amount/subtotal
        $amount   = static::calculateCheckoutAmount($cart, $serviceQuote, $checkoutOptions);
        $currency = $cart->getCurrency();

        // check for secret key first
        if (!static::hasStripeSecret($gateway)) {
            return response()->apiError('Gateway not configured correctly!');
        }

        // Set the stipre secret key from gateway
        \Stripe\Stripe::setApiKey($gateway->config->secret_key);

        // Check customer meta for stripe id
        try {
            if ($customer->missingMeta('stripe_id')) {
                Storefront::createStripeCustomerForContact($customer);
            }
        } catch (StripeAuthenticationException $e) {
            return static::stripeAuthenticationError($gateway, 'create_customer');
        }

        $ephemeralKey = null;

        try {
            $ephemeralKey = \Stripe\EphemeralKey::create(
                ['customer' => $customer->getMeta('stripe_id')],
                ['stripe_version' => '2020-08-27']
            );
        } catch (StripeAuthenticationException $e) {
            return static::stripeAuthenticationError($gateway, 'create_ephemeral_key');
        } catch (InvalidRequestException $e) {
            $errorMessage = $e->getMessage();

            if (Str::contains($errorMessage, 'No such customer')) {
                // create the customer for this network/store
                try {
                    Storefront::createStripeCustomerForContact($customer);
                    // regenerate key
                    $ephemeralKey = \Stripe\EphemeralKey::create(
                        ['customer' => $customer->getMeta('stripe_id')],
                        ['stripe_version' => '2020-08-27']
                    );
                } catch (StripeAuthenticationException $e) {
                    return static::stripeAuthenticationError($gateway, 'recreate_customer');
                }
            } else {
                return response()->apiError('Error from Stripe: ' . $errorMessage);
            }
        }

        // Prepare payment intent data
        $paymentIntentData = [
            'amount'   => Utils::formatAmountForStripe($amount, $currency),
            'currency' => $currency,
            'customer' => $customer->getMeta('stripe_id'),
        ];

        // Check if customer has a saved default payment method
        if (StripeUtils::isCustomerPaymentMethodValid($customer)) {
            $paymentIntentData['payment_method'] = $customer->getMeta('stripe_payment_method_id');
        }

        try {
            $paymentIntent = \Stripe\PaymentIntent::create($paymentIntentData);
        } catch (StripeAuthenticationException $e) {
            return static::stripeAuthenticationError($gateway, 'create_payment_intent');
        } catch (\Exception $e) {
            return response()->apiError($e->getMessage());
        }

        // create checkout token
        $checkout = Checkout::create([
            'company_uuid'             => session('company'),
            'store_uuid'               => session('storefront_store'),
            'network_uuid'             => session('storefront_network'),
            'cart_uuid'                => $cart->uuid,
            'gateway_uuid'             => $gateway->uuid,
            'service_quote_uuid'       => $serviceQuote ? $serviceQuote->uuid : null,
            'owner_uuid'               => $customer->uuid,
            'owner_type'               => 'fleet-ops:contact',
            'amount'                   => $amount,
            'currency'                 => $currency,
            'is_pickup'                => $isPickup,
            'options'                  => $checkoutOptions,
            'cart_state'               => $cart->toArray(),
            'stripe_payment_intent_id' => $paymentIntent->id,
        ]);

        // See initializeCheckout: `checkout` is the chkt_* public id GET /checkouts/status
        // requires alongside the token, and nothing but the QPay path used to return it.
        return static::reservePromotions($checkout, $checkoutOptions, $customer) ?? static::checkoutResponse($checkout, [
            'paymentIntent' => $paymentIntent->id,
            'clientSecret'  => $paymentIntent->client_secret,
            'ephemeralKey'  => $ephemeralKey->secret,
            'customerId'    => $customer->getMeta('stripe_id'),
            'checkout'      => $checkout->public_id,
            'token'         => $checkout->token,
        ]);
    }

    public function createStripeSetupIntentForCustomer(CreateStripeSetupIntentRequest $request)
    {
        $customerId = $request->input('customer');
        $gateway    = Storefront::findGateway('stripe');

        if (!$gateway) {
            return response()->apiError('Stripe not setup.');
        }

        if (!static::hasStripeSecret($gateway)) {
            return response()->apiError('Gateway not configured correctly!');
        }

        $customer = static::resolveCheckoutCustomer($customerId);
        if ($customer instanceof JsonResponse) {
            return $customer;
        }

        \Stripe\Stripe::setApiKey($gateway->config->secret_key);

        // Ensure customer has a stripe_id
        try {
            if ($customer->missingMeta('stripe_id')) {
                Storefront::createStripeCustomerForContact($customer);
            }
        } catch (StripeAuthenticationException $e) {
            return static::stripeAuthenticationError($gateway, 'create_setup_customer');
        }

        // Prepare payment intent data
        $paymentIntentData = [
            'customer' => $customer->getMeta('stripe_id'),
        ];

        // Check if customer has a saved default payment method
        if (StripeUtils::isCustomerPaymentMethodValid($customer)) {
            $paymentIntentData['payment_method'] = $customer->getMeta('stripe_payment_method_id');
        }

        try {
            // Create SetupIntent
            $setupIntent = \Stripe\SetupIntent::create($paymentIntentData);

            $defaultPaymentMethod = null;
            $savedPaymentMethodId = $customer->getMeta('stripe_payment_method_id');

            if ($savedPaymentMethodId) {
                // Attempt to retrieve the stored payment method from Stripe
                try {
                    $pm = \Stripe\PaymentMethod::retrieve($savedPaymentMethodId);
                    if ($pm && $pm->customer === $customer->getMeta('stripe_id')) {
                        $defaultPaymentMethod = [
                            'paymentMethodId'        => $pm->id,
                            'id'                     => $pm->id,
                            'brand'                  => Str::title($pm->card->brand),
                            'last4'                  => $pm->card->last4,
                            'label'                  => $pm->card->last4,
                            'exp_month'              => $pm->card->exp_month,
                            'exp_year'               => $pm->card->exp_year,
                            'country'                => $pm->card->country,
                            'funding'                => $pm->card->funding,
                        ];
                    }
                } catch (\Exception $e) {
                    // If retrieval fails, we just won't have a defaultPaymentMethod
                    Log::warning('Failed to retrieve saved payment method from Stripe: ' . $e->getMessage());
                }
            }

            return response()->json([
                'setupIntent'          => $setupIntent->id,
                'clientSecret'         => $setupIntent->client_secret,
                'defaultPaymentMethod' => $defaultPaymentMethod,
                'customerId'           => $customer->getMeta('stripe_id'),
            ]);
        } catch (StripeAuthenticationException $e) {
            return static::stripeAuthenticationError($gateway, 'create_setup_intent');
        } catch (\Exception $e) {
            return response()->apiError($e->getMessage());
        }
    }

    public function updateStripePaymentIntent(Request $request)
    {
        // Extract necessary parameters from request
        $customerId        = $request->input('customer');
        $cartId            = $request->input('cart');
        $serviceQuoteId    = $request->or(['serviceQuote', 'service_quote']);
        $paymentIntentId   = $request->or(['paymentIntent', 'paymentIntentId', 'payment_intent_id']);
        $isPickup          = $request->input('pickup', false);
        $tip               = $request->input('tip', false);
        $deliveryTip       = $request->or(['deliveryTip', 'delivery_tip'], false);

        // Create checkout options from request
        $checkoutOptions = Utils::createObject([
            'is_pickup'    => $isPickup,
            'tip'          => $tip,
            'delivery_tip' => $deliveryTip,
        ]);

        // Retrieve the gateway (stripe)
        $gateway = Storefront::findGateway('stripe');
        if (!$gateway) {
            return response()->apiError('No stripe gateway configured!');
        }

        // Retrieve and validate necessary models
        $cart = Cart::retrieve($cartId);
        // Cart::retrieve() always returns either the persisted cart or a new cart instance.
        // @codeCoverageIgnoreStart
        if (!$cart) {
            return response()->apiError('Invalid cart ID provided');
        }
        // @codeCoverageIgnoreEnd

        $customer = static::resolveCheckoutCustomer($customerId);
        if ($customer instanceof JsonResponse) {
            return $customer;
        }
        if (!$customer) {
            return response()->apiError('Invalid customer ID provided');
        }

        $serviceQuote = ServiceQuote::select(['amount', 'meta', 'uuid', 'public_id'])
            ->where('public_id', $serviceQuoteId)
            ->first();

        // Price promotions onto the new checkout
        $promotionError = static::applyPromotions($cart, $serviceQuote, $checkoutOptions, $customer, $request);
        if ($promotionError) {
            return $promotionError;
        }

        // Recalculate amount based on cart, serviceQuote, and checkoutOptions
        $amount   = static::calculateCheckoutAmount($cart, $serviceQuote, $checkoutOptions);
        $currency = $cart->getCurrency();

        // Check for Stripe secret key
        if (!static::hasStripeSecret($gateway)) {
            return response()->apiError('Gateway not configured correctly!');
        }

        // Set Stripe API key
        \Stripe\Stripe::setApiKey($gateway->config->secret_key);

        // Ensure customer has a stripe_id
        try {
            if ($customer->missingMeta('stripe_id')) {
                Storefront::createStripeCustomerForContact($customer);
            }
        } catch (StripeAuthenticationException $e) {
            return static::stripeAuthenticationError($gateway, 'create_update_customer');
        }

        // Retrieve the existing PaymentIntent
        try {
            $paymentIntent = \Stripe\PaymentIntent::retrieve($paymentIntentId);
        } catch (StripeAuthenticationException $e) {
            return static::stripeAuthenticationError($gateway, 'retrieve_payment_intent');
        } catch (\Exception $e) {
            return response()->apiError('Failed to retrieve PaymentIntent: ' . $e->getMessage());
        }

        // Check if PaymentIntent is in a modifiable state
        $modifiableStatuses = ['requires_payment_method', 'requires_confirmation', 'requires_action', 'processing'];
        if (!in_array($paymentIntent->status, $modifiableStatuses)) {
            return response()->apiError('PaymentIntent cannot be updated at this stage.');
        }

        // Prepare the updated data
        $updateData = [
            'amount'   => Utils::formatAmountForStripe($amount, $currency),
            'currency' => $currency,
        ];

        // Update the PaymentIntent
        try {
            $paymentIntent = \Stripe\PaymentIntent::update($paymentIntentId, $updateData);
        } catch (StripeAuthenticationException $e) {
            return static::stripeAuthenticationError($gateway, 'update_payment_intent');
        } catch (\Exception $e) {
            return response()->apiError('Failed to update PaymentIntent: ' . $e->getMessage());
        }

        // If payment intent has a payment method set already update for the customer
        $paymentIntentPaymentMethodId = $paymentIntent->payment_method;
        $customerPaymentMethodId      = $customer->getMeta('stripe_payment_method_id');
        if ($paymentIntentPaymentMethodId !== $customerPaymentMethodId) {
            $customer->updateMeta('stripe_payment_method_id', $paymentIntentPaymentMethodId);
        }

        // Create a new EphemeralKey if needed for the frontend
        try {
            $ephemeralKey = \Stripe\EphemeralKey::create(
                ['customer' => $customer->getMeta('stripe_id')],
                ['stripe_version' => '2020-08-27']
            );
        } catch (StripeAuthenticationException $e) {
            return static::stripeAuthenticationError($gateway, 'update_ephemeral_key');
        } catch (\Exception $e) {
            return response()->apiError('Failed to create ephemeral key: ' . $e->getMessage());
        }

        $attributes = [
            'company_uuid'       => session('company'),
            'store_uuid'         => session('storefront_store'),
            'network_uuid'       => session('storefront_network'),
            'cart_uuid'          => $cart->uuid,
            'gateway_uuid'       => $gateway->uuid,
            'service_quote_uuid' => $serviceQuote ? $serviceQuote->uuid : null,
            'owner_uuid'         => $customer->uuid,
            'owner_type'         => 'fleet-ops:contact',
            'amount'             => $amount,
            'currency'           => $currency,
            'is_pickup'          => $isPickup,
            'options'            => $checkoutOptions,
            'cart_state'         => $cart->toArray(),
        ];

        // Capture verifies the payment against the checkout's PaymentIntent, and a
        // PaymentIntent belongs to one checkout, so update the checkout that started it
        // rather than adding an unlinked one.
        $checkout = Checkout::where('stripe_payment_intent_id', $paymentIntent->id)->first();
        if ($checkout && $checkout->owner_uuid !== $customer->uuid) {
            return response()->apiError('PaymentIntent belongs to another checkout.', 422);
        }
        if ($checkout) {
            // Its promotion uses are reserved again below at the new price.
            PromotionRedemptions::releaseFor($checkout);
            $checkout->update($attributes);
        } else {
            $checkout = Checkout::create([...$attributes, 'stripe_payment_intent_id' => $paymentIntent->id]);
        }

        // Return JSON response with updated PaymentIntent and ephemeral key. `checkout` is
        // the chkt_* public id GET /checkouts/status requires alongside the token.
        return static::reservePromotions($checkout, $checkoutOptions, $customer) ?? static::checkoutResponse($checkout, [
            'paymentIntent' => $paymentIntent->id,
            'clientSecret'  => $paymentIntent->client_secret,
            'ephemeralKey'  => $ephemeralKey->secret,
            'customerId'    => $customer->getMeta('stripe_id'),
            'checkout'      => $checkout->public_id,
            'token'         => $checkout->token,
        ]);
    }

    public static function initializeQPayCheckout(Contact $customer, Gateway $gateway, ?ServiceQuote $serviceQuote, Cart $cart, $checkoutOptions, $request)
    {
        // Get store info
        $about = Storefront::about();

        // check if pickup order
        $isPickup = $checkoutOptions->is_pickup;

        // Get ebarimt company registration number if any
        $ebarimtRegistationNumber = $request->ebarimt_registration_no ?? '';
        if ($ebarimtRegistationNumber) {
            $customer->updateMeta('ebarimt_registration_no', $ebarimtRegistationNumber);
        }

        // get amount/subtotal
        $amount   = static::calculateCheckoutAmount($cart, $serviceQuote, $checkoutOptions);
        $currency = $cart->getCurrency();

        // check for secret key first
        if (!isset($gateway->config->username)) {
            return response()->apiError('Gateway not configured correctly!');
        }

        // Create qpay instance
        $qpay = static::qpayForGateway($gateway);
        if ($gateway->sandbox) {
            $qpay = $qpay->useSandbox();
        }

        // Set auth token
        $qpay = $qpay->setAuthToken();

        // Test payment
        $testPayment = is_string(data_get($checkoutOptions, 'testPayment')) && $gateway->sandbox;

        // Create checkout token
        $checkout = Checkout::create([
            'company_uuid'       => session('company'),
            'store_uuid'         => session('storefront_store'),
            'network_uuid'       => session('storefront_network'),
            'cart_uuid'          => $cart->uuid,
            'gateway_uuid'       => $gateway->uuid,
            'service_quote_uuid' => $serviceQuote ? $serviceQuote->uuid : null,
            'owner_uuid'         => $customer->uuid,
            'owner_type'         => 'fleet-ops:contact',
            'amount'             => $amount,
            'currency'           => $currency,
            'is_pickup'          => $isPickup,
            'options'            => $checkoutOptions,
            'cart_state'         => $cart->toArray(),
        ]);

        // Set QPay Callback
        $callbackParams = ['checkout' => $checkout->public_id];
        if ($testPayment) {
            $callbackParams['test'] = data_get($checkoutOptions, 'testPayment');
        }
        $qpay->setCallback(QPay::callbackUrl($callbackParams));

        // Create invoice description
        $taxType             = '1'; // Start with VAT required
        // Sandbox e-barimt invoices use QPay's TEST_EB_INVOICE code (QPay API v2, invoice_create_ebarimt).
        $ebarimtInvoiceCode  = $gateway->sandbox ? 'TEST_EB_INVOICE' : $gateway->config?->ebarimt_invoice_id ?? null;
        $invoiceAmount       = $amount;
        $invoiceCode         = $gateway->sandbox ? 'TEST_INVOICE' : $gateway->config?->invoice_id ?? null;
        $invoiceDescription  = $about->name . ' cart checkout';
        // The customer's own unique code (QPay: "unique number of the customer receiving the
        // invoice"). The e-barimt receiver type is sent separately, with ebarimt_v3/create.
        $invoiceReceiverCode = static::qpayCode($customer->public_id);
        // Unique per checkout, without special characters (QPay: sender_invoice_no).
        $senderInvoiceNo     = static::qpayCode($checkout->public_id);
        $districtCode        = $gateway->config?->district_code ?? null;
        // QPay requires district_code on e-barimt invoices (QPay API v2, invoice_create_ebarimt):
        // the 4-digit code of where the business operates (district + sub-district, see the
        // district_code list), set in the gateway settings. QPay's sandbox accepts invoices
        // without it, so a live gateway missing it is reported rather than guessed.
        if ($ebarimtInvoiceCode && !$districtCode && !$gateway->sandbox) {
            Log::warning('[QPAY]: e-barimt invoice without a district code; set district_code in the QPay gateway settings', ['gateway' => $gateway->public_id]);
        }
        $invoiceReceiverData = Utils::filterArray([
            'register' => $ebarimtRegistationNumber,
            'name'     => $customer->name,
            'email'    => $customer->email ?? null,
            'phone'    => $customer->phone ?? null,
        ]);

        // Create QPay line items
        $lines        = QPay::createQpayInitialLines($cart, $serviceQuote, $checkoutOptions);
        $cartProducts = Product::whereIn('public_id', collect($cart->items)->pluck('product_id')->filter()->unique())->get()->keyBy('public_id');
        foreach ($cart->items as $item) {
            $product            = $item->product_id ? $cartProducts->get($item->product_id) : null;
            $classificationCode = QPay::getCartItemClassificationCode($item, $product);
            $isVatExempt        = QPay::isTaxFreeClassificationCode($classificationCode);

            $line = [
                'line_description'    => $item->name,
                'line_quantity'       => number_format($item->quantity ?? 1, 2, '.', ''),
                'line_unit_price'     => number_format($item->price, 2, '.', ''),
                'note'                => $checkout->public_id,
                'classification_code' => $classificationCode,
                'tax_product_code'    => QPay::getCartItemTaxProductCode($item, $product),
                'taxes'               => [
                    [
                        'tax_code'    => 'VAT',
                        'description' => 'VAT',
                        'amount'      => QPay::calculateTax($item->subtotal),
                        'note'        => $checkout->public_id,
                    ],
                ],
            ];

            $lines[] = $line;
        }

        // Create qpay invoice
        $invoice = null;
        if ($ebarimtInvoiceCode) {
            $invoice = $qpay->createEbarimtInvoice($ebarimtInvoiceCode, $senderInvoiceNo, $invoiceReceiverCode, $invoiceReceiverData, $invoiceDescription, $taxType, $districtCode, $lines);
        } else {
            $invoice = $qpay->createSimpleInvoice($invoiceAmount, $invoiceCode, $invoiceDescription, $invoiceReceiverCode, $senderInvoiceNo);
        }

        // Update checkout with invoice id, and the amount the invoice asks for so a payment
        // can be checked against it before the order is created.
        $checkout->updateOption('qpay_invoice_id', data_get($invoice, 'invoice_id'));
        if (data_get($invoice, 'invoice_id')) {
            $checkout->updateOption('qpay_invoice_amount', $ebarimtInvoiceCode ? QPay::linesTotal($lines) : (float) $invoiceAmount);
        }

        return static::reservePromotions($checkout, $checkoutOptions, $customer) ?? static::checkoutResponse($checkout, [
            'invoice'  => $invoice,
            'checkout' => $checkout->public_id,
            'token'    => $checkout->token,
        ]);
    }

    /**
     * Capture and process QPay callback.
     *
     * This controller method handles QPay callback requests by verifying and processing
     * payment information for a specified checkout. It performs the following steps:
     *
     * - Retrieves the checkout identifier from the request.
     * - Looks up the associated Checkout and Gateway records.
     * - If in sandbox mode and a test scenario is provided, it simulates a test payment
     *   response for either success or error scenarios.
     * - Initializes a QPay instance with the gateway configuration and sets the authentication token.
     * - Retrieves the invoice ID from the checkout options and performs a payment check using QPay's API.
     * - Publishes the checkout status, its order and any error to the checkout's realtime channel.
     *
     * Depending on the 'respond' flag from the request, the method returns a JSON response
     * or completes the processing without returning data.
     *
     * @param Request $request The HTTP request containing:
     *                         - `checkout` (string): The public checkout identifier.
     *                         - `respond` (boolean): Whether to return a JSON response.
     *                         - `test` (string|null): A test scenario indicator ('success' or 'error') for sandbox mode.
     *
     * @return JsonResponse a JSON response with payment data or error details
     *
     * @throws \Exception if an error occurs during payment processing, an API error is returned
     */
    public function captureQPayCallback(Request $request)
    {
        $checkoutId    = $request->input('checkout');
        $shouldRespond = $request->boolean('respond');
        $testScenario  = $request->input('test'); // Expected: 'success' or 'error'

        // QPay calls this URL (GET) when a payment is made and requires the reply to be
        // HTTP 200 with the body SUCCESS, in no other format. `respond=1` (manual checks)
        // gets the details as JSON instead.
        $reply = fn (array $data) => $shouldRespond ? response()->json($data) : response('SUCCESS', 200)->header('Content-Type', 'text/plain');

        if (!$checkoutId) {
            return $reply([
                'error'    => 'CHECKOUT_ID_MISSING',
                'checkout' => null,
                'payment'  => null,
            ]);
        }

        $checkout = Checkout::where('public_id', $checkoutId)->first();
        if (!$checkout) {
            return $reply([
                'error'    => 'CHECKOUT_SESSION_NOT_FOUND',
                'checkout' => null,
                'payment'  => null,
            ]);
        }

        $gateway = Gateway::where('uuid', $checkout->gateway_uuid)->first();
        if (!$gateway) {
            return $reply([
                'error'    => 'GATEWAY_NOT_CONFIGURED',
                'checkout' => $checkout->public_id,
                'payment'  => null,
            ]);
        }

        try {
            // Handle test scenarios if in sandbox mode.
            if ($gateway->sandbox && in_array($testScenario, ['success', 'error'], true)) {
                $data = [
                    'checkout' => $checkout->public_id,
                    'payment'  => null,
                    'error'    => null,
                ];

                if ($testScenario === 'success') {
                    $data['payment'] = QPay::createTestPaymentDataFromCheckout($checkout);
                } else {
                    $data['error'] = [
                        'error'   => 'PAYMENT_NOT_PAID',
                        'message' => 'Payment has not been paid!',
                    ];
                }

                static::publishCheckoutUpdate($checkout, $testScenario === 'success', $data['error']);

                return $reply($data);
            }

            // Create the QPay instance.
            $qpay = static::qpayForGateway($gateway);

            if ($gateway->sandbox) {
                $qpay->useSandbox();
            }

            $qpay->setAuthToken();

            $invoiceId = $checkout->getOption('qpay_invoice_id');
            if (!$invoiceId) {
                Log::error("Missing QPay invoice ID for checkout: {$checkout->public_id}");

                return $reply([
                    'error'    => 'MISSING_INVOICE_ID',
                    'checkout' => $checkout->public_id,
                    'payment'  => null,
                ]);
            }

            // The order is created only for a payment QPay reports as PAID, covering the
            // invoice amount. NEW, FAILED, PARTIAL and REFUNDED payments create nothing.
            $paymentCheck = $qpay->paymentCheck($invoiceId);
            $payment      = static::paidQPayPayment($paymentCheck, $checkout);
            if (!$payment) {
                return $reply([
                    'error'    => 'PAYMENT_NOT_PAID',
                    'checkout' => $checkout->public_id,
                    'payment'  => null,
                ]);
            }

            // Tell the app at once that the payment is confirmed; creating the order takes a
            // moment, and a second update follows with the order.
            static::publishCheckoutUpdate($checkout, true);

            // Create order from payment using reusable gateway-agnostic method
            $transactionDetails = [
                'transaction_id' => $payment->payment_id,
                'payment_status' => $payment->payment_status,
                'payment_wallet' => $payment->payment_wallet ?? 'QPay',
            ];

            $this->createOrderFromCheckout($checkout, $transactionDetails);
            $checkout->refresh();

            static::publishCheckoutUpdate($checkout, true);

            return $reply([
                'checkout' => $checkout->public_id,
                'payment'  => (array) $payment,
                'error'    => null,
            ]);
        } catch (\Exception $e) {
            Log::error('[QPAY CHECKOUT ERROR]: ' . $e->getMessage(), ['checkout' => $checkout->toArray()]);
            if ($shouldRespond) {
                return response()->apiError($e->getMessage());
            }
        }

        return $reply(['checkout' => $checkout->public_id, 'payment' => null, 'error' => null]);
    }

    /**
     * Make the checkout's storefront (its network, else its store) the storefront of the
     * current request, exactly as SetStorefrontSession does for the storefront API. Order
     * creation reads the storefront from there, and requests from a payment provider (QPay's
     * callback) arrive without one. Always the checkout's own, so an order is never created
     * under another storefront's settings.
     */
    protected static function useCheckoutStorefront(Checkout $checkout): void
    {
        $owner = $checkout->network_uuid
            ? Network::select(['key'])->where('uuid', $checkout->network_uuid)->first()
            : ($checkout->store_uuid ? Store::select(['key'])->where('uuid', $checkout->store_uuid)->first() : null);

        if ($owner && $owner->key) {
            app(SetStorefrontSession::class)->setKey($owner->key);
        }
    }

    /**
     * The payment that pays a checkout's QPay invoice, or null.
     *
     * QPay's payment/check returns `count`, `paid_amount` and `rows`, each row with a
     * `payment_status` of NEW, FAILED, PAID, PARTIAL or REFUNDED. A checkout is paid only
     * by a PAID row, and only when the amount paid covers the invoice amount recorded when
     * the invoice was created (older checkouts without it are checked by status only).
     */
    protected static function paidQPayPayment($paymentCheck, Checkout $checkout): ?object
    {
        $rows = collect(data_get($paymentCheck, 'rows', []));
        $paid = $rows->first(fn ($row) => data_get($row, 'payment_status') === 'PAID');
        if (!$paid) {
            return null;
        }

        $expected = $checkout->getOption('qpay_invoice_amount');
        if ($expected !== null) {
            $paidAmount = data_get($paymentCheck, 'paid_amount');
            if ($paidAmount === null) {
                $paidAmount = $rows->filter(fn ($row) => data_get($row, 'payment_status') === 'PAID')->sum(fn ($row) => (float) data_get($row, 'payment_amount', 0));
            }
            // Amounts are decimals in the invoice currency; allow for rounding only.
            if ((float) $paidAmount + 0.01 < (float) $expected) {
                Log::warning('[QPAY]: payment is less than the invoice amount; no order created', [
                    'checkout'    => $checkout->public_id,
                    'paid_amount' => $paidAmount,
                    'expected'    => $expected,
                ]);

                return null;
            }
        }

        return (object) $paid;
    }

    /**
     * A value QPay accepts where it doesn't allow special characters (sender_invoice_no,
     * invoice_receiver_code): letters and digits only, at most 45 characters.
     */
    protected static function qpayCode(?string $value): string
    {
        return substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $value), 0, 45);
    }

    /**
     * Create order from checkout session.
     *
     * Gateway-agnostic reusable method to create an order from a checkout session.
     * Works with any payment gateway (QPay, Stripe, etc.) by delegating to captureOrder().
     * Handles idempotency by checking if order already exists.
     *
     * @param Checkout    $checkout           The checkout session
     * @param array       $transactionDetails Payment gateway transaction details
     * @param string|null $notes              Optional notes for the order
     *
     * @return Order|null The created order or null if already exists/error
     */
    protected function createOrderFromCheckout($checkout, $transactionDetails, $notes = null)
    {
        // Define a unique lock key for this specific checkout
        $lockKey = 'create-order-checkout-' . $checkout->uuid;

        // Keep the lock for the full provider/order workflow. Checkout creation can
        // legitimately take longer than ten seconds when downstream services are slow.
        $lock = Cache::lock($lockKey, 120);

        if ($lock->get()) {
            try {
                // Re-fetch checkout to ensure we have the latest data after acquiring lock
                $checkout->refresh();

                // Check if order already exists for this checkout
                if ($checkout->order_uuid) {
                    Log::info('[ORDER CREATION]: Order already exists for checkout after acquiring lock', [
                        'checkout_id' => $checkout->public_id,
                        'order_id'    => $checkout->order_uuid,
                    ]);

                    return $checkout->order;
                }

                Log::info('[ORDER CREATION]: Creating order from payment', [
                    'checkout_id'    => $checkout->public_id,
                    'transaction_id' => $transactionDetails['transaction_id'] ?? null,
                ]);

                // captureOrder() works within the storefront of the request. QPay's callback
                // carries no storefront key, so set the checkout's own storefront the way the
                // storefront API does for app requests.
                static::useCheckoutStorefront($checkout);

                // Create CaptureOrderRequest with payment details
                $captureRequest = CaptureOrderRequest::create('', 'POST', [
                    'token'              => $checkout->token,
                    'transactionDetails' => $transactionDetails,
                    'notes'              => $notes,
                ]);
                $captureRequest->attributes->set('storefront_checkout_lock_held', true);

                // Call captureOrder to create the order
                $this->captureOrder($captureRequest);

                // Reload checkout to get the created order
                $checkout->refresh();

                if ($checkout->order) {
                    Log::info('[ORDER CREATION]: Order created successfully', [
                        'checkout_id' => $checkout->public_id,
                        'order_id'    => $checkout->order->public_id,
                    ]);

                    return $checkout->order;
                }

                Log::warning('[ORDER CREATION]: captureOrder completed but no order found on checkout', [
                    'checkout_id' => $checkout->public_id,
                ]);

                return null;
            } catch (\Exception $e) {
                Log::error('[ORDER CREATION ERROR]: ' . $e->getMessage(), [
                    'checkout_id'         => $checkout->public_id,
                    'transaction_details' => $transactionDetails,
                    'exception'           => $e->getTraceAsString(),
                ]);

                return null;
            } finally {
                // Always release the lock
                $lock->release();
            }
        } else {
            // Could not acquire lock - another process is creating the order
            Log::info('[ORDER CREATION]: Could not acquire lock, another process is creating order', [
                'checkout_id' => $checkout->public_id,
            ]);

            // Wait briefly and return the order that should be created by the other process
            sleep(2);
            $checkout->refresh();

            if ($checkout->order) {
                Log::info('[ORDER CREATION]: Order found after waiting for lock', [
                    'checkout_id' => $checkout->public_id,
                    'order_id'    => $checkout->order->public_id,
                ]);

                return $checkout->order;
            }

            Log::warning('[ORDER CREATION]: Lock wait completed but no order found', [
                'checkout_id' => $checkout->public_id,
            ]);

            return null;
        }
    }

    /**
     * Process a cart item.
     *
     * @param mixed $cartItem the cart item
     * @param mixed $payload  the payload
     * @param mixed $customer the customer
     *
     * @return void
     */
    /**
     * The booking an order's items make, if any: a cart with a booked service (a bookable
     * product with a chosen time) is a booking order. Products in it come with the earliest
     * appointment, so the order follows the booking flow and carries that appointment's time.
     *
     * The time is kept in the order meta (`booking_at`), not `scheduled_at`: Fleet-Ops
     * dispatches scheduled orders by themselves on the day, before the store confirms.
     */
    protected static function bookingFor(iterable $cartItems): ?array
    {
        $items      = collect($cartItems);
        $productIds = $items->map(fn ($item) => data_get($item, 'product_id'))->filter()->unique()->values();
        $bookable   = $productIds->isEmpty() ? collect() : Product::whereIn('public_id', $productIds)->where('is_bookable', true)->pluck('public_id');

        $times = $items
            ->filter(fn ($item) => data_get($item, 'scheduled_at') || $bookable->contains(data_get($item, 'product_id')))
            ->map(fn ($item) => data_get($item, 'scheduled_at'))
            ->filter()
            ->map(function ($at) {
                try {
                    return Carbon::parse($at);
                } catch (\Throwable $e) {
                    return null;
                }
            })
            ->filter()
            ->sort();

        $hasService = $items->contains(fn ($item) => data_get($item, 'scheduled_at') || $bookable->contains(data_get($item, 'product_id')));
        if (!$hasService) {
            return null;
        }

        $first = $times->first();

        return [
            'is_booking'        => true,
            'booking_at'        => $first ? $first->toIso8601String() : null,
            'booking_has_items' => $items->contains(fn ($item) => !data_get($item, 'scheduled_at') && !$bookable->contains(data_get($item, 'product_id'))),
        ];
    }

    /**
     * Mark an order as a booking (meta and the company's booking order config) when its items make one.
     */
    protected static function applyBooking(iterable $cartItems, array $orderMeta, array $orderInput): array
    {
        $booking = static::bookingFor($cartItems);
        if (!$booking) {
            return [$orderMeta, $orderInput];
        }

        $config = Storefront::getBookingOrderConfig($orderInput['company_uuid'] ?? null);
        if ($config) {
            $orderInput['order_config_uuid'] = $config->uuid;
        }

        return [array_merge($orderMeta, $booking), $orderInput];
    }

    private function processCartItem($cartItem, $payload, $customer)
    {
        $product = Product::where('public_id', $cartItem->product_id)->first();

        // Generate metas array
        $metas = [
            'variants'     => $cartItem->variants ?? [],
            'addons'       => $cartItem->addons ?? [],
            'subtotal'     => $cartItem->subtotal,
            'quantity'     => $cartItem->quantity,
            'scheduled_at' => $cartItem->scheduled_at ?? null,
        ];

        // Create and fill entity
        $entity = Entity::fromStorefrontProduct($product, $metas)->fill([
            'company_uuid'  => session('company'),
            'payload_uuid'  => $payload->uuid,
            'customer_uuid' => $customer->uuid,
            'customer_type' => Utils::getMutationType('fleet-ops:contact'),
        ]);

        // Save entity
        $entity->save();
    }

    public function captureOrder(CaptureOrderRequest $request)
    {
        if (!$request->attributes->get('storefront_checkout_lock_held')) {
            $checkout = Checkout::where('token', $request->input('token'))->first();
            if (!$checkout) {
                return response()->apiError('Checkout session not found.');
            }

            return $this->captureOrderWithLock($checkout, $request);
        }

        $token              = $request->input('token');
        $transactionDetails = $request->input('transactionDetails', []); // optional details to be supplied about transaction
        $notes              = $request->input('notes');

        // validate transaction details
        if (!is_array($transactionDetails)) {
            $transactionDetails = [];
        }

        // get checkout data to create order
        $checkout = Checkout::where('token', $token)->with(['gateway', 'owner', 'serviceQuote', 'cart'])->first();
        if (!$checkout) {
            return response()->apiError('Checkout session not found.');
        }

        $about        = Storefront::about();
        if (!$about) {
            return response()->apiError('No storefront in request to capture order!');
        }

        $customer     = $checkout->owner;
        $serviceQuote = $checkout->serviceQuote;
        $gateway      = $checkout->is_cod ? Gateway::cash() : $checkout->gateway;
        $origin       = $serviceQuote ? $serviceQuote->getMeta('origin', []) : null;
        $destination  = $serviceQuote ? $serviceQuote->getMeta('destination') : null;
        // The cart as it was priced and charged (see Checkout::cartAtCheckout()); the live
        // cart only for checkouts saved before the copy existed.
        $cart         = $checkout->cartAtCheckout() ?? $checkout->cart;

        // If the checkout already has an order created
        if ($checkout->order_uuid) {
            $completedOrder = Order::where('uuid', $checkout->order_uuid)->first();
            if ($completedOrder) {
                return new OrderResource($completedOrder);
            }
        }

        // if cart is null then cart has either been deleted or expired
        if (!$cart) {
            return response()->apiError('Cart expired');
        }

        // $amount = $checkout->amount ?? ($checkout->is_pickup ? $cart->subtotal : $cart->subtotal + $serviceQuote->amount);
        $amount   = static::calculateCheckoutAmount($cart, $serviceQuote, $checkout->options);
        $currency = $checkout->currency ?? $cart->getCurrency();
        $store    = $about;

        if ($gateway && $gateway->isStripeGateway) {
            $stripeVerification = $this->verifyStripePaymentForCheckout($checkout, $gateway, $customer, (int) $amount, (string) $currency);
            if ($stripeVerification instanceof JsonResponse) {
                return $stripeVerification;
            }

            // Provider data is authoritative. Never allow client-supplied transaction
            // identifiers or payment status to replace the verified Stripe values.
            $transactionDetails = array_merge($transactionDetails, $stripeVerification);
            $request->merge(['transactionDetails' => $transactionDetails]);
        }

        // check if order is via network for a single store
        $isNetworkOrder          = $about->is_network === true;
        $isMultiCart             = $cart->isMultiCart;
        $isSingleStoreCheckout   = $isNetworkOrder && !$isMultiCart;
        $isMultipleStoreCheckout = $isNetworkOrder && $isMultiCart;

        // if multi store checkout send to captureMultipleOrders()
        if ($isMultipleStoreCheckout) {
            return $this->captureMultipleOrders($request);
        }

        // if single store set store variable
        if ($isSingleStoreCheckout) {
            $store = Storefront::findAbout($cart->checkoutStoreId);
        }

        // super rare condition
        if (!$store) {
            return response()->apiError('No storefront in request to capture order!');
        }

        // prepare for integrated vendor order if applicable
        $integratedVendorOrder = null;

        // if service quote is applied, resolve it
        if ($serviceQuote instanceof ServiceQuote && $serviceQuote->fromIntegratedVendor()) {
            $vendorResult = $this->createIntegratedVendorOrderSafely($serviceQuote, $request);
            if ($vendorResult['error']) {
                return $vendorResult['error'];
            }
            $integratedVendorOrder = $vendorResult['order'];
        }

        // setup transaction meta
        $transactionMeta = [
            'storefront'    => $store->name,
            'storefront_id' => $store->public_id,
            ...$transactionDetails,
        ];

        if ($about->is_network) {
            $transactionMeta['storefront_network']    = $about->name;
            $transactionMeta['storefront_network_id'] = $about->public_id;
        }

        // create transactions for cart
        $transaction = Transaction::create([
            'company_uuid'           => session('company'),
            'customer_uuid'          => $customer->uuid,
            'customer_type'          => Utils::getMutationType('fleet-ops:contact'),
            'gateway_transaction_id' => Utils::or($transactionDetails, ['id', 'transaction_id']) ?? Transaction::generateNumber(),
            'gateway'                => $gateway->code,
            'gateway_uuid'           => $gateway->uuid,
            'amount'                 => $amount,
            'currency'               => $currency,
            'description'            => 'Storefront order',
            'type'                   => 'storefront',
            'status'                 => Transaction::STATUS_SUCCESS,
            'settlement_status'      => Transaction::SETTLEMENT_STATUS_PAID,
            'settled_at'             => now(),
            'settled_amount'         => $amount,
            'settled_currency'       => $currency,
            'meta'                   => $transactionMeta,
        ]);

        // create transaction items
        foreach ($cart->items as $cartItem) {
            TransactionItem::create([
                'transaction_uuid' => $transaction->uuid,
                'amount'           => $cartItem->subtotal,
                'currency'         => $checkout->currency,
                'details'          => Storefront::getFullDescriptionFromCartItem($cartItem),
                'code'             => 'product',
            ]);
        }

        // create transaction item for service quote
        if (!$checkout->is_pickup) {
            TransactionItem::create([
                'transaction_uuid' => $transaction->uuid,
                'amount'           => $serviceQuote->amount,
                'currency'         => $serviceQuote->currency,
                'details'          => 'Delivery fee',
                'code'             => 'delivery_fee',
            ]);
        }

        // if tip create transaction item for tip
        if ($checkout->hasOption('tip')) {
            TransactionItem::create([
                'transaction_uuid' => $transaction->uuid,
                'amount'           => static::calculateTipAmount($checkout->getOption('tip'), $cart->subtotal),
                'currency'         => $checkout->currency,
                'details'          => 'Tip',
                'code'             => 'tip',
            ]);
        }

        // if delivery tip create transaction item for tip
        if ($checkout->hasOption('delivery_tip')) {
            TransactionItem::create([
                'transaction_uuid' => $transaction->uuid,
                'amount'           => static::calculateTipAmount($checkout->getOption('delivery_tip'), $cart->subtotal),
                'currency'         => $checkout->currency,
                'details'          => 'Delivery Tip',
                'code'             => 'delivery_tip',
            ]);
        }

        // if promotions were applied create a (credit) transaction item for the discount
        $promotions = PromotionResult::fromArray(data_get($checkout->options, 'promotions'));
        static::createDiscountTransactionItem($transaction, $promotions, $checkout->currency);

        // if single cart checkout and origin is array get the first id
        if (is_array($origin)) {
            $origin = Arr::first($origin);
        }

        // Check if the order origin is from a food truck via cart property
        $foodTruck = $this->resolveFoodTruck($cart);

        // Set food truck vehicle location as origin
        $foodTruckOrigin = $this->resolveFoodTruckOrigin($foodTruck);
        $origin          = $foodTruckOrigin ?? $origin;

        $origin = $this->resolveStoreLocationOrigin($origin, $cart);

        // convert payload destinations to Place
        $origin      = Place::createFromMixed($origin);
        $destination = Place::createFromMixed($destination);

        // create payload for order
        $payloadDetails = [
            'company_uuid'   => session('company'),
            'pickup_uuid'    => $origin instanceof Place ? $origin->uuid : null,
            'dropoff_uuid'   => $destination instanceof Place ? $destination->uuid : null,
            'return_uuid'    => $origin instanceof Place ? $origin->uuid : null,
            'payment_method' => $gateway->type,
            'type'           => 'storefront',
        ];

        // if cash on delivery set cod attributes
        if ($checkout->is_cod) {
            $payloadDetails['cod_amount']   = $amount;
            $payloadDetails['cod_currency'] = $checkout->currency;
            // @todo could be card if card swipe on delivery
            $payloadDetails['cod_payment_method'] = 'cash';
        }

        // create payload
        $payload = Payload::create($payloadDetails);

        // create entities
        foreach ($cart->items as $cartItem) {
            $this->processCartItem($cartItem, $payload, $customer);
        }

        // create order meta
        $orderMeta = [
            'storefront'    => $store->name,
            'storefront_id' => $store->public_id,
        ];

        // if network add network to order meta
        if ($isNetworkOrder) {
            $orderMeta['storefront_network']    = $about->name;
            $orderMeta['storefront_network_id'] = $about->public_id;
        }

        $orderMeta = array_merge($orderMeta, [
            'checkout_id'  => $checkout->public_id,
            'subtotal'     => Utils::numbersOnly($cart->subtotal),
            'delivery_fee' => $checkout->is_pickup ? 0 : Utils::numbersOnly($serviceQuote->amount),
            'tip'          => $checkout->getOption('tip'),
            'delivery_tip' => $checkout->getOption('delivery_tip'),
            'discount'     => $promotions->discount(),
            'promotions'   => $promotions->toPublicArray()['applied'],
            'total'        => Utils::numbersOnly($amount),
            'currency'     => $currency,
            'gateway'      => $gateway->type,
            'require_pod'  => $about->getOption('require_pod'),
            'pod_method'   => $about->pod_method,
            'is_pickup'    => $checkout->is_pickup,
            ...$transactionDetails,
        ]);

        // Create order input here
        $orderInput = [];

        // if there is a food truck include it in the order meta
        [$orderMeta, $orderInput] = $this->applyFoodTruckOrderData($foodTruck, $orderMeta, $orderInput);

        // initialize order creation input
        $orderInput = [
            ...$orderInput,
            'company_uuid'      => $store->company_uuid ?? session('company'),
            'payload_uuid'      => $payload->uuid,
            'customer_uuid'     => $customer->uuid,
            'customer_type'     => Utils::getMutationType('fleet-ops:contact'),
            'transaction_uuid'  => $transaction->uuid,
            'order_config_uuid' => $about->getOrderConfigId(),
            'adhoc'             => $about->isOption('auto_dispatch'),
            'type'              => 'storefront',
            'status'            => 'created',
            'meta'              => $orderMeta,
            'notes'             => $notes,
        ];

        // A booked service makes this a booking order, with its own flow.
        [$bookingMeta, $orderInput] = static::applyBooking($cart->items ?? [], $orderInput['meta'], $orderInput);
        $orderInput['meta']         = $bookingMeta;

        // if it's integrated vendor order apply to meta
        if ($integratedVendorOrder) {
            $orderMeta['integrated_vendor']       = $serviceQuote->integratedVendor->public_id;
            $orderMeta['integrated_vendor_order'] = $integratedVendorOrder;
            // order input
            $orderInput['facilitator_uuid'] = $serviceQuote->integratedVendor->uuid;
            $orderInput['facilitator_type'] = Utils::getModelClassName('integrated_vendors');
        }

        // create order
        $order = Order::create($orderInput);

        // record the promotions as used by this order
        PromotionRedemptions::redeem($checkout, $order);

        // notify driver if assigned
        $order->notifyDriverAssigned();

        // purchase service quote
        if ($serviceQuote) {
            $order->purchaseQuote($serviceQuote->uuid, $transactionDetails);
        }

        // if order is auto accepted update status
        if ($store->isOption('auto_accept_orders')) {
            $this->autoAcceptOrder($order);
            if ($store->isOption('auto_dispatch')) {
                $this->autoDispatchOrder($order);
            }
        }

        // notify order creation
        Storefront::alertNewOrder($order);

        // update the cart with the checkout
        $checkout->checkedout();

        // update checkout token
        $checkout->update([
            'order_uuid' => $order->uuid,
            // 'store_uuid' => $about->uuid,
            'captured' => true,
        ]);

        return new OrderResource($order);
    }

    protected function verifyStripePaymentForCheckout(Checkout $checkout, Gateway $gateway, ?Contact $customer, int $amount, ?string $currency): array|JsonResponse
    {
        if (!$checkout->stripe_payment_intent_id) {
            return response()->apiError('Stripe PaymentIntent is not linked to this checkout.', 422);
        }

        if (!static::hasStripeSecret($gateway)) {
            return response()->apiError('Gateway not configured correctly!');
        }

        \Stripe\Stripe::setApiKey($gateway->config->secret_key);

        try {
            $paymentIntent = \Stripe\PaymentIntent::retrieve($checkout->stripe_payment_intent_id);
        } catch (StripeAuthenticationException $e) {
            return static::stripeAuthenticationError($gateway, 'verify_checkout_payment_intent');
        } catch (\Exception $e) {
            Log::warning('[Storefront] Unable to verify Stripe checkout payment.', [
                'checkout_uuid' => $checkout->uuid,
                'gateway_uuid'  => $gateway->uuid,
                'exception'     => get_class($e),
            ]);

            return response()->apiError('Unable to verify Stripe payment.', 502);
        }

        if ($paymentIntent->status !== 'succeeded') {
            return response()->apiError('Stripe payment has not been completed.', 402);
        }

        if (!is_string($currency) || trim($currency) === '') {
            return response()->apiError('Stripe payment does not match this checkout.', 422);
        }

        $stripeCustomerId = is_object($paymentIntent->customer) ? $paymentIntent->customer->id : $paymentIntent->customer;
        $expectedCustomer = $customer?->getMeta('stripe_id');
        $expectedAmount   = Utils::formatAmountForStripe($amount, $currency);
        $expectedLiveMode = !$gateway->sandbox;

        if (
            $paymentIntent->id !== $checkout->stripe_payment_intent_id
            || (int) $paymentIntent->amount !== $expectedAmount
            || (int) $paymentIntent->amount_received !== $expectedAmount
            || strtolower((string) $paymentIntent->currency) !== strtolower((string) $currency)
            || !$expectedCustomer
            || $stripeCustomerId !== $expectedCustomer
            || (bool) $paymentIntent->livemode !== $expectedLiveMode
        ) {
            Log::warning('[Storefront] Stripe payment did not match checkout.', [
                'checkout_uuid'     => $checkout->uuid,
                'gateway_uuid'      => $gateway->uuid,
                'payment_intent_id' => $paymentIntent->id,
            ]);

            return response()->apiError('Stripe payment does not match this checkout.', 422);
        }

        return [
            'id'                => $paymentIntent->id,
            'transaction_id'    => $paymentIntent->id,
            'payment_intent_id' => $paymentIntent->id,
            'payment_status'    => $paymentIntent->status,
        ];
    }

    protected function captureOrderWithLock(Checkout $checkout, CaptureOrderRequest $request)
    {
        $lock = Cache::lock('create-order-checkout-' . $checkout->uuid, 120);

        if (!$lock->get()) {
            // Another request owns the capture. Return its authoritative result when it
            // has already completed; otherwise tell the caller to retry safely.
            $checkout->refresh();
            if ($checkout->order_uuid && $checkout->order) {
                return new OrderResource($checkout->order);
            }

            return response()->apiError('Order capture is already in progress.', 409);
        }

        try {
            $checkout->refresh();
            if ($checkout->order_uuid && $checkout->order) {
                return new OrderResource($checkout->order);
            }

            $request->attributes->set('storefront_checkout_lock_held', true);

            return $this->captureOrder($request);
        } finally {
            $request->attributes->remove('storefront_checkout_lock_held');
            $lock->release();
        }
    }

    public function captureMultipleOrders(CaptureOrderRequest $request)
    {
        $token              = $request->input('token');
        $transactionDetails = $request->input('transactionDetails', []); // optional details to be supplied about transaction
        $notes              = $request->input('notes');

        // validate transaction details
        if (!is_array($transactionDetails)) {
            $transactionDetails = [];
        }

        // get checkout data to create order
        $checkout = Checkout::where('token', $token)->with(['gateway', 'owner', 'serviceQuote', 'cart'])->first();
        if (!$checkout) {
            return response()->apiError('Checkout session not found.');
        }

        $about        = Storefront::about();
        $customer     = $checkout->owner;
        $serviceQuote = $checkout->serviceQuote;
        $gateway      = $checkout->is_cod ? Gateway::cash() : $checkout->gateway;
        $origins      = $serviceQuote->getMeta('origin');
        // set origin
        $origin      = Arr::first($origins);
        $waypoints   = array_slice($origins, 1);
        $destination = $serviceQuote->getMeta('destination');
        // The cart as it was priced and charged (see Checkout::cartAtCheckout()).
        $cart        = $checkout->cartAtCheckout() ?? $checkout->cart;
        // $amount = $checkout->amount ?? ($checkout->is_pickup ? $cart->subtotal : $cart->subtotal + $serviceQuote->amount);
        $amount   = static::calculateCheckoutAmount($cart, $serviceQuote, $checkout->options);
        $currency = $checkout->currency ?? $cart->getCurrency();

        // If the checkout already has an order created
        if ($checkout->order_uuid) {
            $completedOrder = Order::where('uuid', $checkout->order_uuid)->first();
            if ($completedOrder) {
                return new OrderResource($completedOrder);
            }
        }

        if (!$about) {
            return response()->apiError('No network in request to capture order!');
        }

        // prepare for integrated vendor order if applicable
        $integratedVendorOrder = null;

        // if service quote is applied, resolve it
        if ($serviceQuote instanceof ServiceQuote && $serviceQuote->fromIntegratedVendor()) {
            $vendorResult = $this->createIntegratedVendorOrderSafely($serviceQuote, $request);
            if ($vendorResult['error']) {
                return $vendorResult['error'];
            }
            $integratedVendorOrder = $vendorResult['order'];
        }

        // Find each pickup's store before anything is created: an order that cannot be built
        // fails here, without a payment record or half the orders left behind.
        $originPlaces = collect($origins)->map(fn ($publicId) => Place::createFromMixed($publicId))->values();
        $originStores = $originPlaces->map(fn ($pickup) => $pickup instanceof Place ? Storefront::getStoreFromLocation($pickup->uuid) : null)->values();
        if ($originPlaces->isEmpty() || $originStores->contains(fn ($store) => !$store)) {
            return response()->apiError('A store in this order could not be found, so it was not placed. Your payment has not been used.');
        }

        // setup transaction meta
        $transactionMeta = [
            'storefront_network'    => $about->name,
            'storefront_network_id' => $about->public_id,
            ...$transactionDetails,
        ];

        $transaction    = null;
        $multipleOrders = [];

        try {
            // create transactions for cart
            $transaction = Transaction::create([
                'company_uuid'           => session('company'),
                'customer_uuid'          => $customer->uuid,
                'customer_type'          => Utils::getMutationType('fleet-ops:contact'),
                'gateway_transaction_id' => Utils::or($transactionDetails, ['id', 'transaction_id']) ?? Transaction::generateNumber(),
                'gateway'                => $gateway->code,
                'gateway_uuid'           => $gateway->uuid,
                'amount'                 => $amount,
                'currency'               => $currency,
                'description'            => 'Storefront network order',
                'type'                   => 'storefront',
                'status'                 => Transaction::STATUS_SUCCESS,
                'settlement_status'      => Transaction::SETTLEMENT_STATUS_PAID,
                'settled_at'             => now(),
                'settled_amount'         => $amount,
                'settled_currency'       => $currency,
                'meta'                   => $transactionMeta,
            ]);

            // create transaction items
            foreach ($cart->items as $cartItem) {
                $store = Storefront::findAbout($cartItem->store_id);

                TransactionItem::create([
                    'transaction_uuid' => $transaction->uuid,
                    'amount'           => $cartItem->subtotal,
                    'currency'         => $checkout->currency,
                    'details'          => Storefront::getFullDescriptionFromCartItem($cartItem),
                    'code'             => 'product',
                    'meta'             => [
                        'storefront_network'    => $about->name,
                        'storefront_network_id' => $about->public_id,
                        'storefront'            => $store->name ?? null,
                        'storefront_id'         => $store->public_id ?? null,
                    ],
                ]);
            }

            // create transaction item for service quote
            if (!$checkout->is_pickup) {
                TransactionItem::create([
                    'transaction_uuid' => $transaction->uuid,
                    'amount'           => $serviceQuote->amount,
                    'currency'         => $serviceQuote->currency,
                    'details'          => 'Delivery fee',
                    'code'             => 'delivery_fee',
                ]);
            }

            // if tip create transaction item for tip
            if ($checkout->hasOption('tip')) {
                TransactionItem::create([
                    'transaction_uuid' => $transaction->uuid,
                    'amount'           => static::calculateTipAmount($checkout->getOption('tip'), $cart->subtotal),
                    'currency'         => $checkout->currency,
                    'details'          => 'Tip',
                    'code'             => 'tip',
                ]);
            }

            // if delivery tip create transaction item for tip
            if ($checkout->hasOption('delivery_tip')) {
                TransactionItem::create([
                    'transaction_uuid' => $transaction->uuid,
                    'amount'           => static::calculateTipAmount($checkout->getOption('delivery_tip'), $cart->subtotal),
                    'currency'         => $checkout->currency,
                    'details'          => 'Delivery Tip',
                    'code'             => 'delivery_tip',
                ]);
            }

            // if promotions were applied create a (credit) transaction item for the discount
            $promotions          = PromotionResult::fromArray(data_get($checkout->options, 'promotions'));
            $discountAllocations = $promotions->allocationsByStore();
            static::createDiscountTransactionItem($transaction, $promotions, $checkout->currency);

            // payload pickups (resolved above) and the destination as places
            $origins     = $originPlaces;
            $destination = Place::createFromMixed($destination);

            // The store tip goes to the network that runs the app, unless the network splits it
            // across the stores, each by its share of the order.
            $splitTips = $checkout->hasOption('tip') && $about->isOption('split_tips_across_stores');
            $tipShares = [];
            if ($splitTips) {
                $storeSubtotals = $originStores
                    ->unique('public_id')
                    ->mapWithKeys(fn ($store) => [$store->public_id => (int) $cart->getSubtotalForStore($store)])
                    ->all();
                $tipShares = static::splitByShare((int) static::calculateTipAmount($checkout->getOption('tip'), $cart->subtotal), $storeSubtotals);
            }

            $multipleOrders = [];

            foreach ($origins as $index => $pickup) {
                $store = $originStores[$index];

                // create payload
                $payload = Payload::create([
                    'company_uuid'   => $store->company_uuid,
                    'pickup_uuid'    => $pickup instanceof Place ? $pickup->uuid : null,
                    'dropoff_uuid'   => $destination instanceof Place ? $destination->uuid : null,
                    'return_uuid'    => $pickup instanceof Place ? $pickup->uuid : null,
                    'payment_method' => $gateway->type,
                    'type'           => 'storefront',
                ]);

                // get cart items from this store
                $cartItems = $cart->getItemsForStore($store);

                // create entities
                foreach ($cartItems as $cartItem) {
                    $this->processCartItem($cartItem, $payload, $customer);
                }

                // get order subtotal and this store's share of the item discount
                $subtotal      = $cart->getSubtotalForStore($store);
                $storeDiscount = min((int) ($discountAllocations[$store->public_id] ?? 0), (int) $subtotal);

                // prepare order meta
                $orderMeta = [
                    'is_master_order'       => false,
                    'storefront'            => $store->name,
                    'storefront_id'         => $store->public_id,
                    'storefront_network'    => $about->name,
                    'storefront_network_id' => $about->public_id,
                    'checkout_id'           => $checkout->public_id,
                    'subtotal'              => $subtotal,
                    'delivery_fee'          => 0,
                    'tip'                   => $tipShares[$store->public_id] ?? 0,
                    'tip_recipient'         => $splitTips ? 'store' : 'network',
                    'delivery_tip'          => 0,
                    'discount'              => $storeDiscount,
                    'total'                 => $subtotal - $storeDiscount,
                    'currency'              => $currency,
                    'gateway'               => $gateway->type,
                    'require_pod'           => $about->getOption('require_pod'),
                    'pod_method'            => $about->pod_method,
                    'is_pickup'             => $checkout->is_pickup,
                    ...$transactionDetails,
                ];

                // prepare order input
                $orderInput = [
                    'company_uuid'      => $store->company_uuid,
                    'payload_uuid'      => $payload->uuid,
                    'customer_uuid'     => $customer->uuid,
                    'customer_type'     => Utils::getMutationType('fleet-ops:contact'),
                    'transaction_uuid'  => $transaction->uuid,
                    'order_config_uuid' => $store->getOrderConfigId(),
                    'adhoc'             => $about->isOption('auto_dispatch'),
                    'type'              => 'storefront',
                    'status'            => 'created',
                    'notes'             => $notes,
                ];

                // if it's integrated vendor order apply to meta
                if ($integratedVendorOrder) {
                    $orderMeta['integrated_vendor']       = $serviceQuote->integratedVendor->public_id;
                    $orderMeta['integrated_vendor_order'] = $integratedVendorOrder;
                    // order input
                    $orderInput['facilitator_uuid'] = $serviceQuote->integratedVendor->uuid;
                    $orderInput['facilitator_type'] = Utils::getModelClassName('integrated_vendors');
                }

                // A booked service makes this store's order a booking order, with its own flow.
                [$orderMeta, $orderInput] = static::applyBooking($cartItems, $orderMeta, $orderInput);

                // set meta to order input last
                $orderInput['meta'] = $orderMeta;

                // create order
                $multipleOrders[] = $order = Order::create($orderInput);

                // set driving distance and time
                $order->setPreliminaryDistanceAndTime();

                // purchase service quote
                $order->purchaseQuote($serviceQuote->uuid, $transactionDetails);

                // if order is auto accepted update status
                if ($store->isOption('auto_accept_orders')) {
                    $this->autoAcceptOrder($order);
                    if ($store->isOption('auto_dispatch')) {
                        $this->autoDispatchOrder($order);
                    }
                }

                // notify order creation
                Storefront::alertNewOrder($order);
            }

            // convert origin to Place
            $origin = Place::createFromMixed($origin);

            // create master payload
            $payload = Payload::create([
                'company_uuid'   => session('company'),
                'pickup_uuid'    => $origin instanceof Place ? $origin->uuid : null,
                'dropoff_uuid'   => $destination instanceof Place ? $destination->uuid : null,
                'return_uuid'    => $origin instanceof Place ? $origin->uuid : null,
                'payment_method' => $gateway->type,
                'type'           => 'storefront',
            ])->setWaypoints($waypoints);

            // create entities
            foreach ($cart->items as $cartItem) {
                $this->processCartItem($cartItem, $payload, $customer);
            }

            // prepare master order meta
            $masterOrderMeta = [
                'is_master_order'       => true,
                'related_orders'        => collect($multipleOrders)->pluck('public_id')->toArray(),
                // the stores this order brings together, for lists that show it as one order
                'store_names'           => $originStores->pluck('name')->filter()->unique()->values()->all(),
                'storefront'            => $about->name,
                'storefront_id'         => $about->public_id,
                'storefront_network'    => $about->name,
                'storefront_network_id' => $about->public_id,
                'checkout_id'           => $checkout->public_id,
                'subtotal'              => Utils::numbersOnly($cart->subtotal),
                'delivery_fee'          => $checkout->is_pickup ? 0 : Utils::numbersOnly($serviceQuote->amount),
                'tip'                   => $checkout->getOption('tip'),
                'tip_recipient'         => $splitTips ? 'stores' : 'network',
                'delivery_tip'          => $checkout->getOption('delivery_tip'),
                'discount'              => $promotions->discount(),
                'promotions'            => $promotions->toPublicArray()['applied'],
                'total'                 => Utils::numbersOnly($amount),
                'currency'              => $currency,
                'gateway'               => $gateway->type,
                'require_pod'           => $about->getOption('require_pod'),
                'pod_method'            => $about->pod_method,
                'is_pickup'             => $checkout->is_pickup,
                ...$transactionDetails,
            ];

            // prepare master order input
            $masterOrderInput = [
                'company_uuid'      => session('company'),
                'payload_uuid'      => $payload->uuid,
                'customer_uuid'     => $customer->uuid,
                'customer_type'     => Utils::getMutationType('fleet-ops:contact'),
                'transaction_uuid'  => $transaction->uuid,
                'order_config_uuid' => $about->getOrderConfigId(),
                'adhoc'             => $about->isOption('auto_dispatch'),
                'type'              => 'storefront',
                'status'            => 'created',
            ];

            // if it's integrated vendor order apply to meta
            if ($integratedVendorOrder) {
                $masterOrderMeta['integrated_vendor']       = $serviceQuote->integratedVendor->public_id;
                $masterOrderMeta['integrated_vendor_order'] = $integratedVendorOrder;
                // order input
                $masterOrderInput['facilitator_uuid'] = $serviceQuote->integratedVendor->uuid;
                $masterOrderInput['facilitator_type'] = Utils::getModelClassName('integrated_vendors');
            }

            // finally apply meta to master order
            $masterOrderInput['meta'] = $masterOrderMeta;

            // create master order
            $order = Order::create($masterOrderInput);

            // record the promotions as used by this checkout's master order
            PromotionRedemptions::redeem($checkout, $order);

            // update child orders with master order id in meta
            foreach ($multipleOrders as $childOrder) {
                $childOrder->updateMeta('master_order_id', $order->public_id);
            }

            // notify driver if assigned
            $order->notifyDriverAssigned();

            // set driving distance and time
            $order->setPreliminaryDistanceAndTime();

            // purchase service quote
            $order->purchaseQuote($serviceQuote->uuid, $transactionDetails);

            // dispatch if flagged true
            $order->firstDispatch();

            // update the cart with the checkout
            $checkout->checkedout();

            // update checkout token
            $checkout->update([
                'order_uuid' => $order->uuid,
                // 'store_uuid' => $about->uuid,
                'captured' => true,
            ]);

            return new OrderResource($order);
        } catch (\Throwable $e) {
            // Undo this attempt's records so a retry ("Finish placing order") starts clean
            // instead of leaving another payment record with no order.
            static::discardFailedCapture($transaction, $multipleOrders);

            throw $e;
        }
    }

    /**
     * Remove what a failed capture attempt created: its transaction (with its line items) and
     * any of the store orders already made. Nothing else refers to them yet.
     */
    protected static function discardFailedCapture(?Transaction $transaction, array $orders = []): void
    {
        try {
            foreach ($orders as $order) {
                if ($order instanceof Order) {
                    $order->delete();
                }
            }
            if ($transaction) {
                TransactionItem::where('transaction_uuid', $transaction->uuid)->delete();
                $transaction->delete();
            }
        } catch (\Throwable $cleanup) {
            Log::error('[Storefront] Unable to discard a failed order capture.', ['transaction' => $transaction?->public_id, 'error' => $cleanup->getMessage()]);
        }
    }

    public function afterCheckout(Request $request)
    {
    }

    /**
     * Get checkout status including payment and order details.
     *
     * This endpoint allows the app to query the current status of a checkout session,
     * including payment confirmation and order details. If payment is confirmed but
     * order doesn't exist (callback failed), it will create the order as a fallback.
     *
     * @return JsonResponse
     */
    public function getCheckoutStatus(Request $request)
    {
        $checkoutId = $request->input('checkout');
        $token      = $request->input('token');

        // Validate required parameters
        if (!$checkoutId || !$token) {
            return response()->json([
                'error' => 'Missing required parameters: checkout and token',
            ], 400);
        }

        try {
            // Find checkout by ID and token
            $checkout = Checkout::where('public_id', $checkoutId)
                ->where('token', $token)
                ->with(['order'])
                ->first();

            if (!$checkout) {
                return response()->json([
                    'error' => 'Checkout not found',
                ], 404);
            }

            // Initialize response (gateway-agnostic)
            $response = [
                'status'   => $checkout->captured ? 'completed' : 'pending',
                'checkout' => $checkout->public_id,
                'payment'  => null,
                'order'    => $checkout->order ? new OrderResource($checkout->order) : null,
            ];

            // Check if this is a QPay checkout. Once the order exists it is the answer:
            // QPay asks merchants not to check payments over and over (payment_check), so
            // it is only asked while the order is still missing.
            if ($checkout->gateway_uuid && !$response['order']) {
                $gateway = Gateway::where('uuid', $checkout->gateway_uuid)->first();

                if ($gateway && $gateway->code === 'qpay') {
                    // Get QPay invoice ID from checkout options
                    $qpayInvoiceId = $checkout->getOption('qpay_invoice_id');
                    $payment       = null;

                    if ($qpayInvoiceId) {
                        // Create QPay instance with correct credentials
                        $qpay = static::qpayForGateway($gateway);

                        if ($gateway->sandbox) {
                            $qpay->useSandbox();
                        }

                        $qpay->setAuthToken();

                        // Verify payment status with QPay
                        $paymentCheck = $qpay->paymentCheck($qpayInvoiceId);
                        // Only a PAID payment covering the invoice amount (see paidQPayPayment)
                        $payment      = static::paidQPayPayment($paymentCheck, $checkout);
                    }

                    if ($payment) {
                        $response['status']  = 'paid';
                        $response['payment'] = [
                            'payment_id'     => $payment->payment_id,
                            'payment_status' => $payment->payment_status,
                            'payment_amount' => $payment->payment_amount,
                            'payment_date'   => $payment->payment_date ?? null,
                            'payment_wallet' => $payment->payment_wallet ?? 'QPay',
                        ];

                        // FALLBACK: If payment confirmed but order doesn't exist, create it
                        if (!$checkout->order_uuid) {
                            Log::info('[CHECKOUT STATUS FALLBACK]: Payment confirmed but no order exists, attempting to create', [
                                'checkout_id' => $checkout->public_id,
                                'payment_id'  => $payment->payment_id,
                            ]);

                            $transactionDetails = [
                                'transaction_id' => $payment->payment_id,
                                'payment_status' => 'PAID',
                                'payment_wallet' => $payment->payment_wallet ?? 'QPay',
                            ];

                            try {
                                // Use the reusable gateway-agnostic method to create order
                                // createOrderFromCheckout has built-in idempotency checks
                                $order = $this->createOrderFromCheckout($checkout, $transactionDetails);

                                if ($order) {
                                    $response['status'] = 'completed';
                                    $response['order']  = new OrderResource($order);
                                }
                            } catch (\Exception $e) {
                                // If order creation fails (e.g., race condition), refresh and check again
                                Log::warning('[CHECKOUT STATUS FALLBACK]: Order creation failed, checking if order was created by another request', [
                                    'checkout_id' => $checkout->public_id,
                                    'error'       => $e->getMessage(),
                                ]);

                                $checkout->refresh();
                                if ($checkout->order_uuid) {
                                    // Order was created by another request
                                    $response['status'] = 'completed';
                                    $response['order']  = new OrderResource($checkout->order);
                                }
                            }
                        } else {
                            // Order already exists
                            $response['status'] = 'completed';
                            $response['order']  = new OrderResource($checkout->order);
                        }
                    }
                }
            }

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('[CHECKOUT STATUS ERROR]: ' . $e->getMessage(), [
                'checkout_id' => $checkoutId,
                'exception'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error'   => 'Failed to retrieve checkout status',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Calculates the total checkout amount.
     *
     * @param stdClass $checkoutOptions
     */
    private static function calculateCheckoutAmount(Cart $cart, ?ServiceQuote $serviceQuote, $checkoutOptions): int
    {
        // cast checkout options to object always
        $checkoutOptions = (object) $checkoutOptions;
        $subtotal        = (int) $cart->subtotal;
        $total           = $subtotal;
        $tip             = $checkoutOptions->tip ?? false;
        $deliveryTip     = $checkoutOptions->delivery_tip ?? false;
        $isPickup        = $checkoutOptions->is_pickup ?? false;

        if ($tip) {
            $tipAmount = static::calculateTipAmount($tip, $subtotal);

            $total += $tipAmount;
        }

        if ($deliveryTip && !$isPickup) {
            $deliveryTipAmount = static::calculateTipAmount($deliveryTip, $subtotal);

            $total += $deliveryTipAmount;
        }

        if (!$isPickup) {
            $total += Utils::numbersOnly($serviceQuote->amount);
        }

        // Promotions priced when the checkout was created. Tips are computed on the
        // undiscounted subtotal, and discounts never exceed what they discount.
        $promotions = data_get($checkoutOptions, 'promotions');
        if ($promotions) {
            $total -= min((int) data_get($promotions, 'discount_subtotal', 0), $subtotal);
            if (!$isPickup) {
                $total -= min((int) data_get($promotions, 'discount_delivery', 0), (int) Utils::numbersOnly($serviceQuote->amount));
            }
        }

        return max($total, 0);
    }

    /**
     * Record the promotions' discount as a transaction item.
     *
     * The amount is stored positive (the Money cast drops signs) and flagged as a credit with the
     * `discount` code: the transaction amount is the items minus this line.
     */
    protected static function createDiscountTransactionItem(Transaction $transaction, PromotionResult $promotions, ?string $currency): void
    {
        if ($promotions->discount() <= 0) {
            return;
        }

        TransactionItem::create([
            'transaction_uuid' => $transaction->uuid,
            'amount'           => $promotions->discount(),
            'currency'         => $currency,
            'details'          => 'Discount: ' . implode(', ', array_filter(array_column($promotions->applied, 'name'))),
            'code'             => 'discount',
            'meta'             => ['direction' => 'credit', 'promotions' => $promotions->toPublicArray()['applied']],
        ]);
    }

    /**
     * Price the cart's promotions and store them on the checkout options.
     *
     * Codes come from the request (`promo_codes`, `promo_code` or `discount_code`) and from codes
     * applied to the cart. A code that cannot be applied fails the checkout, so the customer is
     * never charged without a discount they expected. Codes that only lost to a better
     * combination of promotions do not.
     */
    protected static function applyPromotions(Cart $cart, ?ServiceQuote $serviceQuote, $checkoutOptions, ?Contact $customer, Request $request): ?JsonResponse
    {
        $isPickup    = (bool) data_get($checkoutOptions, 'is_pickup', false);
        $deliveryFee = $serviceQuote && !$isPickup ? (int) Utils::numbersOnly($serviceQuote->amount) : 0;
        $context     = PromotionContext::fromCart($cart, Storefront::about(), $customer, $isPickup, $deliveryFee);
        $result      = app(PromotionEngine::class)->evaluate($context, static::promotionCodesFor($cart, $request));

        $blocking = array_values(array_filter($result->rejected, fn ($rejection) => $rejection['reason'] !== PromotionEngine::REASON_NOT_COMBINABLE));
        if ($blocking) {
            return response()->apiError(
                'Promotion code "' . $blocking[0]['code'] . '" cannot be applied (' . $blocking[0]['reason'] . ').',
                400,
                ['promotions' => ['rejected' => $blocking]]
            );
        }

        if (!$result->isEmpty()) {
            $checkoutOptions->promotions = $result->toArray();
        }

        return null;
    }

    protected static function promotionCodesFor(Cart $cart, Request $request): array
    {
        $codes = $request->input('promo_codes', $request->or(['promo_code', 'promoCode', 'discount_code']));
        if (is_string($codes)) {
            $codes = explode(',', $codes);
        }

        return array_values(array_unique(array_filter(array_merge((array) $codes, $cart->getPromotionCodes()), 'is_string')));
    }

    /**
     * The JSON response for an initialized checkout.
     *
     * When realtime socket authentication is enabled it carries `socket_token`: a
     * `checkout` token whose scope is exactly this checkout's channel, so a client
     * (a guest included) can listen for its own payment confirmation. The field is
     * absent while socket authentication is disabled.
     */
    protected static function checkoutResponse(Checkout $checkout, array $data): JsonResponse
    {
        $socketToken = StorefrontSocket::checkoutToken($checkout);
        if ($socketToken) {
            $data['socket_token'] = $socketToken;
        }

        return response()->json($data);
    }

    /**
     * Publishes a checkout's progress on its realtime channel.
     *
     * The payload is what a storefront client acts on — the checkout, its status, the
     * order once one exists (serialized exactly as GET checkouts/status returns it) and
     * any error — never the raw gateway payment record. A publish failure is logged and
     * swallowed: by now the payment is recorded, and clients still recover the outcome
     * through GET checkouts/status.
     *
     * @return array|null the published payload, or null when publishing failed
     */
    protected static function publishCheckoutUpdate(Checkout $checkout, bool $paid, ?array $error = null): ?array
    {
        try {
            // A failed payment carries no order, so a client never completes on an error event.
            $order  = !$error && $checkout->order_uuid ? Order::where('uuid', $checkout->order_uuid)->first() : null;
            $status = 'pending';
            if ($error) {
                $status = 'failed';
            } elseif ($order) {
                $status = 'completed';
            } elseif ($paid) {
                $status = 'paid';
            }

            $data = [
                'checkout' => $checkout->public_id,
                'status'   => $status,
                'order'    => $order ? static::checkoutChannelOrder($order) : null,
                'error'    => $error,
            ];

            SocketClusterService::publish(StorefrontSocket::checkoutChannel($checkout), $data);

            return $data;
        } catch (\Throwable $e) {
            Log::warning('[CHECKOUT SOCKET PUBLISH FAILED]: ' . $e->getMessage(), ['checkout' => $checkout->public_id]);

            return null;
        }
    }

    /**
     * Serializes a checkout's order for its realtime channel, as GET checkouts/status does.
     */
    protected static function checkoutChannelOrder(Order $order): array
    {
        return json_decode(json_encode(new OrderResource($order)), true);
    }

    /**
     * Reserve a new checkout's promotions, discarding the checkout if one ran out meanwhile.
     */
    protected static function reservePromotions(Checkout $checkout, $checkoutOptions, ?Contact $customer): ?JsonResponse
    {
        try {
            PromotionRedemptions::reserve($checkout, PromotionResult::fromArray(data_get($checkoutOptions, 'promotions')), $customer?->uuid);
        } catch (PromotionUnavailableException $e) {
            $checkout->delete();

            return response()->apiError($e->getMessage());
        }

        return null;
    }

    /**
     * Split an amount across stores in proportion to their subtotals. Shares are whole cents
     * that add up to the amount; the cents left over by rounding go to the largest shares.
     *
     * @param array<string,int> $subtotals store id => subtotal
     *
     * @return array<string,int> store id => share
     */
    protected static function splitByShare(int $amount, array $subtotals): array
    {
        $total = array_sum($subtotals);
        if ($amount <= 0 || $total <= 0) {
            return array_map(fn () => 0, $subtotals);
        }

        $shares = [];
        foreach ($subtotals as $storeId => $subtotal) {
            $shares[$storeId] = intdiv($amount * $subtotal, $total);
        }

        $left = $amount - array_sum($shares);
        arsort($subtotals);
        foreach (array_keys($subtotals) as $storeId) {
            if ($left <= 0) {
                break;
            }
            $shares[$storeId]++;
            $left--;
        }

        return $shares;
    }

    private static function calculateTipAmount($tip, $subtotal)
    {
        $tipAmount = 0;

        if (is_string($tip) && Str::endsWith($tip, '%')) {
            $percentage = (float) str_replace(',', '', Str::beforeLast($tip, '%'));
            $tipAmount  = Utils::calculatePercentage($percentage, $subtotal);
        } else {
            $tipAmount = Utils::numbersOnly($tip);
        }

        return $tipAmount;
    }
}
