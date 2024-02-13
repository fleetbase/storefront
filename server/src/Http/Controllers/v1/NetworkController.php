<?php

namespace Fleetbase\Storefront\Http\Controllers\v1;

use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Models\Category;
use Fleetbase\Storefront\Http\Resources\Store as StorefrontStore;
use Fleetbase\Storefront\Http\Resources\StoreLocation as StorefrontStoreLocation;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Models\StoreLocation;
use Illuminate\Http\Request;

class NetworkController extends Controller
{
    /**
     * Returns all stores within the network.
     *
     * @return \Illuminate\Http\Response
     */
    public function stores(Request $request)
    {
        if (session('storefront_store')) {
            return response()->error('Stores cannot have stores!');
        }

        $sort        = $request->input('sort', false);
        $limit       = $request->input('limit', false);
        $offset      = $request->input('offset', false);
        $ids         = $request->input('ids', []);
        $tagged      = $request->input('tagged', []);
        $query       = $request->input('query', false);
        $location    = $request->input('location');
        $maxDistance = $request->input('maximum_distance', null);
        $exclude     = $request->input('exclude', []);

        if (is_string($tagged)) {
            $tagged = explode(',', $tagged);
        }

        if (is_string($exclude)) {
            $exclude = explode(',', $exclude);
        }

        if (is_string($ids)) {
            $ids = explode(',', $ids);
        }

        /** @var \Illuminate\Database\Query\Builder $query */
        $query = Store::select('*')
            ->where('company_uuid', session('company'))
            ->with(['logo', 'backdrop', 'media'])
            ->whereHas('locations')
            ->whereHas('networks', function ($q) use ($request) {
                $q->where('network_uuid', session('storefront_network'));

                // Query stores without a category
                if ($request->filled('without_category')) {
                    $q->whereNull('category_uuid');
                }

                // Query stores by category
                if ($request->filled('category')) {
                    $category = Category::select('uuid')->where('public_id', $request->input('category'))->first();
                    $q->where('category_uuid', $category->uuid);
                    // $q->whereHas('category', function ())
                }
            });

        // query stores using tags provided
        if (!empty($tagged)) {
            $query->where(function ($q) use ($tagged) {
                foreach ($tagged as $tag) {
                    $q->orWhereJsonContains('tags', $tag);
                }
            });
        }

        // if we need to exclude specific stores
        if (!empty($exclude)) {
            $query->where(function ($q) use ($exclude) {
                $q->whereNotIn('public_id', $exclude);
            });
        }

        // if query for specific ids
        if (!empty($ids)) {
            $query->where(function ($q) use ($ids) {
                $q->whereIn('public_id', $ids);
            });
        }

        // only get stores with `StoreLocation` within $maxDistance (meters)
        // need to move place->location as alias column to storeLocation->location
        // when place gets updated/changed it also updates corresponding storeLocation in observer
        // if ($location && $maxDistance) {
        //     $query->whereHas('locations', function ($q) use ($maxDistance) {
        //     });
        // }

        switch ($sort) {
            case 'highest_rated':
                $query->withAvg('reviews', 'rating')->orderByDesc('reviews_avg_rating');
                // no break
            case 'lowest_rated':
                $query->withAvg('reviews', 'rating')->orderBy('reviews_avg_rating');
                // no break
            case 'newest':
                $query->orderByDesc('created_at');
                // no break
            case 'oldest':
                $query->orderBy('created_at');
                // no break
            case 'popular':
                $query->withCount('checkouts')->orderByDesc('checkouts_count');
        }

        if ($limit) {
            $query->limit($limit);
        }

        if ($offset) {
            $query->offset($offset);
        }

        $stores = $query->get();

        // handle nearest sort special case due to location depth
        if ($sort === 'nearest' && $location) {
            $coordinates = Utils::getPointFromCoordinates($location);

            $stores = $stores->sort(function ($storeA, $storeB) use ($coordinates) {
                $distanceA = $storeA->locations->sort(function ($locationA, $locationB) use ($coordinates) {
                    $distanceA = Utils::vincentyGreatCircleDistance($coordinates, $locationA->place->location);
                    $distanceB = Utils::vincentyGreatCircleDistance($coordinates, $locationB->place->location);

                    return $distanceA - $distanceB;
                })->map(function ($location) use ($coordinates) {
                    $location->distance = Utils::vincentyGreatCircleDistance($coordinates, $location->place->location);

                    return $location;
                })->first()->distance;

                $distanceB = $storeB->locations->sort(function ($locationA, $locationB) use ($coordinates) {
                    $distanceA = Utils::vincentyGreatCircleDistance($coordinates, $locationA->place->location);
                    $distanceB = Utils::vincentyGreatCircleDistance($coordinates, $locationB->place->location);

                    return $distanceA - $distanceB;
                })->map(function ($location) use ($coordinates) {
                    $location->distance = Utils::vincentyGreatCircleDistance($coordinates, $location->place->location);

                    return $location;
                })->first()->distance;

                return $distanceA - $distanceB;
            });
        }

        // sort trending ( most checkouts within 24h )
        if ($sort === 'trending') {
            $stores = $stores->sortByDesc('24h_checkouts_count');
        }

        return StorefrontStore::collection($stores);
    }

