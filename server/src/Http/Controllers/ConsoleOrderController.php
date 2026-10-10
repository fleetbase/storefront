<?php

namespace Fleetbase\Storefront\Http\Controllers;

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Storefront\Http\Controllers\v1\CheckoutController;
use Fleetbase\Storefront\Http\Controllers\v1\ServiceQuoteController;
use Fleetbase\Storefront\Http\Requests\CaptureOrderRequest;
use Fleetbase\Storefront\Http\Requests\GetServiceQuoteFromCart;
use Fleetbase\Storefront\Models\Cart;
use Fleetbase\Storefront\Models\Checkout;
use Fleetbase\Storefront\Models\Gateway;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Models\StoreLocation;
use Fleetbase\Storefront\Promotions\PromotionContext;
use Fleetbase\Storefront\Promotions\PromotionEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Orders placed from the console. They run the same cart, quote, promotion and cash
 * checkout path the app does, so prices, fees, discounts and minimums come from the API
 * and are never retyped by the operator.
 */
class ConsoleOrderController extends Controller
{
    private const SESSION_KEYS = ['storefront_key', 'storefront_store', 'storefront_store_public_id', 'storefront_network', 'storefront_network_public_id', 'storefront_currency', 'customer_id'];

    /**
     * Price a console order: builds a cart from the lines, quotes delivery when it is not a
     * pickup, evaluates promotions, and returns the totals and the minimum-order check.
     *
     * @return \Illuminate\Http\Response
     */
    public function quote(Request $request)
    {
        $store = $this->resolveStore($request->input('store'));
        if (!$store) {
            return response()->error('Store not found.', 404);
        }

        $customer = $this->resolveCustomer($request->input('customer'));
        $isPickup = $request->boolean('is_pickup');

        $this->scopeSessionToStore($store, $customer);

        try {
            $cart = $this->buildCart($request, $store);
        } catch (\Throwable $e) {
            $this->releaseSession();

            return response()->error($e->getMessage(), 422);
        }

        $serviceQuote = null;
        $quoteError   = null;

        if (!$isPickup) {
            [$serviceQuote, $quoteError] = $this->quoteDelivery($request, $cart);
        }

        $deliveryFee = $serviceQuote && !$isPickup ? (int) Utils::numbersOnly($serviceQuote->amount) : 0;
        $codes       = $this->promotionCodes($request);
        $context     = PromotionContext::fromCart($cart, $store, $customer, $isPickup, $deliveryFee);
        $promotions  = app(PromotionEngine::class)->evaluate($context, $codes);
        $blocking    = array_values(array_filter($promotions->rejected ?? [], fn ($rejection) => $rejection['reason'] !== PromotionEngine::REASON_NOT_COMBINABLE));

        $subtotal    = (int) Utils::numbersOnly($cart->subtotal);
        $tip         = (int) Utils::numbersOnly($request->input('tip', 0));
        $deliveryTip = $isPickup ? 0 : (int) Utils::numbersOnly($request->input('delivery_tip', 0));
        $discount    = (int) $promotions->discount();
        $total       = max(0, $subtotal + $deliveryFee + $tip + $deliveryTip - $discount);

        $minimumRequired = (bool) $store->getOption('required_checkout_min');
        $minimumAmount   = (int) Utils::numbersOnly($store->getOption('required_checkout_min_amount', 0));

        $this->releaseSession();

        return response()->json([
            'cart'          => $cart->public_id,
            'service_quote' => $serviceQuote?->public_id,
            'quote_error'   => $quoteError,
            'currency'      => $cart->getCurrency($store->currency),
            'totals'        => [
                'subtotal'     => $subtotal,
                'delivery_fee' => $deliveryFee,
                'discount'     => $discount,
                'tip'          => $tip,
                'delivery_tip' => $deliveryTip,
                'total'        => $total,
                'items'        => (int) $cart->totalItems,
            ],
            'promotions' => [
                'applied'  => $promotions->toPublicArray()['applied'] ?? [],
                'rejected' => $blocking,
            ],
            'minimum' => [
                'required' => $minimumRequired,
                'amount'   => $minimumAmount,
                'met'      => !$minimumRequired || $subtotal >= $minimumAmount,
            ],
            'eta' => $serviceQuote ? data_get($serviceQuote->meta, 'eta') : null,
        ]);
    }

