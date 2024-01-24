<?php

namespace Fleetbase\Storefront\Http\Controllers\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\Category;
use Fleetbase\Storefront\Http\Resources\Category as StorefrontCategory;
use Fleetbase\Storefront\Http\Resources\Product as ProductResource;
use Fleetbase\Storefront\Models\Product;
use Fleetbase\Storefront\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CategoryController extends Controller
{
    /**
     * Query for Storefront Product resources.
     *
     * @return \Illuminate\Http\Response
     */
    public function query(Request $request)
    {
        $results = [];

        if (session('storefront_store')) {
            $results = Category::queryWithRequest($request, function (&$query) use ($request) {
                $query->select([DB::raw('public_id as id'), 'public_id', 'uuid', 'parent_uuid', 'icon_file_uuid', 'name', 'description', 'tags', 'translations', 'icon', 'slug', 'created_at'])->where(['owner_uuid' => session('storefront_store'), 'for' => 'storefront_product']);

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

            // if we want to get categories with products
            if ($request->has('with_products')) {
                // get all category ids
                $categoryIds = $results->map(function ($category) {
                    return $category->uuid;
                })->toArray();

                // get all products in these categories
                $products = Product::whereIn('category_uuid', $categoryIds)->where('is_available', 1)->with(['addonCategories', 'variants', 'files'])->get();

                $results = $results->map(function ($category) use ($products) {
                    $category->products = $products->where('category_uuid', $category->uuid)->mapInto(ProductResource::class)->values();

                    return $category;
                });
            }
        }

        if (session('storefront_network')) {
            if ($request->filled('store')) {
                $store = Store::where([
                    'company_uuid' => session('company'),
                    'public_id'    => $request->input('store'),
                ])->whereHas('networks', function ($q) {
                    $q->where('network_uuid', session('storefront_network'));
                })->first();

                if ($store) {
                    $results = Category::queryWithRequest($request, function (&$query) use ($store, $request) {
                        $query->select([DB::raw('public_id as id'), 'public_id', 'uuid', 'parent_uuid', 'icon_file_uuid', 'name', 'icon', 'description', 'tags', 'translations', 'slug', 'created_at'])->where(['owner_uuid' => $store->uuid, 'for' => 'storefront_product']);

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

                    // if we want to get categories with products
                    if ($request->has('with_products')) {
                        // get all category ids
                        $categoryIds = $results->map(function ($category) {
                            return $category->uuid;
                        })->toArray();

                        // get all products in these categories
                        $products = Product::whereIn('category_uuid', $categoryIds)->where('is_available', 1)->with(['addonCategories', 'variants', 'files'])->get();

                        $results = $results->map(function ($category) use ($products) {
                            // $category->products = Product::where('category_uuid', $category->uuid)->get()->mapInto(ProductResource::class);
                            $category->products = $products->where('category_uuid', $category->uuid)->mapInto(ProductResource::class)->values();

                            return $category;
                        });
                    }
                }
            } else {
                $results = Category::queryWithRequest($request, function (&$query) use ($request) {
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

                // if we want to get categories with stores
                if ($request->has('with_stores')) {
                    $results = $results->map(function ($category) {
                        $category->stores = Store::whereHas('networks', function ($q) use ($category) {
                            $q->where('network_uuid', session('storefront_network'));
                            $q->where('category_uuid', $category->uuid);
                        })->get();

                        return $category;
                    });
                }
            }
        }

        return StorefrontCategory::collection($results);
    }
}
