<?php

namespace Fleetbase\Storefront\Http\Controllers\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\Category;
use Fleetbase\Storefront\Http\Resources\Category as StorefrontCategory;
use Fleetbase\Storefront\Http\Resources\Product as ProductResource;
use Fleetbase\Storefront\Models\NetworkStore;
use Fleetbase\Storefront\Models\Product;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Support\CategoryTree;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CategoryController extends Controller
{
    /**
     * Query for Storefront Category resources.
     *
     * @return \Illuminate\Http\Response
     */
    public function query(Request $request)
    {
        $results = [];

        if (session('storefront_store')) {
            $results = $this->productCategories($request, session('storefront_store'));
        }

        if (session('storefront_network')) {
            if ($request->filled('store')) {
                $store = Store::where([
                    'public_id'    => $request->input('store'),
                ])->activeInNetwork(session('storefront_network'))->first();

                if ($store) {
                    $results = $this->productCategories($request, $store->uuid);
                }
            } else {
                $results = Category::queryWithRequestCached($request, function (&$query) use ($request) {
                    $query->select([DB::raw('public_id as id'), 'public_id', 'uuid', 'parent_uuid', 'name', 'icon', 'description', 'tags', 'translations', 'slug', 'created_at'])->where(['owner_uuid' => session('storefront_network'), 'for' => 'storefront_network']);

                    // only parent categories
                    if ($request->has('parents_only')) {
                        $query->whereNull('parent_uuid');
                    }

                    // get categories with parent
                    if ($request->has('parent')) {
                        $query->whereHas('parentCategory', function ($q) use ($request) {
                            $q->where('public_id', $request->input('parent'));
                        });
                    }
                });

                $results = $this->withTreeIndex($results, Category::where(['owner_uuid' => session('storefront_network'), 'for' => 'storefront_network'])->get(['uuid', 'public_id', 'parent_uuid']));

                // if we want to get categories with stores
                if ($request->has('with_stores')) {
                    $categoryIds = $results->pluck('uuid');
                    $stores      = Store::whereHas('networks', function ($q) use ($categoryIds) {
                        $q->where('network_uuid', session('storefront_network'));
                        $q->where('network_stores.status', NetworkStore::STATUS_ACTIVE);
                        $q->whereIn('category_uuid', $categoryIds);
                    })->with(['networks' => function ($q) use ($categoryIds) {
                        $q->where('network_uuid', session('storefront_network'));
                        $q->whereIn('category_uuid', $categoryIds);
                    }])->get();

                    $results = $results->map(function ($category) use ($stores) {
                        $category->stores = $stores->filter(function ($store) use ($category) {
                            return $store->networks->contains(function ($network) use ($category) {
                                return $network->pivot?->category_uuid === $category->uuid;
                            });
                        })->values();

                        return $category;
                    });
                }
            }
        }

        return StorefrontCategory::collection($results);
    }

    /**
     * A store's product categories. Each carries its parent's public id and its level, so an app
     * can build the Drinks › Coffee › Cold brew tree; `with_subcategories` adds the categories
     * directly under each one; `with_products` adds the products, and with `rollup` also those of
     * every category under it.
     */
    protected function productCategories(Request $request, string $storeUuid)
    {
        $columns = [DB::raw('public_id as id'), 'public_id', 'uuid', 'parent_uuid', 'icon_file_uuid', 'name', 'description', 'tags', 'translations', 'icon', 'slug', 'order', 'created_at'];
        $results = Category::queryWithRequestCached($request, function (&$query) use ($request, $storeUuid, $columns) {
            $query->select($columns)->where(['owner_uuid' => $storeUuid, 'for' => 'storefront_product']);

            // only parent categories
            if ($request->has('parents_only')) {
                $query->whereNull('parent_uuid');
            }

            // get categories with parent
            if ($request->has('parent')) {
                $query->whereHas('parentCategory', function ($q) use ($request) {
                    $q->where('public_id', $request->input('parent'));
                });
            }
        });

        // Every category of the store, once: the index, the subcategories and the rollup read it.
        $all     = Category::select($columns)->where(['owner_uuid' => $storeUuid, 'for' => 'storefront_product'])->orderBy('order')->get();
        $results = $this->withTreeIndex($results, $all);

        if ($request->has('with_subcategories') || $request->inArray('with', 'subcategories')) {
            $children = $this->withTreeIndex($all, $all)->groupBy('parent_uuid');
            $results  = $results->map(function ($category) use ($children) {
                $category->setRelation('subCategories', $children->get($category->uuid, collect())->values());

                return $category;
            });
        }

        if ($request->has('with_products')) {
            $rollup = $request->boolean('rollup');
            $under  = function (string $uuid) use ($all, $rollup): array {
                if (!$rollup) {
                    return [$uuid];
                }

                $uuids = [$uuid];
                for ($level = [$uuid], $depth = 1; $level && $depth < CategoryTree::MAX_DEPTH; $depth++) {
                    $level = $all->whereIn('parent_uuid', $level)->pluck('uuid')->all();
                    $uuids = array_merge($uuids, $level);
                }

                return $uuids;
            };

            $categoryIds = $results->flatMap(fn ($category) => $under($category->uuid))->unique()->values()->all();
            $products    = Product::whereIn('category_uuid', $categoryIds)
                ->where('store_uuid', $storeUuid)
                ->where('is_available', 1)
                ->where('status', 'published')
                ->with(['addonCategories', 'variants', 'files', 'category:uuid,public_id'])
                ->get();

            $results = $results->map(function ($category) use ($products, $under) {
                $category->products = $products->whereIn('category_uuid', $under($category->uuid))->mapInto(ProductResource::class)->values();

                return $category;
            });
        }

        return $results;
    }

    /**
     * Set each category's parent public id and level from the categories of the same store or network.
     */
    protected function withTreeIndex($results, $all)
    {
        $index = CategoryTree::index($all);

        return collect($results)->map(function ($category) use ($index) {
            $category->setAttribute('parent_public_id', data_get($index, $category->uuid . '.parent'));
            $category->setAttribute('depth', data_get($index, $category->uuid . '.depth', 1));

            return $category;
        });
    }
}
