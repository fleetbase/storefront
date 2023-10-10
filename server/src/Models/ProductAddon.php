<?php

namespace Fleetbase\Storefront\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Category;
use Fleetbase\Models\User;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicid;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class ProductAddon extends StorefrontModel
{
    use HasUuid, HasPublicid, HasApiModelBehavior, HasSlug;

    /**
     * The type of public Id to generate
     *
     * @var string
     */
    protected $publicIdType = 'addon';

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'product_addons';

    /**
     * These attributes that can be queried
     *
     * @var array
     */
    protected $searchableColumns = ['name', 'description'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'public_id',
        'created_by_uuid',
        'category_uuid',
        'name',
        'description',
        'translations',
        'price',
        'sale_price',
        'is_on_sale',
        'slug'
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'is_on_sale' => 'boolean',
        'translations' => Json::class
    ];

    /**
     * Dynamic attributes that are appended to object
     *
     * @var array
     */
    protected $appends = [];

    /**
     * @var \Spatie\Sluggable\SlugOptions
     */
    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function createdBy()
    {
        return $this->setConnection(config('fleetbase.connection.db'))->belongsTo(User::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function category()
    {
        return $this->setConnection(config('fleetbase.connection.db'))->belongsTo(Category::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Set the price as only numbers
     *
     * @void
     */
    public function setPriceAttribute($value)
    {
        $this->attributes['price'] = Utils::numbersOnly($value);
    }

    /**
     * Set the sale price as only numbers
     *
     * @void
     */
    public function setSalePriceAttribute($value)
    {
        $this->attributes['sale_price'] = Utils::numbersOnly($value);
    }
}
