<?php

namespace Fleetbase\Storefront\Models;

use Fleetbase\Models\Category;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

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
        return $this->morphTo(__FUNCTION__, 'owner_type', 'owner_uuid');
    }

    /**
     * The catalog the category belongs to.
     */
    public function catalog(): BelongsTo
    {
        return $this->belongsTo(Catalog::class, 'owner_uuid', 'uuid');
    }

    /**
     * Many-to-many relationship with Product via the pivot table.
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(
            Product::class,
            'catalog_category_products',
            'catalog_category_uuid',
            'product_uuid'
        )
        ->using(CatalogProduct::class)
        ->withTimestamps();
    }
}
