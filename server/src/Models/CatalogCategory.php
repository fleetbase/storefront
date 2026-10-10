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
        ->withPivot(['price', 'is_available'])
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
    public function setProducts(array $products = [], array $overrides = []): CatalogCategory
    {
        // Ensure products relation is loaded if needed (optional).
        $this->loadMissing('products');

        // Fetch existing pivot records for this category
        $existingPivotRecords = CatalogProduct::where('catalog_category_uuid', $this->uuid)->get();
        $existingProductUuids = $existingPivotRecords->pluck('product_uuid')->toArray();

        // Normalize incoming product UUIDs, and collect the per-catalog overrides carried
        // either on the product entries (`price`, `is_available`) or in `$overrides` by uuid.
        $incomingProductUuids = [];
        $overridesByUuid      = [];
        foreach ($products as $item) {
            $uuid = is_string($item) ? $item : data_get($item, 'uuid');
            if (!is_string($uuid) || !Str::isUuid($uuid)) {
                continue;
            }

            $incomingProductUuids[] = $uuid;
            if (!is_string($item)) {
                $entry = array_merge((array) data_get($overrides, $uuid, []), array_filter([
                    'price'        => data_get($item, 'catalog_price', data_get($item, 'price_override')),
                    'is_available' => data_get($item, 'catalog_available'),
                ], fn ($value) => $value !== null));
                if ($entry !== []) {
                    $overridesByUuid[$uuid] = $entry;
                }
            } elseif (data_get($overrides, $uuid) !== null) {
                $overridesByUuid[$uuid] = (array) $overrides[$uuid];
            }
        }
        $incomingProductUuids = array_values(array_unique($incomingProductUuids));

        // 1) Remove pivot rows for products not in incoming list
        $toRemove = array_diff($existingProductUuids, $incomingProductUuids);
        if (!empty($toRemove)) {
            CatalogProduct::where('catalog_category_uuid', $this->uuid)
                ->whereIn('product_uuid', $toRemove)
                ->delete();
        }

        // 2) Create pivot rows for new products, carrying their overrides
        $toAdd = array_diff($incomingProductUuids, $existingProductUuids);
        foreach ($toAdd as $productUuid) {
            CatalogProduct::create(array_merge([
                'catalog_category_uuid' => $this->uuid,
                'product_uuid'          => $productUuid,
            ], static::normalizeOverride($overridesByUuid[$productUuid] ?? [])));
        }

        // 3) Apply overrides to products that stay; an entry with null values clears them
        if ($overrides !== [] || $overridesByUuid !== []) {
            foreach ($existingPivotRecords as $pivot) {
                if (!in_array($pivot->product_uuid, $incomingProductUuids, true) || !array_key_exists($pivot->product_uuid, $overridesByUuid)) {
                    continue;
                }

                CatalogProduct::where('catalog_category_uuid', $this->uuid)
                    ->where('product_uuid', $pivot->product_uuid)
                    ->update(static::normalizeOverride($overridesByUuid[$pivot->product_uuid]));
            }
        }

        return $this;
    }

    /**
     * The storable form of a per-catalog override: a price in minor units or null, and an
     * availability flag or null (inherit from the store).
     *
     * @return array{price: int|null, is_available: bool|null}
     */
    public static function normalizeOverride(array $override): array
    {
        $price     = $override['price'] ?? null;
        $available = $override['is_available'] ?? null;

        return [
            'price'        => $price === null || $price === '' ? null : (int) preg_replace('/[^0-9]/', '', (string) $price),
            'is_available' => $available === null || $available === '' ? null : filter_var($available, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * Overrides keyed by product uuid, for the console editor.
     *
     * @return array<string, array{price: int|null, is_available: bool|null}>
     */
    public function productOverrides(): array
    {
        $overrides = [];
        foreach ($this->products ?? [] as $product) {
            $pivot = $product->pivot ?? null;
            if ($pivot && ($pivot->price !== null || $pivot->is_available !== null)) {
                $overrides[$product->uuid] = ['price' => $pivot->price === null ? null : (int) $pivot->price, 'is_available' => $pivot->is_available];
            }
        }

        return $overrides;
    }
}
