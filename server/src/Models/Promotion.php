<?php

namespace Fleetbase\Storefront\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Casts\PolymorphicType;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Models\File;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Promotion extends StorefrontModel
{
    use HasUuid;
    use HasPublicId;
    use HasApiModelBehavior;
    use SoftDeletes;

    public const TYPE_PERCENTAGE    = 'percentage';
    public const TYPE_FIXED_AMOUNT  = 'fixed_amount';
    public const TYPE_FREE_DELIVERY = 'free_delivery';
    public const TYPE_BOGO          = 'bogo';

    public const TYPES = [self::TYPE_PERCENTAGE, self::TYPE_FIXED_AMOUNT, self::TYPE_FREE_DELIVERY, self::TYPE_BOGO];

    public const TRIGGER_AUTOMATIC = 'automatic';
    public const TRIGGER_CODE      = 'code';

    public const STATUS_DRAFT  = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_ENDED  = 'ended';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_PAUSED, self::STATUS_ENDED];

    protected $publicIdType = 'promotion';

    protected $table = 'promotions';

    protected $searchableColumns = ['name', 'description'];

    protected $fillable = [
        'company_uuid', 'created_by_uuid', 'owner_uuid', 'owner_type', 'name', 'description', 'status', 'trigger', 'type', 'value',
        'max_discount_amount', 'currency', 'min_subtotal', 'min_items', 'applies_to', 'bogo_config', 'first_order_only',
        'usage_limit', 'usage_limit_per_customer', 'budget_amount', 'stackable', 'priority', 'is_public', 'starts_at', 'ends_at',
        'schedule', 'timezone', 'image_uuid', 'translations', 'meta',
    ];

    protected $casts = [
        'owner_type'               => PolymorphicType::class,
        'value'                    => 'float',
        'max_discount_amount'      => 'integer',
        'min_subtotal'             => 'integer',
        'min_items'                => 'integer',
        'applies_to'               => Json::class,
        'bogo_config'              => Json::class,
        'first_order_only'         => 'boolean',
        'usage_limit'              => 'integer',
        'usage_limit_per_customer' => 'integer',
        'budget_amount'            => 'integer',
        'stackable'                => 'boolean',
        'priority'                 => 'integer',
        'is_public'                => 'boolean',
        'starts_at'                => 'datetime',
        'ends_at'                  => 'datetime',
        'schedule'                 => Json::class,
        'translations'             => Json::class,
        'meta'                     => Json::class,
    ];

    public function owner()
    {
        return $this->morphTo(__FUNCTION__, 'owner_type', 'owner_uuid');
    }

    public function codes()
    {
        return $this->hasMany(PromotionCode::class, 'promotion_uuid', 'uuid');
    }

    public function redemptions()
    {
        return $this->hasMany(PromotionRedemption::class, 'promotion_uuid', 'uuid');
    }

    public function image()
    {
        return $this->setConnection(config('fleetbase.connection.db'))->belongsTo(File::class, 'image_uuid', 'uuid');
    }

    public function setOwnerTypeAttribute($type)
    {
        $this->attributes['owner_type'] = Utils::getMutationType($type);
    }

    protected static function boot()
    {
        parent::boot();

        static::saving(fn (Promotion $promotion) => $promotion->inferOwnerType());
    }

    /**
     * The owner is a store or a network; infer which from the uuid when no type is given.
     */
    public function inferOwnerType(): void
    {
        if ($this->owner_uuid && empty($this->attributes['owner_type'])) {
            $this->attributes['owner_type'] = Store::where('uuid', $this->owner_uuid)->exists() ? Store::class : Network::class;
        }
    }

    /**
     * Whether the promotion can apply at the given moment: active, inside its date range and
     * inside one of its weekly schedule windows (if any).
     *
     * `schedule` is a list of windows like `{"days": [1,2,3,4,5], "start": "15:00", "end": "18:00"}`
     * where days are ISO weekdays (1 = Monday) in the promotion's timezone. A window whose end is
     * before its start runs past midnight.
     */
    public function isLiveAt(?Carbon $moment = null): bool
    {
        $moment ??= Carbon::now();

        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        if ($this->starts_at && $moment->lt($this->starts_at)) {
            return false;
        }

        if ($this->ends_at && $moment->gte($this->ends_at)) {
            return false;
        }

        $windows = array_values(array_filter((array) ($this->schedule ?? []), 'is_array'));
        if (empty($windows)) {
            return true;
        }

        $local   = $moment->copy()->setTimezone($this->timezone ?: config('app.timezone', 'UTC'));
        $minutes = $local->hour * 60 + $local->minute;

        foreach ($windows as $window) {
            $days  = array_map('intval', (array) ($window['days'] ?? [1, 2, 3, 4, 5, 6, 7]));
            $start = static::minutesOfDay($window['start'] ?? '00:00');
            $end   = static::minutesOfDay($window['end'] ?? '24:00');

            if ($start <= $end) {
                if (in_array($local->dayOfWeekIso, $days, true) && $minutes >= $start && $minutes < $end) {
                    return true;
                }
            } elseif (
                (in_array($local->dayOfWeekIso, $days, true) && $minutes >= $start)
                || (in_array($local->copy()->subDay()->dayOfWeekIso, $days, true) && $minutes < $end)
            ) {
                return true;
            }
        }

        return false;
    }

    protected static function minutesOfDay(string $time): int
    {
        [$hours, $minutes] = array_pad(array_map('intval', explode(':', $time)), 2, 0);

        return $hours * 60 + $minutes;
    }
}
