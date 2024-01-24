<?php

namespace Fleetbase\Storefront\Models;

use Fleetbase\FleetOps\Models\Place;
use Fleetbase\Models\User;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicid;
use Fleetbase\Traits\HasUuid;
use Grimzy\LaravelMysqlSpatial\Eloquent\SpatialTrait;

class StoreLocation extends StorefrontModel
{
    use HasUuid;
    use HasPublicid;
    use HasApiModelBehavior;
    use SpatialTrait;

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'store_location';

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'store_locations';

    /**
     * These attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = [];

    /**
     * The attributes that are spatial columns.
     *
     * @var array
     */
    protected $spatialFields = ['location'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'public_id',
        'store_uuid',
        'created_by_uuid',
        'place_uuid',
        'name',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = ['address'];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = ['place'];

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
    public function place()
    {
        return $this->setConnection(config('fleetbase.connection.db'))->belongsTo(Place::class, 'place_uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function hours()
    {
        return $this->hasMany(StoreHour::class);
    }

    /**
     * Get address for places.
     *
     * @return string
     */
    public function getAddressAttribute()
    {
        return $this->fromCache('place.address');
    }

    /**
     * @return \Grimzy\LaravelMysqlSpatial\Types\Point
     */
    public function getLocationAttribute()
    {
        return $this->place()->first()->location;
    }
}
