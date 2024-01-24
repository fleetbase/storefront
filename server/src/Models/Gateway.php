<?php

namespace Fleetbase\Storefront\Models;

use Fleetbase\Casts\Json;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Models\Company;
use Fleetbase\Models\File;
use Fleetbase\Models\User;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicid;
use Fleetbase\Traits\HasUuid;

class Gateway extends StorefrontModel
{
    use HasUuid;
    use HasPublicid;
    use HasApiModelBehavior;

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'gateway';

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'gateways';

    /**
     * These attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = ['name'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['public_id', 'company_uuid', 'created_by_uuid', 'logo_file_uuid', 'owner_uuid', 'owner_type', 'name', 'description', 'code', 'type', 'sandbox', 'meta', 'config', 'return_url', 'callback_url'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'sandbox' => 'boolean',
        'meta'    => Json::class,
        'config'  => Json::class,
    ];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = ['is_stripe_gateway', 'logo_url'];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = ['logoFile'];

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
        return $this->morphTo(__FUNCTION__, 'owner_type', 'owner_uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function logoFile()
    {
        return $this->setConnection(config('fleetbase.connection.db'))->belongsTo(File::class);
    }

    /**
     * @return string
     */
    public function getLogoUrlAttribute()
    {
        $default = $this->logoFile->url ?? null;
        $backup  = 'https://flb-assets.s3.ap-southeast-1.amazonaws.com/static/image-file-icon.png';

        return $default ?? $backup;
    }

    /**
     * Sets the owner type.
     */
    public function setOwnerTypeAttribute($type)
    {
        $this->attributes['owner_type'] = Utils::getMutationType($type);
    }

    public function getConfigAttribute($config)
    {
        $config     = Json::decode($config);
        $sortedKeys = collect($config)->keys()->sort(function ($key) use ($config) {
            return Utils::isBooleanValue($config[$key]) ? 1 : 0;
        });
        $sortedConfig = [];

        foreach ($sortedKeys as $key) {
            $sortedConfig[$key] = $config[$key];
        }

        return (object) $sortedConfig;
    }

    public function getIsStripeGatewayAttribute()
    {
        return $this->type === 'stripe';
    }

    public function getIsQpayGatewayAttribute()
    {
        return $this->type === 'qpay';
    }

    /**
     * Generates a new cash/cash on delivery gateway.
     */
    public static function cash($attributes = ['sandbox' => 0]): Gateway
    {
        return new static([
            'public_id'    => 'gateway_cash',
            'name'         => 'Cash',
            'code'         => 'cash',
            'type'         => 'cash',
            'sandbox'      => $attributes['sandbox'],
            'return_url'   => null,
            'callback_url' => null,
            ...$attributes,
        ]);
    }
}
