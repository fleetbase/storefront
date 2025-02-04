<?php

namespace Fleetbase\Storefront\Models;

use Fleetbase\Models\Category;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

class CatalogCategory extends Category
{
    /**
     * The key to use in the payload responses (optional).
     */
    protected string $payloadKey = 'catalog_category';

    /**
     * Override the boot method to set "for" automatically.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function (Category $model) {
            $model->for = 'storefront_catalog';
        });
    }

    /**
     * The catalog the category belongs to.
     */
    public function owner(): MorphTo
    {
        return $this->setConnection(config('storefront.connection.db'))->morphTo(__FUNCTION__, 'owner_type', 'owner_uuid');
    }

    /**
     * The catalog the category belongs to.
     */
    public function catalog(): BelongsTo
    {
        return $this->setConnection(config('storefront.connection.db'))->belongsTo(Catalog::class, 'owner_uuid', 'uuid');
    }

    /**
     * Many-to-many relationship with Product via the pivot table.
     */
    public function products(): BelongsToMany
    {
        return $this->setConnection(config('storefront.connection.db'))
        ->belongsToMany(
            Product::class,
            'catalog_category_products',
            'catalog_category_uuid',
            'product_uuid'
        )
        ->using(CatalogProduct::class)
        ->withTimestamps()
        ->wherePivotNull('deleted_at');
    }

    /**
     * Update or create product relationships for this catalog category.
     *
     * This method:
     *  1) Removes pivot entries (CatalogProduct) for products no longer in `$products`.
     *  2) Creates pivot entries for new product IDs/UUIDs in `$products`.
     *
     * @param array $products an array of product identifiers (UUIDs) or objects containing a 'uuid' key
     *
     * @return $this
     */
    public function setProducts(array $products = []): CatalogCategory
    {
        // Ensure products relation is loaded if needed (optional).
        $this->loadMissing('products');

        // Fetch existing pivot records for this category
        $existingPivotRecords = CatalogProduct::where('catalog_category_uuid', $this->uuid)->get();
        $existingProductUuids = $existingPivotRecords->pluck('product_uuid')->toArray();

        // Normalize incoming product UUIDs
        $incomingProductUuids = collect($products)
            ->map(function ($item) {
                // If $item is simply a UUID string, use it
                if (is_string($item) && Str::isUuid($item)) {
                    return $item;
                }

                // If $item is an array/object, try to extract 'uuid'
                return data_get($item, 'uuid');
            })
            ->filter(fn ($uuid) => Str::isUuid($uuid)) // only valid UUIDs
            ->unique()
            ->values()
            ->toArray();

        // 1) Remove pivot rows for products not in incoming list
        $toRemove = array_diff($existingProductUuids, $incomingProductUuids);
        if (!empty($toRemove)) {
            CatalogProduct::where('catalog_category_uuid', $this->uuid)
                ->whereIn('product_uuid', $toRemove)
                ->delete();
        }

        // 2) Create pivot rows for new products
        $toAdd = array_diff($incomingProductUuids, $existingProductUuids);
        foreach ($toAdd as $productUuid) {
            CatalogProduct::create([
                'catalog_category_uuid' => $this->uuid,
                'product_uuid'          => $productUuid,
            ]);
        }

        return $this;
    }
}
