<?php

namespace Fleetbase\Storefront\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Casts\PolymorphicType;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasOptionsAttributes;
use Fleetbase\Traits\HasUuid;
use Illuminate\Support\Str;

class NotificationChannel extends StorefrontModel
{
    use HasUuid;
    use HasApiModelBehavior;
    use HasOptionsAttributes;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'notification_channels';

    /**
     * These attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = ['name', 'scheme'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['created_by_uuid', 'company_uuid', 'certificate_uuid', 'owner_uuid', 'owner_type', 'name', 'scheme', 'app_key', 'config', 'options'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        // 'config' => Json::class,
        'options'    => Json::class,
        'owner_type' => PolymorphicType::class,
    ];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = [];

    /** on boot generate key */
    public static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->app_key = 'noty_channel_' . md5(Str::random(14) . time());
        });
    }

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
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function certificate()
    {
        return $this->setConnection(config('fleetbase.connection.db'))->belongsTo(File::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function owner()
    {
        return $this->morphTo(__FUNCTION__, 'owner_type', 'owner_uuid');
    }

    /**
     * Sets the owner type.
     */
    public function setOwnerTypeAttribute($type)
    {
        $this->attributes['owner_type'] = Utils::getMutationType($type);
    }

    public function setConfigAttribute($config)
    {
        if (is_array($config) && isset($config['private_key_content'])) {
            $config['private_key_content'] = trim($config['private_key_content']);
        }

        $json = json_encode($config);
        // $json = str_replace("\\n", "", $json);

        $this->attributes['config'] = $json;
    }

    public function getConfigAttribute($config)
    {
        $config = json_decode($config, true);
        // $config = Json::decode($config);

        $sortedKeys = collect($config)->keys()->sort(function ($key) use ($config) {
            $item = Utils::get($config, $key);

            return Utils::isBooleanValue($item) ? 1 : 0;
        });
        $sortedConfig = [];

        foreach ($sortedKeys as $key) {
            $sortedConfig[$key] = $config[$key];
        }

        return (object) $sortedConfig;
    }

    public function getIsApnGatewayAttribute()
    {
        return $this->scheme === 'apn';
    }

    public function getIsFcmGatewayAttribute()
    {
        return $this->scheme === 'fcm';
    }
}
