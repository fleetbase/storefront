<?php

namespace Fleetbase\Storefront\Http\Controllers\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\Category;
use Fleetbase\Storefront\Http\Resources\Gateway as GatewayResource;
use Fleetbase\Storefront\Http\Resources\Network as NetworkResource;
use Fleetbase\Storefront\Http\Resources\Product as ProductResource;
use Fleetbase\Storefront\Http\Resources\Store as StorefrontResource;
use Fleetbase\Storefront\Http\Resources\StoreLocation as StoreLocationResource;
use Fleetbase\Storefront\Models\Gateway;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Product;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Models\StoreLocation;
use Fleetbase\Storefront\Support\Storefront;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StoreController extends Controller
{
    /**
     * Returns general information and settings about the storefront or network given the key.
     *
     * @return \Illuminate\Http\Response
     */
    public function about()
    {
        $about = Storefront::about('*');

        if (!$about) {
            return response()->error(__('storefront::api.stores.not_found'));
        }

        if ($about->is_store) {
            return new StorefrontResource($about);
        }

        return new NetworkResource($about);
    }

    /**
     * Lookup a store or network provided the ID.
     *
     * @return \Illuminate\Http\Response
     */
    public function lookup(?string $id)
    {
        if (!$id) {
            return response()->apiError(__('storefront::api.stores.lookup_id_required'));
        }

        $store = Store::where('public_id', $id)
            ->when(session('storefront_network'), function ($query) {
                $query->activeInNetwork(session('storefront_network'));
            })
            ->when(session('storefront_store'), function ($query) {
                $activeStore = session('storefront_store');
                $query->where(fn ($storeQuery) => $storeQuery->where('uuid', $activeStore)->orWhere('public_id', $activeStore));
            })
            ->first();
        if ($store) {
            return new StorefrontResource($store);
        }

        $network = Network::where('public_id', $id)
            ->when(session('storefront_network'), fn ($query) => $query->where('uuid', session('storefront_network')))
            ->when(session('storefront_store'), fn ($query) => $query->where('company_uuid', session('company')))
            ->first();
        if ($network) {
            return new NetworkResource($network);
        }

        return response()->apiError(__('storefront::api.stores.store_or_network_not_found'));
    }

    /**
     * Returns all locations and their hours for the current store.
     *
     * @return \Illuminate\Http\Response
     */
    public function locations(Request $request)
    {
        if (session('storefront_network') && $request->missing('store')) {
            return response()->error(__('storefront::api.stores.networks_cannot_have_locations'));
        }

        if ($request->filled('store')) {
            $locations = StoreLocation::whereHas('store', function ($q) use ($request) {
                $q->where('public_id', $request->input('store'));

                if (session('storefront_network')) {
                    $q->activeInNetwork(session('storefront_network'));
                }

                if (session('storefront_store')) {
                    $q->where('uuid', session('storefront_store'));
                }
            })->with(['place', 'hours'])->get();
        } else {
            $locations = StoreLocation::where('store_uuid', session('storefront_store'))->with(['place', 'hours'])->get();
        }

        return StoreLocationResource::collection($locations);
    }

    /**
     * Returns a specific store location given the id.
     *
     * @return \Illuminate\Http\Response
     */
    public function location(string $id, Request $request)
    {
        if (session('storefront_network') && $request->missing('store')) {
            return response()->error(__('storefront::api.stores.networks_cannot_have_locations'));
        }

        $storeId = $request->input('store', session('storefront_store'));
        $store   = Store::where(function ($query) use ($storeId) {
            $query->where('public_id', $storeId)->orWhere('uuid', $storeId);
        })
            ->when(session('storefront_network'), function ($query) {
                $query->activeInNetwork(session('storefront_network'));
            })
            ->when(session('storefront_store'), function ($query) {
                $activeStore = session('storefront_store');
                $query->where(fn ($storeQuery) => $storeQuery->where('uuid', $activeStore)->orWhere('public_id', $activeStore));
            })
            ->first();

        // Both lookups were unguarded, so an id that resolved nothing was handed straight
        // to the resource and blew up inside it — GET /storefront/v1/locations/{id}
        // answered 500 with an HTML stack trace for any unknown location, which is what a
        // client sees for a stale or mistyped id. 404 is the correct answer.
        if (!$store) {
            return response()->error(__('storefront::api.stores.not_found'), 404);
        }

        $location = StoreLocation::where([
            'public_id'  => $id,
            'store_uuid' => $store->uuid,
        ])
            ->with(['place', 'hours'])
            ->first();

        if (!$location) {
            return response()->error(__('storefront::api.stores.location_not_found'), 404);
        }

        return new StoreLocationResource($location);
    }

    /**
     * Returns all payment gateways for the current store.
     *
     * @return \Illuminate\Http\Response
     */
    public function gateways(Request $request)
    {
        $id = session('storefront_store') ?? session('storefront_network');

        $sandbox = $request->input('sandbox', false);
        $query   = Gateway::select(['public_id', 'name', 'code', 'type', 'sandbox', 'return_url', 'callback_url'])->where('owner_uuid', $id);

        if ($sandbox) {
            $query->where('sandbox', 1);
        }

        // fetch all gateways available
        $gateways = $query->get();

        // create cod/cash gateway if enabled
        // @var \Fleetbase\Models\Storefront\Store|\Fleetbase\Models\Storefront\Network $about
        $about = Storefront::about();

        // if cod is enabled add cash as a gateway
        if ($about->hasOption('cod_enabled')) {
            $gateways->push(Gateway::cash(['sandbox'=>$sandbox]));
        }

        return GatewayResource::collection($gateways);
    }

    /**
     * Returns a specific payment gateway given the id.
     *
     * @return \Illuminate\Http\Response
     */
    public function gateway(string $id, Request $request)
    {
        $ownerId = session('storefront_store') ?? session('storefront_network');

        $sandbox = $request->input('sandbox', false);
        $query   = Gateway::select(['public_id', 'name', 'code', 'type', 'sandbox', 'return_url', 'callback_url'])
            ->where(['owner_uuid' => $ownerId])
            ->where(function ($q) use ($id) {
                $q->where('public_id', $id);
                $q->orWhere('code', $id);
            });

        if ($sandbox) {
            $query->where('sandbox', 1);
        }

        // fetch all gateways available
        $gateway = $query->first();

        return new GatewayResource($gateway);
    }

    /**
     * Search current store or network.
     *
     * @return \Illuminate\Http\Response
     */
    public function search(Request $request)
    {
        $searchQuery = $request->input('query', '');
        $limit       = $request->input('limit', 14);
        $store       = $request->input('store');
        $key         = session('storefront_key');

        if (Str::startsWith($key, 'store')) {
            $results = Product::where('store_uuid', session('storefront_store'))
                ->search($searchQuery)
                ->limit($limit)
                ->whereNull('deleted_at')
                ->whereIsAvailable(1)
                ->whereStatus('published')
                ->get();

            // Search categories as well
            $categories = Category::where(['company_uuid' => session('company'), 'for' => 'storefront_product'])->search($searchQuery)->get();
            if ($categories) {
                foreach ($categories as $category) {
                    $categoryProducts = Product::where([
                        'category_uuid' => $category->uuid,
                        'store_uuid'    => session('storefront_store'),
                        'is_available'  => 1,
                        'status'        => 'published',
                    ])->get();
                    $results          = $results->merge($categoryProducts)->unique('uuid');
                }
            }

            return ProductResource::collection($results);
        }

        $results = Product::findFromNetwork($searchQuery, $store, $limit);
        if ($request->boolean('with_store') || $request->inArray('with', 'store')) {
            $results->load(['store.logo', 'store.backdrop']);
        }

        return ProductResource::collection($results);
    }
}
