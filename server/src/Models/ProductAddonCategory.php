<?php

namespace Fleetbase\Storefront\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\HasApiModelBehavior;

class ProductAddonCategory extends StorefrontModel
{
    use HasUuid, HasApiModelBehavior;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'product_addon_categories';

    /**
     * These attributes that can be queried
     *
     * @var array
     */
    protected $searchableColumns = [];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'product_uuid',
        'category_uuid',
        'exluded_addons',
        'max_selectable'
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'exluded_addons' => Json::class
    ];

    /**
     * Dynamic attributes that are appended to object
     *
     * @var array
     */
    protected $appends = ['name'];

    /**
     * Dynamic attributes should be hidden
     *
     * @var array
     */
    protected $hidden = ['product'];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function category()
    {
        return $this->setConnection(config('fleetbase.connection.db'))->belongsTo(AddonCategory::class)->with(['addons']);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @var string
     */
    public function getNameAttribute()
    {
        return static::attributeFromCache($this, 'category.name');
    }

    public function getExcludedAddonsAttribute($excluded)
    {
        return Json::decode($excluded);
    }
}