    /**
     * Place the order: a cash checkout for the priced cart, then the same capture that
     * creates the Fleet-Ops order for the app. "Mark as paid" records the payment on the order.
     *
     * @return \Illuminate\Http\Response
     */
    public function place(Request $request)
    {
        $store = $this->resolveStore($request->input('store'));
        if (!$store) {
            return response()->error('Store not found.', 404);
        }

        $customer = $this->resolveCustomer($request->input('customer'));
        if (!$customer) {
            return response()->error('Pick a customer for the order.', 422);
        }

        $isPickup = $request->boolean('is_pickup');
        $this->scopeSessionToStore($store, $customer);

        try {
            $cart = $request->filled('cart') ? Cart::retrieve($request->input('cart')) : $this->buildCart($request, $store);

            if (!count($cart->items ?? [])) {
                throw new \RuntimeException('Add at least one product to the order.');
            }

            $cart->update(['customer_id' => $customer->uuid]);

            $serviceQuote = null;
            if (!$isPickup) {
                $serviceQuote = $request->filled('service_quote') ? ServiceQuote::where('public_id', $request->input('service_quote'))->first() : null;

                if (!$serviceQuote) {
                    [$serviceQuote, $quoteError] = $this->quoteDelivery($request, $cart);

                    if (!$serviceQuote) {
                        throw new \RuntimeException($quoteError ?? 'No delivery rate covers this address.');
                    }
                }
            }

            $checkoutOptions = Utils::createObject([
                'is_pickup'    => $isPickup,
                'is_cod'       => true,
                'tip'          => (int) Utils::numbersOnly($request->input('tip', 0)) ?: false,
                'delivery_tip' => $isPickup ? false : ((int) Utils::numbersOnly($request->input('delivery_tip', 0)) ?: false),
                'placed_via'   => 'console',
            ]);

            $deliveryFee = $serviceQuote ? (int) Utils::numbersOnly($serviceQuote->amount) : 0;
            $promotions  = app(PromotionEngine::class)->evaluate(PromotionContext::fromCart($cart, $store, $customer, $isPickup, $deliveryFee), $this->promotionCodes($request));
            $blocking    = array_values(array_filter($promotions->rejected ?? [], fn ($rejection) => $rejection['reason'] !== PromotionEngine::REASON_NOT_COMBINABLE));
            if ($blocking) {
                throw new \RuntimeException('Promotion code "' . $blocking[0]['code'] . '" cannot be applied (' . $blocking[0]['reason'] . ').');
            }
            if (!$promotions->isEmpty()) {
                $checkoutOptions->promotions = $promotions->toArray();
            }

            $initialized = CheckoutController::initializeCashCheckout($customer, Gateway::cash(), $serviceQuote, $cart, $checkoutOptions, $request);
            if ($initialized instanceof JsonResponse && $initialized->getStatusCode() >= 400) {
                $this->releaseSession();

                return $initialized;
            }

            $checkout = Checkout::where('cart_uuid', $cart->uuid)->latest()->first();
            if (!$checkout) {
                throw new \RuntimeException('Unable to start the checkout.');
            }

            $payment            = $request->input('payment', 'cash');
            $transactionDetails = [
                'payment_status' => $payment === 'paid' ? 'paid' : 'unpaid',
                'payment_method' => $payment === 'paid' ? ($request->input('payment_method') ?? 'console') : 'cash',
                'placed_via'     => 'console',
                'placed_by'      => session('user'),
            ];

            $capture = CaptureOrderRequest::createFrom($request, new CaptureOrderRequest());
            $capture->merge(['token' => $checkout->token, 'notes' => $request->input('notes'), 'transactionDetails' => $transactionDetails]);
            $capture->setContainer(app())->setRedirector(app('redirect'));
            $capture->validateResolved();

            $result = app(CheckoutController::class)->captureOrder($capture);
        } catch (\Throwable $e) {
            $this->releaseSession();

            return response()->error($e->getMessage(), 422);
        }

        $this->releaseSession();

        return $result;
    }

