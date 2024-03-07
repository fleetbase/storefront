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
use Fleetbase\Storefront\Http\Requests\InitializeCheckoutRequest;
use Fleetbase\Storefront\Models\Cart;
use Fleetbase\Storefront\Models\Checkout;
use Fleetbase\Storefront\Models\Customer;
use Fleetbase\Storefront\Models\Gateway;
use Fleetbase\Storefront\Models\Product;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Models\StoreLocation;
use Fleetbase\Storefront\Notifications\StorefrontOrderPreparing;
use Fleetbase\Storefront\Support\QPay;
use Fleetbase\Storefront\Support\Storefront;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Stripe\Exception\InvalidRequestException;

class CheckoutController extends Controller
{
    public function beforeCheckout(InitializeCheckoutRequest $request)
    {
        $gatewayCode      = $request->input('gateway');
        $customerId       = $request->input('customer');
        $cartId           = $request->input('cart');
        $serviceQuoteId   = $request->or(['serviceQuote', 'service_quote']);
        $isCashOnDelivery = $request->input('cash') || $gatewayCode === 'cash';
        $isPickup         = $request->input('pickup', false);
        $tip              = $request->input('tip', false);
        $deliveryTip      = $request->or(['delivery_tip', 'deliveryTip'], false);

        // create checkout options
        $checkoutOptions = Utils::createObject([
            'is_pickup'    => $isPickup,
            'is_cod'       => $isCashOnDelivery,
            'tip'          => $tip,
            'delivery_tip' => $deliveryTip,
        ]);

        // find and validate cart session
        $cart         = Cart::retrieve($cartId);
        $gateway      = Storefront::findGateway($gatewayCode);
        $customer     = Customer::findFromCustomerId($customerId);
        $serviceQuote = ServiceQuote::select(['amount', 'meta', 'uuid', 'public_id'])->where('public_id', $serviceQuoteId)->first();

        // handle cash orders
        if ($isCashOnDelivery) {
            return static::initializeCashCheckout($customer, $gateway, $serviceQuote, $cart, $checkoutOptions);
        }

        if (!$gateway) {
            return response()->error('No gateway configured!');
        }

        // handle checkout initialization based on gateway
        if ($gateway->isStripeGateway) {
            return static::initializeStripeCheckout($customer, $gateway, $serviceQuote, $cart, $checkoutOptions);
        }

        // handle checkout initialization based on gateway
        if ($gateway->isQpayGateway) {
            return static::initializeQpayCheckout($customer, $gateway, $serviceQuote, $cart, $checkoutOptions);
        }

        return response()->error('Unable to initialize checkout!');
    }

