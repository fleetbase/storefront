<?php

namespace Fleetbase\Storefront\Http\Controllers\v1;

use Fleetbase\FleetOps\Http\Resources\v1\DeletedResource;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\Category;
use Fleetbase\Storefront\Http\Requests\CreateProductRequest;
use Fleetbase\Storefront\Http\Requests\UpdateProductRequest;
use Fleetbase\Storefront\Http\Resources\Product as StorefrontProduct;
use Fleetbase\Storefront\Models\AddonCategory;
use Fleetbase\Storefront\Models\Product;
use Fleetbase\Storefront\Models\ProductAddon;
use Fleetbase\Storefront\Models\ProductAddonCategory;
use Fleetbase\Storefront\Models\ProductVariant;
use Fleetbase\Storefront\Models\ProductVariantOption;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Support\Utils;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * Create a new Storefront product.
     *
     * @return void
     */
    public function create(CreateProductRequest $request)
    {
        // Collect product details input
        $input = $request->only([
            'name',
            'description',
            'tags',
            'meta',
            'sku',
            'price',
            'currency',
            'sale_price',
            'addons',
            'variants',
            'is_service',
            'is_bookable',
            'is_available',
            'is_on_sale',
            'is_recommended',
            'can_pickup',
            'youtube_urls',
            'status',
        ]);

        // Set product store
        $input['store_uuid'] = session('storefront_store');

        // Set product relations
        $input['company_uuid']    = session('company');
        $input['created_by_uuid'] = session('user');

        // Prepare arrayable data
        $input['tags']         = Utils::arrayFrom(data_get($input, 'tags', []));
        $input['youtube_urls'] = Utils::arrayFrom(data_get($input, 'youtube_urls', []));

        // Prepare money
        $input['price']      = Utils::numbersOnly(data_get($input, 'price', 0));
        $input['sale_price'] = Utils::numbersOnly(data_get($input, 'sale_price', 0));

        // Set currency
        $input['currency'] = data_get($input, 'currency', session('storefront_currency', 'USD'));

        // Resolve category
        if ($request->filled('category')) {
            $categoryInput = $request->input('category');

            if (Utils::isPublicId($categoryInput)) {
                $category = Category::where([
                    'company_uuid' => session('company'),
                    'owner_uuid'   => session('storefront_store'),
                    'public_id'    => $categoryInput,
                    'for'          => 'storefront_product',
                ])->first();
            }

            // Create new product if data is array
            if (is_array($categoryInput) && isset($categoryInput['name'])) {
                $category = Category::create([
                    'company_uuid' => session('company'),
                    'owner_uuid'   => session('storefront_store'),
                    'owner_type'   => Utils::getMutationType('storefront:store'),
                    'name'         => data_get($categoryInput, 'name'),
                    'description'  => data_get($categoryInput, 'description'),
                    'tags'         => Utils::arrayFrom(data_get($categoryInput, 'tags', [])),
                    'for'          => 'storefront_product',
                ]);
            }

            // Set the cateogry for the product
            if ($category instanceof Category) {
                $input['category_uuid'] = $category->uuid;
            }
        }

        // Create product
        $product = Product::create($input);

        // Resolve addon categories
        if ($request->filled('addon_categories') && $request->isArray('addon_categories')) {
            $request->collect('addon_categories')->each(function ($addonCategoryInput) use ($product) {
                // Resolve existing addon category from ID
                if (Utils::isPublicId($addonCategoryInput)) {
                    $addonCategory = AddonCategory::where('public_id', $addonCategoryInput)->first();
                }

                // Create new addon cateogry with addons
                if (is_array($addonCategoryInput)) {
                    $addonCategory = AddonCategory::create([
                        'company_uuid' => session('company'),
                        'name'         => data_get($addonCategoryInput, 'name'),
                        'description'  => data_get($addonCategoryInput, 'description'),
                        'tags'         => Utils::arrayFrom(data_get($addonCategoryInput, 'tags', [])),
                    ]);

                    if (isset($addonCategoryInput['addons']) && is_array($addonCategoryInput['addons'])) {
                        collect($addonCategoryInput['addons'])->each(function ($addonInput) use ($addonCategory) {
                            if (is_string($addonInput)) {
                                return ProductAddon::create([
                                    'category_uuid'   => $addonCategory->uuid,
                                    'created_by_uuid' => session('user'),
                                    'name'            => $addonInput,
                                ]);
                            }

                            if (is_array($addonInput)) {
                                return ProductAddon::create([
                                    'category_uuid'   => $addonCategory->uuid,
                                    'created_by_uuid' => session('user'),
                                    'name'            => data_get($addonInput, 'name'),
                                    'price'           => Utils::numbersOnly(data_get($addonInput, 'price', 0)),
                                    'sale_price'      => Utils::numbersOnly(data_get($addonInput, 'sale_price', 0)),
                                    'is_on_sale'      => Utils::castBoolean(data_get($addonInput, 'is_on_sale', false)),
                                ]);
                            }
                        });
                    }
                }

                // Create product addon category
                if ($addonCategory instanceof AddonCategory) {
                    ProductAddonCategory::create([
                        'product_uuid'    => $product->uuid,
                        'category_uuid'   => $addonCategory->uuid,
                        'excluded_addons' => Utils::arrayFrom(data_get($addonCategoryInput, 'excluded_addons', [])),
                        'max_selectable'  => data_get($addonCategoryInput, 'max_selectable'),
                        'is_required'     => Utils::castBoolean(data_get($addonCategoryInput, 'is_required')),
                    ]);
                }
            });
        }

        // Resolve variants
        if ($request->filled('variants') && $request->isArray('variants')) {
            $request->collect('variants')->each(function ($variantInput) use ($product) {
                // Create new variants for product
                if (is_array($variantInput)) {
                    $productVariant = ProductVariant::create([
                        'product_uuid'   => $product->uuid,
                        'name'           => data_get($variantInput, 'name'),
                        'description'    => data_get($variantInput, 'description'),
                        'meta'           => data_get($variantInput, 'meta', []),
                        'is_required'    => Utils::castBoolean(data_get($variantInput, 'is_required')),
                        'is_multiselect' => Utils::castBoolean(data_get($variantInput, 'is_multiselect')),
                        'min'            => data_get($variantInput, 'min', 0),
                        'max'            => data_get($variantInput, 'max', 1),
                    ]);

                    if (isset($variantInput['options']) && is_array($variantInput['options'])) {
                        collect($variantInput['options'])->each(function ($variantOptionInput) use ($productVariant) {
                            if (is_string($variantOptionInput)) {
                                ProductVariantOption::create([
                                    'product_variant_uuid' => $productVariant->uuid,
                                    'name'                 => $variantOptionInput,
                                ]);
                            }

                            if (is_array($variantOptionInput)) {
                                ProductVariantOption::create([
                                    'product_variant_uuid' => $productVariant->uuid,
                                    'name'                 => data_get($variantOptionInput, 'name'),
                                    'description'          => data_get($variantOptionInput, 'description'),
                                    'additional_cost'      => Utils::numbersOnly(data_get($variantOptionInput, 'additional_cost', 0)),
                                ]);
                            }
                        });
                    }
                }
            });
        }

        return new StorefrontProduct($product);
    }

    /**
     * Updates a Storefront Product.
     *
     * @param string $id
     *
     * @return \Fleetbase\Storefront\Http\Resources\StorefrontProduct
     */
    public function update($id, UpdateProductRequest $request)
    {
        // Try to resolve the product by public_id or uuid (custom method)
        try {
            $product = Product::findRecordOrFail($id);
        } catch (ModelNotFoundException $exception) {
            return response()->json([
                'error' => 'Product not found.',
            ], 404);
        }

        // Validate input
        $input = $request->validated();

        // Sanitize/transform
        $input['tags']         = Utils::arrayFrom(data_get($input, 'tags', []));
        $input['youtube_urls'] = Utils::arrayFrom(data_get($input, 'youtube_urls', []));
        $input['price']        = Utils::numbersOnly(data_get($input, 'price', 0));
        $input['sale_price']   = Utils::numbersOnly(data_get($input, 'sale_price', 0));

        // Resolve or create category
        if (isset($input['category'])) {
            $categoryInput = $input['category'];
            $category      = null;

            if (Utils::isPublicId($categoryInput)) {
                $category = Category::where([
                    'company_uuid' => session('company'),
                    'owner_uuid'   => session('storefront_store'),
                    'public_id'    => $categoryInput,
                    'for'          => 'storefront_product',
                ])->first();
            }

            if (is_array($categoryInput) && isset($categoryInput['name'])) {
                $category = Category::create([
                    'company_uuid' => session('company'),
                    'owner_uuid'   => session('storefront_store'),
                    'owner_type'   => Utils::getMutationType('storefront:store'),
                    'name'         => data_get($categoryInput, 'name'),
                    'description'  => data_get($categoryInput, 'description'),
                    'tags'         => Utils::arrayFrom(data_get($categoryInput, 'tags', [])),
                    'for'          => 'storefront_product',
                ]);
            }

            if ($category instanceof Category) {
                $input['category_uuid'] = $category->uuid;
            }
        }

        // Update the product
        $product->update($input);

        // Update sync addon categories
        if ($request->filled('addon_categories') && $request->isArray('addon_categories')) {
            $addonCategories = $request->collect('addon_categories')->map(function ($addonCategory) {
                // Resolve category public_id to UUID
                if (isset($addonCategory['category'])) {
                    $category = AddonCategory::where('public_id', $addonCategory['category'])->first();
                    if ($category) {
                        $addonCategory['category_uuid'] = $category->uuid;
                    }
                }

                // Resolve ProductAddonCategory public_id if present (e.g. for updates)
                if (isset($addonCategory['id']) && Utils::isPublicId($addonCategory['id'])) {
                    $pac = ProductAddonCategory::where('public_id', $addonCategory['id'])->first();
                    if ($pac) {
                        $addonCategory['uuid'] = $pac->uuid;
                    }
                }

                return $addonCategory;
            })->toArray();

            $product->setAddonCategories($addonCategories);
        }

        // update sync product variants and options
        if ($request->filled('variants') && $request->isArray('variants')) {
            $variants = $request->collect('variants')->map(function ($variant) {
                // Resolve variant public_id to UUID
                if (isset($variant['id']) && Utils::isPublicId($variant['id'])) {
                    $variantModel = ProductVariant::where('public_id', $variant['id'])->first();
                    if ($variantModel) {
                        $variant['uuid'] = $variantModel->uuid;
                    }
                }

                // Resolve variant option IDs if using public_id
                if (isset($variant['options']) && is_array($variant['options'])) {
                    $variant['options'] = collect($variant['options'])->map(function ($option) {
                        if (isset($option['id']) && Utils::isPublicId($option['id'])) {
                            $optionModel = ProductVariantOption::where('public_id', $option['id'])->first();
                            if ($optionModel) {
                                $option['uuid'] = $optionModel->uuid;
                            }
                        }

                        return $option;
                    })->toArray();
                }

                return $variant;
            })->toArray();

            $product->setProductVariants($variants);
        }

        // Return resource
        return new StorefrontProduct($product);
    }

    /**
     * Query for Storefront Product resources.
     *
     * @return \Fleetbase\Http\Resources\ProductCollection
     */
    public function query(Request $request)
    {
        $results = Product::queryWithRequestCached($request, function (&$query, $request) {
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
     * @return DeletedResource
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