    /**
     * A fresh cart with the request's lines; variants and add-ons are passed as the app sends them.
     */
    private function buildCart(Request $request, Store $store): Cart
    {
        $lines = $request->input('items', []);

        if (!is_array($lines) || !count($lines)) {
            throw new \RuntimeException('Add at least one product to the order.');
        }

        $cart = Cart::newCart('console_' . Str::lower(Str::random(16)));
        $cart->update(['currency' => $store->currency]);

        foreach ($lines as $line) {
            $productId = data_get($line, 'product');
            $quantity  = max(1, (int) data_get($line, 'quantity', 1));
            $variants  = array_values(array_filter((array) data_get($line, 'variants', [])));
            $addons    = array_values(array_filter((array) data_get($line, 'addons', [])));

            if (!$productId) {
                continue;
            }

            $cart->add($productId, $quantity, $variants, $addons, $request->input('pickup_location'), $request->input('scheduled_at'));
        }

        $codes = $this->promotionCodes($request);
        if ($codes) {
            $cart->setPromotionCodes($codes);
            $cart->save();
        }

        return $cart->fresh();
    }

    /**
     * A delivery quote from the store location to the customer's place through the app's own quote logic.
     *
     * @return array{0: ?ServiceQuote, 1: ?string}
     */
    private function quoteDelivery(Request $request, Cart $cart): array
    {
        $location = $request->filled('pickup_location') ? StoreLocation::where(fn ($query) => $query->where('uuid', $request->input('pickup_location'))->orWhere('public_id', $request->input('pickup_location')))->with('place')->first() : null;
        $origin   = $location?->place;
        $dropoff  = $request->input('dropoff');
        $place    = $dropoff ? Place::where(fn ($query) => $query->where('uuid', $dropoff)->orWhere('public_id', $dropoff))->first() : null;

        if (!$origin) {
            return [null, 'Pick the store location the order leaves from.'];
        }

        if (!$place) {
            return [null, 'Pick where the order is delivered to.'];
        }

        try {
            $quoteRequest = GetServiceQuoteFromCart::createFrom($request, new GetServiceQuoteFromCart());
            $quoteRequest->merge([
                'origin'       => $origin->public_id,
                'destination'  => $place->public_id,
                'cart'         => $cart->public_id,
                'scheduled_at' => $request->input('scheduled_at'),
            ]);
            $quoteRequest->setContainer(app())->setRedirector(app('redirect'));
            $quoteRequest->validateResolved();

            $response = app(ServiceQuoteController::class)->fromCart($quoteRequest);
        } catch (\Throwable $e) {
            return [null, $e->getMessage()];
        }

        if ($response instanceof JsonResponse) {
            return [null, data_get($response->getData(true), 'errors.0', data_get($response->getData(true), 'error', 'No delivery rate covers this address.'))];
        }

        $quote = $response->resource ?? null;

        if ($quote instanceof \Illuminate\Support\Collection) {
            $quote = $quote->first();
        }

        return $quote instanceof ServiceQuote ? [$quote, null] : [null, 'No delivery rate covers this address.'];
    }

    private function promotionCodes(Request $request): array
    {
        $codes = $request->input('promo_codes', $request->input('promo_code'));

        if (is_string($codes)) {
            $codes = explode(',', $codes);
        }

        return array_values(array_unique(array_filter(array_map('trim', (array) $codes))));
    }

    private function resolveStore(?string $id): ?Store
    {
        if (!$id) {
            return null;
        }

        return Store::where('company_uuid', session('company'))->where(fn ($query) => $query->where('uuid', $id)->orWhere('public_id', $id))->first();
    }

    private function resolveCustomer(?string $id): ?Contact
    {
        if (!$id) {
            return null;
        }

        return Contact::where('company_uuid', session('company'))->where(fn ($query) => $query->where('uuid', $id)->orWhere('public_id', $id)->orWhere('public_id', str_replace('customer_', 'contact_', $id)))->first();
    }

    /**
     * The cart, quote and checkout code read the storefront from the session the way the
     * public middleware sets it; scope this request to the store, and release it after.
     */
    private function scopeSessionToStore(Store $store, ?Contact $customer): void
    {
        session([
            'storefront_key'               => $store->key,
            'storefront_store'             => $store->uuid,
            'storefront_store_public_id'   => $store->public_id,
            'storefront_network'           => null,
            'storefront_network_public_id' => null,
            'storefront_currency'          => $store->currency,
            'customer_id'                  => $customer?->uuid,
        ]);
    }

    private function releaseSession(): void
    {
        session()->forget(self::SESSION_KEYS);
    }
}