    public static function initializeCashCheckout(Contact $customer, Gateway $gateway, ServiceQuote $serviceQuote, Cart $cart, $checkoutOptions)
    {
        // check if pickup order
        $isPickup = $checkoutOptions->is_pickup;

        // get amount/subtotal
        $amount   = static::calculateCheckoutAmount($cart, $serviceQuote, $checkoutOptions);
        $currency = $cart->currency ?? session('storefront_currency');

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
            'service_quote_uuid' => $serviceQuote->uuid,
            'owner_uuid'         => $customer->uuid,
            'owner_type'         => 'fleet-ops:contact',
            'amount'             => $amount,
            'currency'           => $currency,
            'is_cod'             => true,
            'is_pickup'          => $isPickup,
            'options'            => $checkoutOptions,
            'cart_state'         => $cart->toArray(),
        ]);

        return response()->json([
            'token' => $checkout->token,
        ]);
    }

    public static function initializeStripeCheckout(Contact $customer, Gateway $gateway, ServiceQuote $serviceQuote, Cart $cart, $checkoutOptions)
    {
        // check if pickup order
        $isPickup = $checkoutOptions->is_pickup;

        // get amount/subtotal
        $amount   = static::calculateCheckoutAmount($cart, $serviceQuote, $checkoutOptions);
        $currency = $cart->currency ?? session('storefront_currency');

        // check for secret key first
        if (!isset($gateway->config->secret_key)) {
            return response()->error('Gateway not configured correctly!');
        }

        // Set the stipre secret key from gateway
        \Stripe\Stripe::setApiKey($gateway->config->secret_key);

        // Check customer meta for stripe id
        if ($customer->missingMeta('stripe_id')) {
            Storefront::createStripeCustomerForContact($customer);
        }

        $ephemeralKey = null;

        try {
            $ephemeralKey = \Stripe\EphemeralKey::create(
                ['customer' => $customer->getMeta('stripe_id')],
                ['stripe_version' => '2020-08-27']
            );
        } catch (InvalidRequestException $e) {
            $errorMessage = $e->getMessage();

            if (Str::contains($errorMessage, 'No such customer')) {
                // create the customer for this network/store
                Storefront::createStripeCustomerForContact($customer);
                // regenerate key
                $ephemeralKey = \Stripe\EphemeralKey::create(
                    ['customer' => $customer->getMeta('stripe_id')],
                    ['stripe_version' => '2020-08-27']
                );
            } else {
                return response()->error('Error from Stripe: ' . $errorMessage);
            }
        }

        $paymentIntent = \Stripe\PaymentIntent::create([
            'amount'   => $amount,
            'currency' => $currency,
            'customer' => $customer->getMeta('stripe_id'),
        ]);

        info("Payment Intent", $paymentIntent->toArray());
        // create checkout token
        $checkout = Checkout::create([
            'company_uuid'       => session('company'),
            'store_uuid'         => session('storefront_store'),
            'network_uuid'       => session('storefront_network'),
            'cart_uuid'          => $cart->uuid,
            'gateway_uuid'       => $gateway->uuid,
            'service_quote_uuid' => $serviceQuote->uuid,
            'owner_uuid'         => $customer->uuid,
            'owner_type'         => 'fleet-ops:contact',
            'amount'             => $amount,
            'currency'           => $currency,
            'is_pickup'          => $isPickup,
            'options'            => $checkoutOptions,
            'cart_state'         => $cart->toArray(),
        ]);

        return response()->json([
            'paymentIntent' => $paymentIntent->id,
            'ephemeralKey'  => $ephemeralKey->secret,
            'customerId'    => $customer->getMeta('stripe_id'),
            'token'         => $checkout->token,
        ]);
    }

    public static function initializeQpayCheckout(Contact $customer, Gateway $gateway, ServiceQuote $serviceQuote, Cart $cart, $checkoutOptions)
    {
        // Get store info
        $about = Storefront::about();

        // check if pickup order
        $isPickup = $checkoutOptions->is_pickup;

        // get amount/subtotal
        $amount   = static::calculateCheckoutAmount($cart, $serviceQuote, $checkoutOptions);
        $currency = $cart->currency ?? session('storefront_currency');

        // check for secret key first
        if (!isset($gateway->config->username)) {
            return response()->error('Gateway not configured correctly!');
        }

        // Create qpay instance
        $qpay = QPay::instance($gateway->config->username, $gateway->config->password, $gateway->callback_url);

        if ($gateway->sandbox) {
            $qpay = $qpay->useSandbox();
        }

        // Set auth token
        $qpay = $qpay->setAuthToken();

        // Create invoice description
        $invoiceAmount       = round($amount / 100);
        $invoiceCode         = $gateway->sandbox ? 'TEST_INVOICE' : $gateway->config->invoice_id;
        $invoiceDescription  = $about->name . ' cart checkout';
        $invoiceReceiverCode = $gateway->public_id;
        $senderInvoiceNo     = $cart->id;

        // Create qpay invoice
        $invoice = $qpay->createSimpleInvoice($invoiceAmount, $invoiceCode, $invoiceDescription, $invoiceReceiverCode, $senderInvoiceNo);

        // Create checkout token
        $checkout = Checkout::create([
            'company_uuid'       => session('company'),
            'store_uuid'         => session('storefront_store'),
            'network_uuid'       => session('storefront_network'),
            'cart_uuid'          => $cart->uuid,
            'gateway_uuid'       => $gateway->uuid,
            'service_quote_uuid' => $serviceQuote->uuid,
            'owner_uuid'         => $customer->uuid,
            'owner_type'         => 'fleet-ops:contact',
            'amount'             => $amount,
            'currency'           => $currency,
            'is_pickup'          => $isPickup,
            'options'            => $checkoutOptions,
            'cart_state'         => $cart->toArray(),
        ]);

        return response()->json([
            'invoice' => $invoice,
            'token'   => $checkout->token,
        ]);
    }

    /**
     * Process a cart item and create/save an entity.
     *
     * @param mixed $cartItem The cart item to process.
     * @param mixed $payload  The payload.
     * @param mixed $customer The customer.
     * @return void
     */
    private function processCartItem($cartItem, $payload, $customer) {
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
        $token              = $request->input('token');
        $transactionDetails = $request->input('transactionDetails', []); // optional details to be supplied about transaction

        // validate transaction details
        if (!is_array($transactionDetails)) {
            $transactionDetails = [];
        }

        // get checkout data to create order
        $about        = Storefront::about();
        $checkout     = Checkout::where('token', $token)->with(['gateway', 'owner', 'serviceQuote', 'cart'])->first();
        $customer     = $checkout->owner;
        $serviceQuote = $checkout->serviceQuote;
        $gateway      = $checkout->is_cod ? Gateway::cash() : $checkout->gateway;
        $origin       = $serviceQuote->getMeta('origin', []);
        $destination  = $serviceQuote->getMeta('destination');
        $cart         = $checkout->cart;

        // if cart is null then cart has either been deleted or expired
        if (!$cart) {
            return response()->json([
                'error' => 'Cart expired',
            ], 400);
        }

        // $amount = $checkout->amount ?? ($checkout->is_pickup ? $cart->subtotal : $cart->subtotal + $serviceQuote->amount);
        $amount   = static::calculateCheckoutAmount($cart, $serviceQuote, $checkout->options);
        $currency = $checkout->currency ?? ($cart->currency ?? session('storefront_currency'));
        $store    = $about;

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
            return response()->error('No storefront in request to capture order!');
        }

        // prepare for integrated vendor order if applicable
        $integratedVendorOrder = null;

        // if service quote is applied, resolve it
        if ($serviceQuote instanceof ServiceQuote && $serviceQuote->fromIntegratedVendor()) {
            // create order with integrated vendor, then resume fleetbase order creation
            try {
                $integratedVendorOrder = $serviceQuote->integratedVendor->api()->createOrderFromServiceQuote($serviceQuote, $request);
            } catch (\Exception $e) {
                return response()->error($e->getMessage());
            }
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
            'status'                 => 'success',
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

        // if single cart checkout and origin is array get the first id
        if (is_array($origin)) {
            $origin = Arr::first($origin);
        }

        // if there is no origin attempt to get from cart
        if (!$origin) {
            $storeLocation = collect($cart->items)->map(function ($cartItem) {
                $storeLocationId = $cartItem->store_location_id;

                // if no store location id set, use first locations id
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

            if ($storeLocation) {
                $origin = $storeLocation->place_uuid;
            }
        }

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
            'delivery_fee' => $checkout->is_pickip ? 0 : Utils::numbersOnly($serviceQuote->amount),
            'tip'          => $checkout->getOption('tip'),
            'delivery_tip' => $checkout->getOption('delivery_tip'),
            'total'        => Utils::numbersOnly($amount),
            'currency'     => $currency,
            'require_pod'  => $about->getOption('require_pod'),
            'pod_method'   => $about->pod_method,
            'is_pickup'    => $checkout->is_pickup,
            ...$transactionDetails,
        ]);

        // initialize order creation input
        $orderInput = [
            'company_uuid'     => $store->company_uuid ?? session('company'),
            'payload_uuid'     => $payload->uuid,
            'customer_uuid'    => $customer->uuid,
            'customer_type'    => Utils::getMutationType('fleet-ops:contact'),
            'transaction_uuid' => $transaction->uuid,
            'adhoc'            => $about->isOption('auto_dispatch'),
            'type'             => 'storefront',
            'status'           => 'created',
            'meta'             => $orderMeta,
        ];

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

        info('Order created', $order->toArray());

        // notify order creation
        Storefront::alertNewOrder($order);

        // purchase service quote
        $order->purchaseQuote($serviceQuote->uuid, $transactionDetails);

        // if order is auto accepted update status
        if ($about->isOption('auto_accept_orders')) {
            if ($about->isOption('auto_dispatch')) {
                $order->updateStatus(['preparing', 'dispatched']);
            } else {
                $order->updateStatus('preparing');
            }

            // notify customer order is preparing
            $customer->notify(new StorefrontOrderPreparing($order));
        }

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

    public function captureMultipleOrders(CaptureOrderRequest $request)
    {
        $token              = $request->input('token');
        $transactionDetails = $request->input('transactionDetails', []); // optional details to be supplied about transaction

        // validate transaction details
        if (!is_array($transactionDetails)) {
            $transactionDetails = [];
        }

        // get checkout data to create order
        $about        = Storefront::about();
        $checkout     = Checkout::where('token', $token)->with(['gateway', 'owner', 'serviceQuote', 'cart'])->first();
        $customer     = $checkout->owner;
        $serviceQuote = $checkout->serviceQuote;
        $gateway      = $checkout->is_cod ? Gateway::cash() : $checkout->gateway;
        $origins      = $serviceQuote->getMeta('origin');
        // set origin
        $origin      = Arr::first($origins);
        $waypoints   = array_slice($origins, 1);
        $destination = $serviceQuote->getMeta('destination');
        $cart        = $checkout->cart;
        // $amount = $checkout->amount ?? ($checkout->is_pickup ? $cart->subtotal : $cart->subtotal + $serviceQuote->amount);
        $amount   = static::calculateCheckoutAmount($cart, $serviceQuote, $checkout->options);
        $currency = $checkout->currency ?? ($cart->currency ?? session('storefront_currency'));

        if (!$about) {
            return response()->error('No network in request to capture order!');
        }

        // prepare for integrated vendor order if applicable
        $integratedVendorOrder = null;

        // if service quote is applied, resolve it
        if ($serviceQuote instanceof ServiceQuote && $serviceQuote->fromIntegratedVendor()) {
            // create order with integrated vendor, then resume fleetbase order creation
            try {
                $integratedVendorOrder = $serviceQuote->integratedVendor->api()->createOrderFromServiceQuote($serviceQuote, $request);
            } catch (\Exception $e) {
                return response()->error($e->getMessage());
            }
        }

        // setup transaction meta
        $transactionMeta = [
            'storefront_network'    => $about->name,
            'storefront_network_id' => $about->public_id,
            ...$transactionDetails,
        ];

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
            'status'                 => 'success',
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

        // convert payload destinations to Place
        $origins = collect($origins)->map(function ($publicId) {
            return Place::createFromMixed($publicId);
        });
        $destination = Place::createFromMixed($destination);

        $multipleOrders = [];

        foreach ($origins as $pickup) {
            $store = Storefront::getStoreFromLocation($pickup);

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

            // get order subtotal
            $subtotal = $cart->getSubtotalForStore($store);

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
                'tip'                   => 0,
                'delivery_tip'          => 0,
                'total'                 => $subtotal,
                'currency'              => $currency,
                'require_pod'           => $about->getOption('require_pod'),
                'pod_method'            => $about->pod_method,
                'is_pickup'             => $checkout->is_pickup,
                ...$transactionDetails,
            ];

            // prepare order input
            $orderInput = [
                'company_uuid'     => $store->company_uuid,
                'payload_uuid'     => $payload->uuid,
                'customer_uuid'    => $customer->uuid,
                'customer_type'    => Utils::getMutationType('fleet-ops:contact'),
                'transaction_uuid' => $transaction->uuid,
                'adhoc'            => $about->isOption('auto_dispatch'),
                'type'             => 'storefront',
                'status'           => 'created',
            ];

            // if it's integrated vendor order apply to meta
            if ($integratedVendorOrder) {
                $orderMeta['integrated_vendor']       = $serviceQuote->integratedVendor->public_id;
                $orderMeta['integrated_vendor_order'] = $integratedVendorOrder;
                // order input
                $orderInput['facilitator_uuid'] = $serviceQuote->integratedVendor->uuid;
                $orderInput['facilitator_type'] = Utils::getModelClassName('integrated_vendors');
            }

            // set meta to order input last
            $orderInput['meta'] = $orderMeta;

            // create order
            $multipleOrders[] = $order = Order::create($orderInput);

            // set driving distance and time
            $order->setPreliminaryDistanceAndTime();

            // purchase service quote
            $order->purchaseQuote($serviceQuote->uuid, $transactionDetails);

            // notify order creation
            Storefront::alertNewOrder($order);

            // if order is auto accepted update status
            if ($store->isOption('auto_accept_orders')) {
                if ($store->isOption('auto_dispatch')) {
                    $order->updateStatus(['preparing', 'dispatched']);
                } else {
                    $order->updateStatus('preparing');
                }

                // notify customer order is preparing
                $customer->notify(new StorefrontOrderPreparing($order));
            }
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
            'storefront'            => $about->name,
            'storefront_id'         => $about->public_id,
            'storefront_network'    => $about->name,
            'storefront_network_id' => $about->public_id,
            'checkout_id'           => $checkout->public_id,
            'subtotal'              => Utils::numbersOnly($cart->subtotal),
            'delivery_fee'          => $checkout->is_pickip ? 0 : Utils::numbersOnly($serviceQuote->amount),
            'tip'                   => $checkout->getOption('tip'),
            'delivery_tip'          => $checkout->getOption('delivery_tip'),
            'total'                 => Utils::numbersOnly($amount),
            'currency'              => $currency,
            'require_pod'           => $about->getOption('require_pod'),
            'pod_method'            => $about->pod_method,
            'is_pickup'             => $checkout->is_pickup,
            ...$transactionDetails,
        ];

        // prepare master order input
        $masterOrderInput = [
            'company_uuid'     => session('company'),
            'payload_uuid'     => $payload->uuid,
            'customer_uuid'    => $customer->uuid,
            'customer_type'    => Utils::getMutationType('fleet-ops:contact'),
            'transaction_uuid' => $transaction->uuid,
            'adhoc'            => $about->isOption('auto_dispatch'),
            'type'             => 'storefront',
            'status'           => 'created',
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
    }

    public function afterCheckout(Request $request)
    {
    }

    /**
     * Calculates the total checkout amount.
     *
     * @param stdClass $checkoutOptions
     */
    private static function calculateCheckoutAmount(Cart $cart, ServiceQuote $serviceQuote, $checkoutOptions): int
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

        return $total;
    }

    private static function calculateTipAmount($tip, $subtotal)
    {
        $tipAmount = 0;

        if (is_string($tip) && Str::endsWith($tip, '%')) {
            $tipAmount = Utils::calculatePercentage(Utils::numbersOnly($tip), $subtotal);
        } else {
            $tipAmount = Utils::numbersOnly($tip);
        }

        return $tipAmount;
    }
}
