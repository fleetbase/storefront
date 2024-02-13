<?php

namespace Fleetbase\Storefront\Models;

use Fleetbase\Casts\Json;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\Models\Category;
use Fleetbase\Models\Company;
use Fleetbase\Models\File;
use Fleetbase\Models\User;
use Fleetbase\Support\Utils as FleetbaseUtils;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasOptionsAttributes;
use Fleetbase\Traits\HasPublicid;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class Store extends StorefrontModel
{
    use HasUuid;
    use HasPublicid;
    use HasApiModelBehavior;
    use HasOptionsAttributes;
    use HasMetaAttributes;
    use HasSlug;
    use Searchable;

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'store';

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'stores';

    /**
     * These attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = ['name', 'description', 'tags'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['created_by_uuid', 'company_uuid', 'logo_uuid', 'backdrop_uuid', 'key', 'online', 'name', 'description', 'translations', 'website', 'facebook', 'instagram', 'twitter', 'email', 'phone', 'tags', 'currency', 'meta', 'timezone', 'pod_method', 'options', 'alertable', 'slug'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'options'         => Json::class,
        'meta'            => Json::class,
        'translations'    => Json::class,
        'alertable'       => Json::class,
        'tags'            => 'array',
        'require_account' => 'boolean',
        'online'          => 'boolean',
    ];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = ['logo_url', 'backdrop_url', 'rating'];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = ['logo', 'backdrop', 'files'];

    /**
     * Attributes that is filterable on this model.
     *
     * @var array
     */
    protected $filterParams = ['network', 'without_category', 'category', 'category_uuid'];

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
            $model->key = 'store_' . md5(Str::random(14) . time());
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
        return $this->files()
            ->select(
                [
                    'id',
                    'uuid',
                    'public_id',
                    'original_filename',
                    'content_type',
                    'path',
                    'type',
                    'caption',
                    'created_at',
                    'updated_at',
                ]
            )->where('type', 'storefront_store_media');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function backdrop()
    {
        return $this->setConnection(config('fleetbase.connection.db'))->belongsTo(File::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function categories()
    {
        return $this->setConnection(config('fleetbase.connection.db'))->hasMany(Category::class, 'owner_uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function products()
    {
        return $this->hasMany(Product::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function checkouts()
    {
        return $this->hasMany(Checkout::class);
    }

    /**
     * @return int
     */
    public function getThisMonthCheckoutsCountAttribute()
    {
        return $this->checkouts()->where('created_at', '>=', Carbon::now()->subMonth())->count();
    }

    /**
     * @return int
     */
    public function get24hCheckoutsCountAttribute()
    {
        return $this->checkouts()->where('created_at', '>=', Carbon::now()->subHours(24))->count();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function hours()
    {
        return $this->hasMany(StoreHour::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function reviews()
    {
        return $this->hasMany(Review::class, 'subject_uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function votes()
    {
        return $this->hasMany(Vote::class, 'subject_uuid');
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
    public function locations()
    {
        return $this->hasMany(StoreLocation::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function networkStores()
    {
        return $this->hasMany(NetworkStore::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function networks()
    {
        return $this->belongsToMany(Network::class, 'network_stores', 'store_uuid', 'network_uuid')
            ->using(NetworkStore::class)
            ->withPivot('category_uuid');
    }

    /**
     * @var string
     */
    public function getLogoUrlAttribute()
    {
        return data_get($this, 'logo.url', 'https://flb-assets.s3.ap-southeast-1.amazonaws.com/static/image-file-icon.png');
    }

    /**
     * @var string
     */
    public function getBackdropUrlAttribute()
    {
        return data_get($this, 'backdrop.url', 'https://flb-assets.s3.ap-southeast-1.amazonaws.com/static/default-storefront-backdrop.png');
    }

    /**
     * @var float
     */
    public function getRatingAttribute()
    {
        return $this->reviews()->avg('rating') ?? 0;
    }

    /**
     * Retrieves the category of the store belonging to the specified network using the network id.
     *
     * @param string $id the ID of the network for which the category is to be retrieved
     *
     * @return \Fleetbase\Models\Category|null the category of the store in the given network, or null if the store does not belong to the network
     */
    public function getNetworkCategoryUsingId(?string $id)
    {
        if (is_null($id)) {
            return null;
        }

        try {
            $network = Network::where('uuid', $id)->orWhere('public_id', $id)->first();
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return null;
        } catch (\Exception $e) {
            return null;
        }

        return $this->getNetworkCategory($network);
    }

    /**
     * Retrieves the category of the store belonging to the specified network.
     *
     * @param Network $network the network for which the category is to be retrieved
     *
     * @return \Fleetbase\Models\Category|null the category of the store in the given network, or null if the store does not belong to the network
     */
    public function getNetworkCategory(Network $network)
    {
        // Find the relationship between this store and the given network
        $networkRelation = $this->networks()->where('networks.uuid', $network->uuid)->first();

        // Check if the relationship exists
        if ($networkRelation) {
            // Retrieve the category_uuid from the pivot table
            $categoryUuid = $networkRelation->pivot->category_uuid;

            // Assuming you have a Category model that's connected to the correct database
            return Category::find($categoryUuid);
        }

        // Return null if the store does not belong to the given network
        return null;
    }

    /**
     *'Create a new product category for this store record.
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

        return Category::create(
            [
                'company_uuid'   => $this->company_uuid,
                'owner_uuid'     => $this->uuid,
                'owner_type'     => FleetbaseUtils::getMutationType('storefront:store'),
                'parent_uuid'    => $parent instanceof Category ? $parent->uuid : null,
                'icon_file_uuid' => $iconFile instanceof File ? $iconFile->uuid : null,
                'for'            => 'storefront_product',
                'name'           => $name,
                'description'    => $description,
                'translations'   => $translations,
                'meta'           => $meta,
                'icon'           => $iconName,
                'icon_color'     => $iconColor,
            ]
        );
    }

    /**
     * Create a new product category if it doesn't already exists for this store record.
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

    /**
     * Creates a new product in the store.
     */
    public function createProduct(string $name, string $description, array $tags = [], ?Category $category = null, ?File $image = null, ?User $createdBy = null, string $sku = '', int $price = 0, string $status = 'available', array $options = []): Product
    {
        return Product::create(
            [
                'company_uuid'       => $this->company_uuid,
                'primary_image_uuid' => $image instanceof File ? $image->uuid : null,
                'created_by_uuid'    => $createdBy instanceof User ? $createdBy->uuid : null,
                'store_uuid'         => $this->uuid,
                'category_uuid'      => $category instanceof Category ? $category->uuid : null,
                'name'               => $name,
                'description'        => $description,
                'tags'               => $tags,
                'sku'                => $sku,
                'price'              => $price,
                'sale_price'         => isset($options['sale_price']) ? $options['sale_price'] : null,
                'currency'           => $this->currency,
                'is_service'         => isset($options['is_service']) ? $options['is_service'] : false,
                'is_bookable'        => isset($options['is_bookable']) ? $options['is_bookable'] : false,
                'is_available'       => isset($options['is_available']) ? $options['is_available'] : true,
                'is_on_sale'         => isset($options['is_on_sale']) ? $options['is_on_sale'] : false,
                'is_recommended'     => isset($options['is_recommended']) ? $options['is_recommended'] : false,
                'can_pickup'         => isset($options['can_pickup']) ? $options['can_pickup'] : false,
                'status'             => $status,
            ]
        );
    }

    public function createLocation($location, ?string $name = null, ?User $createdBy): ?StoreLocation
    {
        $place = Place::createFromMixed($location);

        if (empty($name)) {
            $name = $this->name . ' store location';
        }

        if ($place instanceof Place) {
            return StoreLocation::create(
                [
                    'store_uuid'      => $this->uuid,
                    'created_by_uuid' => $createdBy instanceof User ? $createdBy->uuid : null,
                    'place_uuid'      => $place->uuid,
                    'name'            => $name,
                ]
            );
        }

        return null;
    }
}
