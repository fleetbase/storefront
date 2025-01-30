<?php

namespace Fleetbase\Storefront\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicid;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'name',
        'description',
        'status',
        'meta',
        'company_uuid',
        'created_by_uuid',
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

    public function hours()
    {
        return $this->hasMany(CatalogHour::class, 'catalog_uuid', 'uuid');
    }
}
