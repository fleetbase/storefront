<?php

namespace Fleetbase\Storefront\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicid;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Catalog extends StorefrontModel
{
    use HasUuid;
    use HasPublicid;
    use HasApiModelBehavior;

    /**
     * The type of public ID to generate.
     *
     * @var string
     */
    protected $publicIdType = 'catalog';

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'catalogs';

    /**
     * Searchable columns.
     *
     * @var array
     */
    protected $searchableColumns = ['name'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'uuid',
        'store_uuid',
        'company_uuid',
        'created_by_uuid',
        'name',
        'description',
        'status',
        'meta',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'meta' => Json::class,
    ];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = [];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [];

    /**
     * Get the user who created the catalog.
     */
    public function createdBy(): BelongsTo
    {
        return $this
            ->setConnection(config('fleetbase.connection.db'))
            ->belongsTo(User::class, 'created_by_uuid', 'uuid');
    }

    /**
     * Get the company this catalog belongs to.
     */
    public function company(): BelongsTo
    {
        return $this
            ->setConnection(config('fleetbase.connection.db'))
            ->belongsTo(Company::class, 'company_uuid', 'uuid');
    }

    /**
     * Get the store that owns this food truck.
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_uuid', 'uuid');
    }

    /**
     * Hours the catalog is available to customers.
     */
    public function hours(): HasMany
    {
        return $this->hasMany(CatalogHour::class, 'catalog_uuid', 'uuid');
    }

    /**
     * Categories the catalog contains.
     */
    public function categories(): HasMany
    {
        return $this->setConnection(config('fleetbase.connection.db'))->hasMany(CatalogCategory::class, 'owner_uuid', 'uuid');
    }

    /**
     * Assignments of subjects the catalog has.
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(CatalogSubject::class, 'catalog_uuid', 'uuid');
    }

    /**
     * Subjects the catalog has been assigned too.
     */
    public function subjects(): MorphToMany
    {
        return $this->morphedByMany(
            StorefrontModel::class,
            'subject',
            'catalog_subjects',
            'catalog_uuid',
            'subject_uuid'
        );
    }

    /**
     * Update or create categories for this catalog based on the provided array.
     *
     * This method:
     *  1) Deletes categories that are no longer in the `$categories` list.
     *  2) Updates existing categories if their UUID is found in `$categories`.
     *  3) Creates new categories for entries without a UUID.
     *  4) Delegates product assignment to `setProducts()` on each category.
     *
     * @param array $categories An array of category data, each containing:
     *                          - 'uuid' (optional): if provided, indicates an existing category to update
     *                          - 'name': the category name
     *                          - 'products' (optional): an array of product UUIDs or objects to link
     *
     * @return $this
     */
    public function setCategories(array $categories = []): Catalog
    {
        // Ensure categories relation is loaded.
        $this->loadMissing('categories');

        // Grab all existing categories for this catalog.
        $existingCategories = $this->categories;

        // Extract incoming category UUIDs (if any).
        $incomingUuids = collect($categories)->pluck('uuid')->filter()->unique();

        // 1) Delete categories that are no longer present.
        //    (i.e., they exist in DB but their UUID is not in $incomingUuids)
        $existingCategories
            ->whereNotIn('uuid', $incomingUuids)
            ->each(function (CatalogCategory $cat) {
                $cat->delete(); // or $cat->forceDelete() if you don't want soft deletes
            });

        // 2) Loop through incoming categories array
        foreach ($categories as $categoryData) {
            $categoryUuid = data_get($categoryData, 'uuid');
            $products     = data_get($categoryData, 'products', []);
            $name         = data_get($categoryData, 'name');

            // Try to find an existing category
            $categoryRecord = null;
            if ($categoryUuid) {
                $categoryRecord = $existingCategories->firstWhere('uuid', $categoryUuid);
            }

            if ($categoryRecord) {
                // 2a) Update existing category
                $categoryRecord->update(['name' => $name]);
            } else {
                // 2b) Create a new category
                $categoryRecord = CatalogCategory::create([
                    'name'         => $name,
                    'company_uuid' => $this->company_uuid,
                    'owner_uuid'   => $this->uuid,
                    'owner_type'   => get_class($this),
                ]);
            }

            // 3) Update products for this category
            $categoryRecord->setProducts($products);
        }

        return $this;
    }
}
