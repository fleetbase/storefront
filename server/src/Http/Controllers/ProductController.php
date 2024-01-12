<?php

namespace Fleetbase\Storefront\Http\Controllers;

use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Models\Category;
use Fleetbase\Models\File;
use Fleetbase\Storefront\Imports\ProductsImport;
use Fleetbase\Storefront\Jobs\DownloadProductImageUrl;
use Fleetbase\Storefront\Models\Product;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Support\Http;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class ProductController extends StorefrontController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'product';

    /**
     * Update a Product record.
     * This update method was overwritten because the ProductObserver
     * isn't firing on the `updated` callback.
     *
     * @return \Fleetbase\Storefront\Http\Resources\Product
     */
    public function updateRecord(Request $request, string $id)
    {
        try {
            $this->validateRequest($request);
            $record = $this->model->updateRecordFromRequest($request, $id, function (&$request, Product &$product) {
                $addonCategories = $request->array('product.addon_categories');
                $variants        = $request->array('product.variants');
                $files           = $request->array('product.files');

                // save addon categories
                $product->setAddonCategories($addonCategories);

                // save product variants
                $product->setProductVariants($variants);

                // set keys on files
                foreach ($files as $file) {
                    $fileRecord = File::where('uuid', $file['uuid'])->first();
                    $fileRecord->setKey($product);
                }
            });

            if (Http::isInternalRequest($request)) {
                $this->resource::wrap($this->resourceSingularlName);

                return new $this->resource($record);
            }

            return new $this->resource($record);
        } catch (\Exception $e) {
            return response()->error($e->getMessage());
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->error($e->getMessage());
        } catch (\Fleetbase\Exceptions\FleetbaseRequestValidationException $e) {
            return response()->error($e->getErrors());
        }
    }

    /**
     * List all activity options for current order.
     *
     * @return \Illuminate\Http\Response
     */
    public function processImports(Request $request)
    {
        $disk           = $request->input('disk', config('filesystems.default'));
        $store          = $request->input('store');
        $category       = $request->input('category');
        $files          = $request->input('files');
        $files          = File::whereIn('uuid', $files)->get();
        $validFileTypes = ['csv', 'tsv', 'xls', 'xlsx'];
        $imports        = collect();

        if ($category) {
            $category = Category::find($category);
        }

        if ($store) {
            $store = Store::find($store);
        }

        foreach ($files as $file) {
            // validate file type
            if (!Str::endsWith($file->path, $validFileTypes)) {
                return response()->error('Invalid file uploaded, must be one of the following: ' . implode(', ', $validFileTypes));
            }

            try {
                $data = Excel::toArray(new ProductsImport(), $file->path, $disk);
            } catch (\Exception $e) {
                return response()->error('Invalid file, unable to proccess.');
            }

            $data    = Arr::first($data);
            $imports = $imports->merge($data);
        }

        // track imported products
        $products = [];

        foreach ($imports as $row) {
            if (empty($row) || empty(array_values($row))) {
                continue;
            }

            // $importId = (string) Str::uuid();
            $name          = Utils::or($row, ['name', 'product_name', 'entry_name', 'entity_name', 'entity', 'item_name', 'item', 'service', 'service_name']);
            $description   = Utils::or($row, ['description', 'product_description', 'details', 'info', 'about', 'item_description']);
            $tags          = Utils::or($row, ['tags']);
            $sku           = Utils::or($row, ['sku', 'internal_id', 'stock_number']);
            $price         = Utils::or($row, ['price', 'cost', 'value']);
            $salePrice     = Utils::or($row, ['sale_price', 'sale_cost', 'sale_value']);
            $isService     = Utils::or($row, ['is_service'], false);
            $isBookable    = Utils::or($row, ['is_bookable', 'bookable'], false);
            $isOnSale      = Utils::or($row, ['on_sale', 'is_on_sale'], false);
            $isAvailable   = Utils::or($row, ['available', 'is_available'], true);
            $isRecommended = Utils::or($row, ['recommended', 'is_recommended'], false);
            $canPickup     = Utils::or($row, ['can_pickup', 'is_pickup', 'is_pickup_only'], false);
            $youtubeUrls   = Utils::or($row, ['youtube', 'youtube_urls', 'youtube_videos']);
            $images        = Utils::or($row, ['photos', 'images', 'image', 'photo', 'primary_image', 'product_image', 'thumbnail', 'photo1', 'image1']);

            $products[] = $product = Product::create(
                [
                    'company_uuid'    => $request->session()->get('company'),
                    'created_by_uuid' => $request->session()->get('user'),
                    'store_uuid'      => $store->uuid,
                    'name'            => Utils::unicodeDecode($name),
                    'description'     => Utils::unicodeDecode($description),
                    'sku'             => $sku,
                    'tags'            => explode(',', $tags),
                    'youtube_urls'    => explode(',', $youtubeUrls),
                    'price'           => $price,
                    'sale_price'      => $salePrice,
                    'currency'        => $store->currency,
                    'is_service'      => $isService,
                    'is_bookable'     => $isBookable,
                    'is_on_sale'      => $isOnSale,
                    'is_available'    => $isAvailable,
                    'is_recommended'  => $isRecommended,
                    'can_pickup'      => $canPickup,
                    'category_uuid'   => $category ? $category->uuid : null,
                    'status'          => 'published',
                ]
            );

            $images = explode(',', $images);

            foreach ($images as $imageUrl) {
                dispatch(new DownloadProductImageUrl($product, $imageUrl));
            }
        }

        return response()->json($products);
    }
}
