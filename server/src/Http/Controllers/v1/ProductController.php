<?php

namespace Fleetbase\Storefront\Http\Controllers\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Storefront\Http\Resources\Product as StorefrontProduct;
use Fleetbase\Http\Resources\v1\DeletedResource;
use Fleetbase\Models\Category;
use Fleetbase\Storefront\Models\Product;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * Query for Storefront Product resources.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Fleetbase\Http\Resources\DriverCollection
     */
    public function query(Request $request)
    {
        $results = Product::queryWithRequest($request, function (&$query, $request) {
            // for stores
            if (session('storefront_store')) {
                $query->where(['store_uuid' => session('storefront_store')]);
            }

            // for networks
            if (session('storefront_network')) {
                $query->whereHas('store', function ($sq) {
                    $sq->whereHas('networks', function ($nq) {
                        $nq->where('network_uuid', session('storefront_network'));
                    });
                });
            }

            // @todo When done dev is completed make sure status is published - also add status field to product view
            $query->where('is_available', 1);
            $query->with(['addonCategories.category', 'variants.options', 'files']);

            if ($request->filled('category')) {
                $category = Category::where(['public_id' => $request->input('category'), 'for' => 'storefront_product'])->first();

                if ($category) {
                    $query->where('category_uuid', $category->uuid);
                }
            }
        });

        return StorefrontProduct::collection($results);
    }

    /**
     * Finds a single Storefront Product resources.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Fleetbase\Http\Resources\EntityCollection
     */
    public function find($id)
    {
        // find for the product
        try {
            $product = Product::findRecordOrFail($id);
        } catch (ModelNotFoundException $exception) {
            return response()->error('Product resource not found.');
        }

        // response the product resource
        return new StorefrontProduct($product);
    }

    /**
     * Deletes a Storefront Product resources.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Fleetbase\Http\Resources\v1\DeletedResource
     */
    public function delete($id)
    {
        // find for the product
        try {
            $product = Product::findRecordOrFail($id);
        } catch (ModelNotFoundException $exception) {
            return response()->error('Product resource not found.');
        }

        // delete the product
        $product->delete();

        // response the product resource
        return new DeletedResource($product);
    }
}
