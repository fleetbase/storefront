<?php

namespace Fleetbase\Storefront\Models;

use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicid;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CatalogSubject extends StorefrontModel
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
    protected $publicIdType = 'catalog_subject';

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
