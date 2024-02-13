<?php

namespace Fleetbase\Storefront\Models;

use Fleetbase\Casts\Json;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Models\Category;
use Fleetbase\Models\Company;
use Fleetbase\Models\File;
use Fleetbase\Models\Invite;
use Fleetbase\Models\User;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasOptionsAttributes;
use Fleetbase\Traits\HasPublicid;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Illuminate\Support\Str;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class Network extends StorefrontModel
{
    use HasUuid;
    use HasPublicid;
    use HasApiModelBehavior;
    use HasOptionsAttributes;
    use HasSlug;
    use Searchable;

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'network';

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'networks';

    /**
     * These attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = ['name', 'description'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['created_by_uuid', 'company_uuid', 'logo_uuid', 'backdrop_uuid', 'key', 'online', 'name', 'description', 'website', 'facebook', 'instagram', 'twitter', 'email', 'phone', 'translations', 'tags', 'currency', 'timezone', 'pod_method', 'options', 'alertable', 'slug'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'options'      => Json::class,
        'translations' => Json::class,
        'alertable'    => Json::class,
        'tags'         => 'array',
        'online'       => 'boolean',
    ];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = ['logo_url', 'backdrop_url', 'stores_count'];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = ['logo', 'backdrop', 'files', 'media'];

    /**
     * @var SlugOptions
     */
    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug');
    }

    /** on boot generate key */
    public static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->key = 'network_' . md5(Str::random(14) . time());
        });
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
    public function company()
    {
        return $this->setConnection(config('fleetbase.connection.db'))->belongsTo(Company::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function logo()
    {
        return $this->setConnection(config('fleetbase.connection.db'))->belongsTo(File::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function backdrop()
    {
        return $this->setConnection(config('fleetbase.connection.db'))->belongsTo(File::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function files()
    {
        return $this->setConnection(config('fleetbase.connection.db'))->hasMany(File::class, 'subject_uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function media()
    {
        return $this->files()->where('type', 'storefront_network_media');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
     */
    public function stores()
    {
        return $this->belongsToMany(Store::class, 'network_stores', 'network_uuid', 'store_uuid')
            ->using(NetworkStore::class)
            ->withPivot('category_uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function notificationChannels()
    {
        return $this->hasMany(NotificationChannel::class, 'owner_uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function gateways()
    {
        return $this->hasMany(Gateway::class, 'owner_uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function invitations()
    {
        return $this->setConnection(config('fleetbase.connection.db'))->hasMany(Invite::class, 'subject_uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function categories()
    {
        return $this->setConnection(config('fleetbase.connection.db'))->hasMany(Category::class, 'owner_uuid')->where(['for' => 'network_category']);
    }

    /**
     * @var string
     */
    public function getLogoUrlAttribute()
    {
        // return static::attributeFromCache($this, 'logo.url', 'https://flb-assets.s3.ap-southeast-1.amazonaws.com/static/image-file-icon.png');
        return $this->logo->url ?? 'https://flb-assets.s3.ap-southeast-1.amazonaws.com/static/image-file-icon.png';
    }

    /**
     * @var string
     */
    public function getBackdropUrlAttribute()
    {
        // return static::attributeFromCache($this, 'backdrop.url', 'https://flb-assets.s3.ap-southeast-1.amazonaws.com/static/default-storefront-backdrop.png');
        return $this->backdrop->url ?? 'https://flb-assets.s3.ap-southeast-1.amazonaws.com/static/default-storefront-backdrop.png';
    }

    /**
     * @var int
     */
    public function getStoresCountAttribute()
    {
        return $this->stores()->count();
    }

    /**
     * Adds a new store to the network.
     */
    public function addStore(Store $store, ?Category $category = null): NetworkStore
    {
        return NetworkStore::updateOrCreate(
            [
                'network_uuid' => $this->uuid,
                'store_uuid'   => $store->uuid,
            ],
            [
                'network_uuid'  => $this->uuid,
                'store_uuid'    => $store->uuid,
                'category_uuid' => $category instanceof Category ? $category->uuid : null,
            ]
        );
    }

    /**
     *'Create a new network store category for this store record.
     *
     * @param File|string|null $icon
     * @param string           $iconColor
     */
    public function createCategory(string $name, string $description = '', ?array $meta = [], ?array $translations = [], ?Category $parent = null, $icon = null, $iconColor = '#000000'): Category
    {
        $iconFile = null;
        $iconName = null;

        if ($icon instanceof File) {
            $iconFile = $icon;
        }

        if (is_string($icon)) {
            $iconName = $icon;
        }

        return Category::create([
            'company_uuid'   => $this->company_uuid,
            'owner_uuid'     => $this->uuid,
            'owner_type'     => Utils::getMutationType('network:storefront'),
            'parent_uuid'    => $parent instanceof Category ? $parent->uuid : null,
            'icon_file_uuid' => $iconFile->uuid,
            'for'            => 'storefront_network',
            'name'           => $name,
            'description'    => $description,
            'translations'   => $translations,
            'meta'           => $meta,
            'icon'           => $iconName,
            'icon_color'     => $iconColor,
        ]);
    }

    /**
     * Create a new network store category if it doesn't already exists for this store record.
     *
     * @param File|string|null $icon
     * @param string           $iconColor
     */
    public function createCategoryStrict(string $name, string $description = '', ?array $meta = [], ?array $translations = [], ?Category $parent = null, $icon = null, $iconColor = '#000000'): Category
    {
        $existingCategory = Category::where(['company_uuid' => $this->company_uuid, 'owner_uuid' => $this->uuid, 'name' => $name])->first();

        if ($existingCategory) {
            return $existingCategory;
        }

        return $this->createCategory($name, $description, $meta, $translations, $parent, $icon, $iconColor);
    }
}
