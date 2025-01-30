<?php

namespace Fleetbase\Storefront\Models;

use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicid;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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
     * Polymorphic relationship to the CatalogSubject pivot.
     * This allows you to retrieve all catalogs assigned to this food truck.
     */
    public function catalogAssignments(): MorphMany
    {
        return $this->morphMany(CatalogSubject::class, 'subject', 'subject_type', 'subject_uuid');
    }

    /**
     * Shortcut to get the actual Catalog models assigned via pivot.
     */
    public function catalogs()
    {
        return $this
            ->catalogAssignments()
            ->with('catalog')
            ->get()
            ->pluck('catalog')
            ->filter();
    }
}
