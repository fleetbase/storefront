<?php

namespace Fleetbase\Storefront\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicid;
use Fleetbase\Traits\HasUuid;

class PaymentMethod extends StorefrontModel
{
    use HasUuid;
    use HasPublicid;
    use HasApiModelBehavior;

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'payment_method';

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'payment_methods';

    /**
     * These attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = [];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['company_uuid', 'created_by_uuid', 'gateway_uuid', 'store_uuid', 'owner_uuid', 'owner_type', 'gateway_id', 'type', 'brand', 'last4', 'meta'];

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
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function owner()
    {
        return $this->setConnection(config('fleetbase.connection.db'))->morphTo(__FUNCTION__, 'owner_type', 'owner_uuid')->withoutGlobalScopes();
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
    public function gateway()
    {
        return $this->belongsTo(Gateway::class);
    }
}
