<?php

namespace Fleetbase\Storefront\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Casts\PolymorphicType;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A rule based audience of a store's or network's customers.
 *
 * See Promotions\SegmentResolver for the supported rules.
 */
class CustomerSegment extends StorefrontModel
{
    use HasUuid;
    use HasPublicId;
    use HasApiModelBehavior;
    use SoftDeletes;

    protected $publicIdType = 'segment';

    protected $table = 'customer_segments';

    protected $searchableColumns = ['name', 'description'];

    protected $fillable = ['company_uuid', 'created_by_uuid', 'owner_uuid', 'owner_type', 'name', 'description', 'rules', 'meta'];

    protected $casts = [
        'owner_type' => PolymorphicType::class,
        'rules'      => Json::class,
        'meta'       => Json::class,
    ];

    public function owner()
    {
        return $this->morphTo(__FUNCTION__, 'owner_type', 'owner_uuid');
    }

    public function setOwnerTypeAttribute($type)
    {
        $this->attributes['owner_type'] = Utils::getMutationType($type);
    }
}