    /**
     * Returns all store locations within the network.
     *
     * @return \Illuminate\Http\Response
     */
    public function storeLocations(Request $request)
    {
        $limit              = $request->input('limit', 30);
        $ids                = $request->input('ids', []);
        $exclude            = $request->input('exclude', []);
        $offset             = $request->input('offset');
        $location           = $request->input('location');
        $tagged             = $request->input('tagged', []);
        $searchQuery        = $request->input('query', false);
        $shouldIncludeStore = $request->input('with_store', false);
        $coordinates        = Utils::getPointFromCoordinates($location);

        if (is_string($tagged)) {
            $tagged = explode(',', $tagged);
        }

        if (is_string($exclude)) {
            $exclude = explode(',', $exclude);
        }

        if (is_string($ids)) {
            $ids = explode(',', $ids);
        }

        $databaseName    = config('database.connections.mysql.database');
        $placesTableName = $databaseName . '.places';

        $query = StoreLocation::select(['store_locations.*', $placesTableName . '.location', $placesTableName . '.uuid'])
            ->join($placesTableName, $placesTableName . '.uuid', '=', 'store_locations.place_uuid')
            ->whereHas('store', function ($q) use ($tagged, $searchQuery) {
                $q->whereHas('networks', function ($q) {
                    $q->where('network_uuid', session('storefront_network'));
                });

                if (!empty($tagged)) {
                    $q->where(function ($q) use ($tagged) {
                        foreach ($tagged as $tag) {
                            $q->orWhereJsonContains('tags', $tag);
                        }
                    });
                }

                if ($searchQuery) {
                    $q->search($searchQuery);
                }
            });

        if ($shouldIncludeStore) {
            $query->with('store');
        }

        // if we need to exclude specific stores
        if (!empty($exclude)) {
            $query->where(function ($q) use ($exclude) {
                $q->whereNotIn('store_locations.public_id', $exclude);
            });
        }

        // if query for specific ids
        if (!empty($ids)) {
            $query->where(function ($q) use ($ids) {
                $q->whereIn('store_locations.public_id', $ids);
            });
        }

        if ($limit) {
            $query->limit($limit);
        }

        if ($offset) {
            $query->offset($offset);
        }

        if ($coordinates instanceof Point) {
            $query->orderByDistance('location', $coordinates);
        }

        $locations = $query->get();

        return StorefrontStoreLocation::collection($locations);
    }

    /**
     * Returns all tags from stores/categories throughout the network.
     *
     * @return \Illuminate\Http\Response
     */
    public function tags(Request $request)
    {
        $tags = [];

        $stores = Store::select(['tags'])
            ->whereHas('locations')
            ->whereHas('networks', function ($q) {
                $q->where('network_uuid', session('storefront_network'));
            })->get();

        foreach ($stores as $store) {
            $tags = array_merge($tags, $store->tags ?? []);
        }

        // $categories = Category::select(['tags'])->where(['owner_uuid' => session('storefront_network'), 'for' => 'storefront_network']);
        // foreach ($categories as $category) {
        //     $tags = array_merge($tags, $category->tags ?? []);
        // }

        // unique only
        $tags = array_values(array_unique($tags));

        return response()->json($tags);
    }
}
