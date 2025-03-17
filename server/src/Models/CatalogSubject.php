<?php

namespace Fleetbase\Storefront\Models;

use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CatalogSubject extends MorphPivot
{
    use HasUuid;
    use HasApiModelBehavior;
    use SoftDeletes;

    /**
     * The default database connection to use.
     *
     * @var string
     */
    protected $connection = 'storefront';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'catalog_subjects';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'uuid',
        'catalog_uuid',
        'subject_type',
        'subject_uuid',
        'company_uuid',
        'created_by_uuid',
    ];

    /**
     * Set the configured connection.
     */
    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('storefront.connection.db'));
        parent::__construct($attributes);
    }

    /**
     * The "subject" of this pivot - can be a Store, FoodTruck, etc.
     */
    public function subject(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'subject_type', 'subject_uuid');
    }

    /**
     * Get the associated catalog.
     */
    public function catalog(): BelongsTo
    {
        return $this->belongsTo(Catalog::class, 'catalog_uuid', 'uuid');
    }
}
