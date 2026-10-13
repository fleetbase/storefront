<?php

namespace Fleetbase\Storefront\Http\Controllers;

use Fleetbase\FleetOps\Http\Resources\v1\Entity as EntityResource;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Models\Category;
use Fleetbase\Models\File;
use Fleetbase\Storefront\Imports\ProductsImport;
use Fleetbase\Storefront\Jobs\DownloadProductImageUrl;
use Fleetbase\Storefront\Models\FoodTruck;
use Fleetbase\Storefront\Models\InventoryMovement;
use Fleetbase\Storefront\Models\Product;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Models\StoreLocation;
use Fleetbase\Storefront\Support\BookingSlots;
use Fleetbase\Storefront\Support\Inventory;
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
     * List all activity options for current order.
     *
     * @return \Illuminate\Http\Response
     */
    /**
     * Counts the products page sidebar and tabs show for one store: per category, per status,
     * and the smart groups (out of stock, drafts, recommended, on sale, uncategorised).
     *
     * @return \Illuminate\Http\Response
     */
    public function summary(Request $request)
    {
        $storeUuid = $request->input('store_uuid') ?? $request->input('store');
        $products  = Product::where('company_uuid', session('company'));

        if ($storeUuid) {
            $products->where('store_uuid', $storeUuid);
        }

        $byCategory = (clone $products)->selectRaw('category_uuid, count(*) as total')->groupBy('category_uuid')->pluck('total', 'category_uuid');
        $byStatus   = (clone $products)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return response()->json([
            'total'         => (clone $products)->count(),
            'by_category'   => collect($byCategory)->filter(fn ($count, $key) => !empty($key))->all(),
            'uncategorized' => (int) ($byCategory[''] ?? $byCategory[null] ?? (clone $products)->whereNull('category_uuid')->count()),
            'by_status'     => $byStatus,
            'published'     => (int) ($byStatus['published'] ?? 0),
            'draft'         => (int) ($byStatus['draft'] ?? 0),
            'out_of_stock'  => (clone $products)->outOfStock()->count(),
            'low_stock'     => (clone $products)->lowStock()->count(),
            'on_sale'       => (clone $products)->where('is_on_sale', true)->count(),
            'recommended'   => (clone $products)->where('is_recommended', true)->count(),
        ]);
    }

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

    /**
     * Retrieves a list of product IDs from the request, finds the corresponding Product models, converts each to an Entity, and returns them as a JSON response.
     *
     * This function handles a request that includes an array of product UUIDs. It fetches the corresponding Product models from the database,
     * converts each Product to an Entity using the Product model's toEntity method, and collects these entities. The function finally returns
     * these entities as a JSON response, which can be useful for front-end applications or other services that need structured product data.
     *
     * @param Request $request the request object, expected to contain an array of product UUIDs under the 'products' key
     *
     * @return \Illuminate\Http\JsonResponse Returns a JSON response that contains an array of Entity objects, each representing a product.
     *                                       Each Entity object includes all relevant product details formatted and structured as specified in the Product model's toEntity method.
     *
     * @example
     * // Example usage:
     * POST /storefront/int/v1/products/create-entities
     * Body: { "products": ["uuid1", "uuid2"] }
     */
    public function createEntities(Request $request)
    {
        $productIds = $request->array('products');
        $products   = Product::whereIn('uuid', $productIds)->get();
        $entities   = [];

        foreach ($products as $product) {
            $entities[] = $product->createAsEntity();
        }

        return EntityResource::collection($entities);
    }

    /**
     * The product's stock at each of its store's locations and food trucks, with recent changes.
     */
    public function inventory(string $id)
    {
        $product = Product::where('company_uuid', session('company'))->where(fn ($query) => $query->where('public_id', $id)->orWhere('uuid', $id))->first();
        if (!$product) {
            return response()->error('Product not found', 404);
        }

        return response()->json(static::inventoryPayload($product));
    }

    /**
     * Turn stock tracking on or off and set the count, low stock threshold and backorders per
     * location: `{ track_inventory, locations: [{ location, quantity, low_stock_threshold, allow_backorder }], note }`.
     */
    public function updateInventory(Request $request, string $id)
    {
        $request->validate([
            'track_inventory'                 => ['sometimes', 'boolean'],
            'locations'                       => ['sometimes', 'array'],
            'locations.*.location'            => ['required', 'string'],
            'locations.*.quantity'            => ['sometimes', 'integer', 'min:-100000', 'max:10000000'],
            'locations.*.low_stock_threshold' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'locations.*.allow_backorder'     => ['sometimes', 'boolean'],
            'note'                            => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $product = Product::where('company_uuid', session('company'))->where(fn ($query) => $query->where('public_id', $id)->orWhere('uuid', $id))->first();
        if (!$product) {
            return response()->error('Product not found', 404);
        }

        if ($request->has('track_inventory')) {
            $product->update(['track_inventory' => $request->boolean('track_inventory')]);
        }

        $locations = collect(static::stockLocations($product))->keyBy('id');
        foreach ((array) $request->input('locations', []) as $row) {
            $location = $locations->get($row['location']);
            if (!$location) {
                return response()->error('Location ' . $row['location'] . ' does not belong to this store.', 422);
            }
            Inventory::set($product, $location['uuid'], $location['type'], Arr::only($row, ['quantity', 'low_stock_threshold', 'allow_backorder']), $request->input('note'));
        }

        return response()->json(static::inventoryPayload($product->fresh()));
    }

    /**
     * Free booking slots for a service on a day, for booking from the console.
     */
    public function slots(Request $request, string $id)
    {
        $request->validate(['date' => ['required', 'date_format:Y-m-d']]);

        $product = Product::where('company_uuid', session('company'))->where(fn ($query) => $query->where('public_id', $id)->orWhere('uuid', $id))->with(['hours', 'store'])->first();
        if (!$product) {
            return response()->error('Product not found', 404);
        }

        return response()->json([
            'date'    => $request->input('date'),
            'service' => BookingSlots::service($product),
            'slots'   => BookingSlots::forDay($product, $request->input('date')),
        ]);
    }

    protected static function inventoryPayload(Product $product): array
    {
        $records   = $product->inventories()->get()->keyBy('location_uuid');
        $movements = InventoryMovement::whereIn('inventory_uuid', $records->pluck('uuid'))->latest()->limit(20)->get();

        return [
            'track_inventory' => (bool) $product->track_inventory,
            'summary'         => Inventory::summary($product),
            'locations'       => collect(static::stockLocations($product))->map(function ($location) use ($records) {
                $record = $records->get($location['uuid']);

                return [
                    'location'            => $location['id'],
                    'type'                => $location['kind'],
                    'name'                => $location['name'],
                    'address'             => $location['address'],
                    'quantity'            => (int) ($record->quantity ?? 0),
                    'reserved'            => (int) ($record->reserved ?? 0),
                    'available'           => $record ? $record->available() : 0,
                    'low_stock_threshold' => $record->low_stock_threshold ?? null,
                    'allow_backorder'     => (bool) ($record->allow_backorder ?? false),
                    'low'                 => $record ? $record->isLow() : false,
                    'stocked'             => (bool) $record,
                ];
            })->values()->all(),
            'movements' => $movements->map(fn (InventoryMovement $movement) => [
                'type'           => $movement->type,
                'quantity'       => $movement->quantity,
                'quantity_after' => $movement->quantity_after,
                'location'       => optional($records->firstWhere('uuid', $movement->inventory_uuid))->location_uuid,
                'note'           => $movement->note,
                'at'             => optional($movement->created_at)->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * Where the product's store keeps stock: its locations and its food trucks.
     */
    protected static function stockLocations(Product $product): array
    {
        $locations = StoreLocation::where('store_uuid', $product->store_uuid)->with('place')->get()->map(fn ($location) => [
            'id'      => $location->public_id,
            'uuid'    => $location->uuid,
            'type'    => StoreLocation::class,
            'kind'    => 'store_location',
            'name'    => $location->name,
            'address' => data_get($location, 'place.address'),
        ]);
        $trucks = FoodTruck::where('store_uuid', $product->store_uuid)->get()->map(fn ($truck) => [
            'id'      => $truck->public_id,
            'uuid'    => $truck->uuid,
            'type'    => FoodTruck::class,
            'kind'    => 'food_truck',
            'name'    => $truck->name ?? data_get($truck, 'vehicle.display_name') ?? $truck->public_id,
            'address' => null,
        ]);

        return $locations->merge($trucks)->values()->all();
    }
}
