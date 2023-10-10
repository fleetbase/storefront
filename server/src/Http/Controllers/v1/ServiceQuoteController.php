<?php

namespace Fleetbase\Storefront\Http\Controllers\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Storefront\Http\Requests\GetServiceQuoteFromCart;
use Fleetbase\FleetOps\Http\Resources\v1\ServiceQuote as ServiceQuoteResource;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\FleetOps\Models\ServiceQuoteItem;
use Fleetbase\FleetOps\Models\ServiceRate;
use Fleetbase\FleetOps\Models\IntegratedVendor;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Models\Cart;
use Fleetbase\Storefront\Models\Product;
use Fleetbase\Storefront\Models\StoreLocation;
use Fleetbase\FleetOps\Support\Flow;
use Fleetbase\FleetOps\Support\Utils;
use Illuminate\Support\Str;

class ServiceQuoteController extends Controller
{
    /**
     * Query for Storefront Product resources.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Fleetbase\Http\Resources\DriverCollection
     */
    public function fromCart(GetServiceQuoteFromCart $request)
    {
        $requestId = ServiceQuote::generatePublicId('request');
        $origin = $this->getPlaceFromId($request->input('origin'));
        $destination = $this->getPlaceFromId($request->input('destination'));
        $facilitator = $request->input('facilitator');
        $scheduledAt = $request->input('scheduled_at');
        $serviceType = $request->input('service_type');
        $cart = Cart::retrieve($request->input('cart'));
        $currency = $cart->currency;
        $config = $request->input('config', 'storefront');
        $all = $request->boolean('all');
        $isRouteOptimized = $request->boolean('is_route_optimized', true);
        $isNetwork = Str::startsWith(session('storefront_key'), 'network_');

        if ($isNetwork) {
            return $this->fromCartForNetwork($request);
        }

        if (!$origin) {
            return response()->error('No delivery origin!');
        }

        if (!$destination) {
            return response()->error('No delivery destination!');
        }

        // if no cart respond with error
        if (!$cart) {
            return response()->error('Cart session not found!');
        }

        // if facilitator is an integrated partner resolve service quotes from bridge
        if ($facilitator && Utils::isIntegratedVendorId($facilitator)) {
            $integratedVendor = IntegratedVendor::where('company_uuid', session('company'))->where(function ($q) use ($facilitator) {
                $q->where('public_id', $facilitator);
                $q->orWhere('provider', $facilitator);
            })->first();

            if ($integratedVendor) {
                try {
                    /** @var \Fleetbase\Models\ServiceQuote $serviceQuote */
                    $serviceQuote = $integratedVendor->api()->setRequestId($requestId)->getQuoteFromPreliminaryPayload([$origin, $destination], [], $serviceType, $scheduledAt, $isRouteOptimized);
                } catch (\Exception $e) {
                    return response()->error($e->getMessage());
                }
            }

            // set origin and destination in service quote meta
            $serviceQuote->updateMeta([
                'origin' => $origin->public_id,
                'destination' => $destination->public_id,
            ]);

            return new ServiceQuoteResource($serviceQuote);
        }

        // get distance matrix
        $matrix = Utils::getDrivingDistanceAndTime($origin, $destination);

        // create entities from cart items
        $entities = collect($cart->items ?? [])->map(function ($cartItem) {
            $product = Product::where('public_id', $cartItem->product_id)->first();

            return Entity::fromStorefrontProduct($product);
        });

        // prepare to collect service quotes
        $serviceQuotes = collect();

        // get order configurations for ecommerce / task
        $orderConfigs = Flow::queryOrderConfigurations(function (&$query) use ($config) {
            $query->where('key', $config);
        });

        // get service rates for config type
        $serviceRates = ServiceRate::whereIn('service_type', $orderConfigs->pluck('key'))->get();

        // if no service rates send an empty quote
        if ($serviceRates->isEmpty()) {
            // if service rates is empty but there is integrated vendors, get quote from integrated vendors
            $integratedVendor = IntegratedVendor::where('company_uuid', session('company'))->first();

            if ($integratedVendor) {
                try {
                    /** @var \Fleetbase\Models\ServiceQuote $serviceQuote */
                    $serviceQuote = $integratedVendor->api()->setRequestId($requestId)->getQuoteFromPreliminaryPayload([$origin, $destination], [], $serviceType, $scheduledAt, $isRouteOptimized);
                } catch (\Exception $e) {
                    return response()->error($e->getMessage());
                }

                // set origin and destination in service quote meta
                $serviceQuote->updateMeta([
                    'origin' => $origin->public_id,
                    'destination' => $destination->public_id,
                ]);

                return new ServiceQuoteResource($serviceQuote);
            }

            return response()->error('No service rates available!');
        }

        foreach ($serviceRates as $serviceRate) {
            // get a quote from each rate and send back the best
            [$subTotal, $lines] = $serviceRate->quoteFromPreliminaryData($entities, [$origin, $destination], $matrix->distance, $matrix->time, false);

            $quote = ServiceQuote::create([
                'request_id' => $requestId,
                'company_uuid' => $serviceRate->company_uuid,
                'service_rate_uuid' => $serviceRate->uuid,
                'amount' => $subTotal,
                'currency' => $serviceRate->currency,
                'meta' => [
                    'origin' => $origin->public_id,
                    'destination' => $destination->public_id,
                ]
            ]);

            $items = $lines->map(function ($line) use ($quote) {
                return ServiceQuoteItem::create([
                    'service_quote_uuid' => $quote->uuid,
                    'amount' => $line['amount'],
                    'currency' => $line['currency'],
                    'details' => $line['details'],
                    'code' => $line['code'],
                ]);
            });

            $quote->setRelation('items', $items);
            $serviceQuotes->push($quote);
        }

        // if user is requesting all return all service quotes
        if ($all) {
            return ServiceQuoteResource::collection($serviceQuotes);
        }

        // filter by currency
        $matchingCurrency = $serviceQuotes->where('currency', $currency);

        if ($matchingCurrency->count()) {
            $bestQuote = $matchingCurrency->sortBy('amount')->first();

            return new ServiceQuoteResource($bestQuote);
        }

        // get the best service quote
        $bestQuote = $serviceQuotes->sortBy('amount')->first();

        return new ServiceQuoteResource($bestQuote);
    }
    /**
     * Query for Storefront Product resources.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Fleetbase\Http\Resources\DriverCollection
     */
    public function fromCartForNetwork(GetServiceQuoteFromCart $request)
    {
        $requestId = ServiceQuote::generatePublicId('request');
        // $origin = $this->getPlaceFromId($request->input('origin'));
        $destination = $this->getPlaceFromId($request->input('destination'));
        $facilitator = $request->input('facilitator');
        $scheduledAt = $request->input('scheduled_at');
        $serviceType = $request->input('service_type');
        $cart = Cart::retrieve($request->input('cart'));
        $currency = $cart->currency;
        $config = $request->input('config', 'storefront');
        $all = $request->boolean('all');
        $isRouteOptimized = $request->boolean('is_route_optimized', true);

        // make sure destination is set
        if (!$destination) {
            return response()->error('No delivery destination!');
        }

        // if no cart respond with error
        if (!$cart) {
            return response()->error('Cart session not found!');
        }

        // collect stores
        $storeLocations = collect($cart->items)->map(function ($cartItem) {
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
            return StoreLocation::where('public_id', $storeLocationId)->with(['store', 'place'])->first();
        });

        // fallback store locations using origin param
        if ($storeLocations->isEmpty()) {
            $storeLocationIds = $request->input('origin', []);

            if (is_string($storeLocationIds) && Str::contains($storeLocationIds, ',')) {
                $storeLocationIds = explode(',', $storeLocationIds);
            }

            $storeLocations = collect($storeLocationIds)->unique()->filter()->map(function ($storeLocationId) {
                return StoreLocation::where('public_id', $storeLocationId)->with(['store', 'place'])->first();
            });
        }

        // get origins
        $origins = $storeLocations->map(function ($storeLocation) {
            return $storeLocation->place;
        });

        // if facilitator is an integrated partner resolve service quotes from bridge
        if ($facilitator && Utils::isIntegratedVendorId($facilitator)) {
            $integratedVendor = IntegratedVendor::where('company_uuid', session('company'))->where(function ($q) use ($facilitator) {
                $q->where('public_id', $facilitator);
                $q->orWhere('provider', $facilitator);
            })->first();

            if ($integratedVendor) {
                try {
                    /** @var \Fleetbase\Models\ServiceQuote $serviceQuote */
                    $serviceQuote = $integratedVendor->api()->setRequestId($requestId)->getQuoteFromPreliminaryPayload([...$origins, $destination], [], $serviceType, $scheduledAt, $isRouteOptimized);
                } catch (\Exception $e) {
                    return response()->error($e->getMessage());
                }
            }

            // set origin and destination in service quote meta
            $serviceQuote->updateMeta([
                'origin' => $origins->pluck('public_id')->toArray(),
                'destination' => $destination->public_id,
            ]);

            return new ServiceQuoteResource($serviceQuote);
        }

        // get distance matrix
        // $matrix = Utils::getDrivingDistanceAndTime($origin, $destination);
        $matrix = Utils::distanceMatrix($origins, [$destination]);

        // create entities from cart items
        $entities = collect($cart->items ?? [])->map(function ($cartItem) {
            $product = Product::where('public_id', $cartItem->product_id)->first();

            return Entity::fromStorefrontProduct($product);
        });

        // prepare to collect service quotes
        $serviceQuotes = collect();

        // get order configurations for ecommerce / task
        $orderConfigs = Flow::queryOrderConfigurations(function (&$query) use ($config) {
            $query->where('key', $config);
        });

        // get service rates for config type
        $serviceRates = ServiceRate::whereIn('service_type', $orderConfigs->pluck('key'))->get();

        // if no service rates send an empty quote
        if ($serviceRates->isEmpty()) {
            // if service rates is empty but there is integrated vendors, get quote from integrated vendors
            $integratedVendor = IntegratedVendor::where('company_uuid', session('company'))->first();

            if ($integratedVendor) {
                try {
                    /** @var \Fleetbase\FleetOps\Models\ServiceQuote $serviceQuote */
                    $serviceQuote = $integratedVendor->api()->setRequestId($requestId)->getQuoteFromPreliminaryPayload([...$origins, $destination], [], $serviceType, $scheduledAt, $isRouteOptimized);
                } catch (\Exception $e) {
                    return response()->error($e->getMessage());
                }

                // set origin and destination in service quote meta
                $serviceQuote->updateMeta([
                    'origin' => $origins->pluck('public_id')->toArray(),
                    'destination' => $destination->public_id,
                ]);

                return new ServiceQuoteResource($serviceQuote);
            }

            return response()->error('No service rates available!');
        }

        foreach ($serviceRates as $serviceRate) {
            // get a quote from each rate and send back the best
            [$subTotal, $lines] = $serviceRate->quoteFromPreliminaryData($entities, [...$origins, $destination], $matrix->distance, $matrix->time, false);

            $quote = ServiceQuote::create([
                'request_id' => $requestId,
                'company_uuid' => $serviceRate->company_uuid,
                'service_rate_uuid' => $serviceRate->uuid,
                'amount' => $subTotal,
                'currency' => $serviceRate->currency,
                'meta' => [
                    'origin' => $origins->pluck('public_id')->toArray(),
                    'destination' => $destination->public_id,
                ]
            ]);

            $items = $lines->map(function ($line) use ($quote) {
                return ServiceQuoteItem::create([
                    'service_quote_uuid' => $quote->uuid,
                    'amount' => $line['amount'],
                    'currency' => $line['currency'],
                    'details' => $line['details'],
                    'code' => $line['code'],
                ]);
            });

            $quote->setRelation('items', $items);
            $serviceQuotes->push($quote);
        }

        // if user is requesting all return all service quotes
        if ($all) {
            return ServiceQuoteResource::collection($serviceQuotes);
        }

        // filter by currency
        $matchingCurrency = $serviceQuotes->where('currency', $currency);

        if ($matchingCurrency->count()) {
            $bestQuote = $matchingCurrency->sortBy('amount')->first();

            return new ServiceQuoteResource($bestQuote);
        }

        // get the best service quote
        $bestQuote = $serviceQuotes->sortBy('amount')->first();

        return new ServiceQuoteResource($bestQuote);
    }

    /**
     * Returns a place from either a place id or store location id.
     *
     * @param string $id
     * @return Place
     */
    public function getPlaceFromId($id)
    {
        if (Str::startsWith($id, 'store_location')) {
            $storeLocation = StoreLocation::select(['place_uuid'])->where(['public_id' => $id, 'store_uuid' => session('storefront_store')])->with(['place'])->first();

            if (!$storeLocation) {
                return null;
            }

            return $storeLocation->place;
        }

        if (Str::startsWith($id, 'place')) {
            return Place::where(['public_id' => $id, 'company_uuid' => session('company')])->first();
        }

        // handle coordinates tooo!
        if (!Str::startsWith($id, ['place_', 'store_location']) && Utils::isCoordinates($id)) {
            $point = Utils::getPointFromCoordinates($id);
            $place = Place::createFromCoordinates($point, ['company_uuid' => session('company')], true);

            return $place;
        }

        return Place::where(['public_id' => $id, 'company_uuid' => session('company')])->first();
    }
}
