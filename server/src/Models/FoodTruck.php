<?php

namespace Fleetbase\Storefront\Models;

use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Zone;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicid;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class FoodTruck extends StorefrontModel
{
    use HasUuid;
    use HasPublicid;
    use HasApiModelBehavior;
    use SoftDeletes;

    /**
     * The type of public ID to generate.
     *
     * @var string
     */
    protected $publicIdType = 'food_truck';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'food_trucks';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'uuid',
        'vehicle_uuid',
        'store_uuid',
        'company_uuid',
        'created_by_uuid',
        'service_area_uuid',
        'zone_uuid',
        'status',
    ];

    /**
     * Get the store that owns this food truck.
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_uuid', 'uuid');
    }

    /**
     * Get the vehicle that is the food truck.
     */
    public function vehicle(): BelongsTo
    {
        return $this->setConnection(config('fleetbase.connection.db'))->belongsTo(Vehicle::class, 'vehicle_uuid', 'uuid');
    }

    /**
     * Get the service area the food truck is assigned to.
     */
    public function serviceArea(): BelongsTo
    {
        return $this->setConnection(config('fleetbase.connection.db'))->belongsTo(ServiceArea::class, 'service_area_uuid', 'uuid');
    }

    /**
     * Get the zone the food truck is assigned to.
     */
    public function zone(): BelongsTo
    {
        return $this->setConnection(config('fleetbase.connection.db'))->belongsTo(Zone::class, 'zone_uuid', 'uuid');
    }

    /**
     * Polymorphic relationship to the CatalogSubject pivot.
     * This allows you to retrieve all catalogs assigned to this food truck.
     */
    public function catalogAssignments(): MorphMany
    {
        return $this->morphMany(CatalogSubject::class, 'subject', 'subject_type', 'subject_uuid');
    }

    /**
     * The catalogs assigned to this food truck.
     */
    public function catalogs(): MorphToMany
    {
        return $this->morphToMany(
            Catalog::class,
            'subject',
            'catalog_subjects',
            'subject_uuid',
            'catalog_uuid',
        )->using(CatalogSubject::class)
        ->withTimestamps()
        ->wherePivotNull('deleted_at');
    }

    /**
     * Sync the given catalogs to this food truck by creating or deleting pivot rows
     * in catalog_subjects. If pivot rows are polymorphic, also filter by subject_type.
     *
     * @return $this
     */
    public function setCatalogs(array $catalogs = []): FoodTruck
    {
        // Ensure 'catalogs' relationship is loaded
        $this->loadMissing('catalogs');

        // Collect existing catalogs
        $existingCatalogs = $this->catalogs;

        // Extract incoming UUIDs
        $incomingUuids = collect($catalogs)
        ->map(function ($item) {
            // If it's already a Catalog model, use its uuid
            if ($item instanceof Catalog) {
                return $item->uuid;
            }
            // If it's a string UUID, return as is
            if (is_string($item) && Str::isUuid($item)) {
                return $item;
            }

            // If it's an array/object with a 'uuid' key, return that
            return data_get($item, 'uuid');
        })
        ->filter(fn ($uuid) => Str::isUuid($uuid))
        ->unique()
        ->values();

        // Remove pivot rows for catalogs not in incoming list
        $existingCatalogs
            ->whereNotIn('uuid', $incomingUuids)
            ->each(function (Catalog $catalog) {
                CatalogSubject::where([
                    'catalog_uuid' => $catalog->uuid,
                    'subject_uuid' => $this->uuid,
                    'subject_type' => get_class($this),
                ])->delete();
            });

        // Create or restore pivot rows for each incoming
        foreach ($incomingUuids as $catalogUuid) {
            CatalogSubject::firstOrCreate(
                [
                    'catalog_uuid'  => $catalogUuid,
                    'subject_uuid'  => $this->uuid,
                    'subject_type'  => get_class($this),
                ],
                [
                    'company_uuid'   => $this->company_uuid,
                    'created_by_uuid'=> session('user'),
                ]
            );
        }

        return $this;
    }
}
