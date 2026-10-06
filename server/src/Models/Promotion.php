<?php

namespace Fleetbase\Storefront\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Casts\PolymorphicType;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Models\Category;
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

    /**
     * Customer-facing availability: running now, running later (outside its weekly hours or
     * before its start date), over, or not offered at all (draft or paused).
     */
    public const AVAILABILITY_LIVE      = 'live';
    public const AVAILABILITY_SCHEDULED = 'scheduled';
    public const AVAILABILITY_ENDED     = 'ended';
    public const AVAILABILITY_INACTIVE  = 'inactive';

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

    /**
     * Customer-facing availability at the given moment.
     */
    public function availabilityAt(?Carbon $moment = null): string
    {
        $moment ??= Carbon::now();

        if ($this->status === self::STATUS_ENDED || ($this->ends_at && $moment->gte($this->ends_at))) {
            return self::AVAILABILITY_ENDED;
        }

        if ($this->status !== self::STATUS_ACTIVE) {
            return self::AVAILABILITY_INACTIVE;
        }

        return $this->isLiveAt($moment) ? self::AVAILABILITY_LIVE : self::AVAILABILITY_SCHEDULED;
    }

    /**
     * When a scheduled promotion next starts applying, looking up to a week ahead; null when it
     * is live now, has ended, is not active, or has no start inside that week.
     */
    public function nextLiveAt(?Carbon $moment = null): ?Carbon
    {
        $moment ??= Carbon::now();

        if ($this->availabilityAt($moment) !== self::AVAILABILITY_SCHEDULED) {
            return null;
        }

        $timezone = $this->timezone ?: config('app.timezone', 'UTC');
        $from     = ($this->starts_at && $this->starts_at->gt($moment) ? $this->starts_at : $moment)->copy()->setTimezone($timezone);
        $windows  = array_values(array_filter((array) ($this->schedule ?? []), 'is_array'));
        if (empty($windows)) {
            $windows = [['days' => [1, 2, 3, 4, 5, 6, 7], 'start' => '00:00', 'end' => '24:00']];
        }

        $next = null;
        for ($offset = -1; $offset <= 7; $offset++) {
            $day = $from->copy()->startOfDay()->addDays($offset);
            foreach ($windows as $window) {
                if (!in_array($day->dayOfWeekIso, array_map('intval', (array) ($window['days'] ?? [1, 2, 3, 4, 5, 6, 7])), true)) {
                    continue;
                }

                $start = static::minutesOfDay($window['start'] ?? '00:00');
                $end   = static::minutesOfDay($window['end'] ?? '24:00');
                $opens = $day->copy()->addMinutes($start);
                $shuts = $day->copy()->addMinutes($end <= $start ? $end + 1440 : $end);

                $candidate = $opens->lt($from) ? $from->copy() : $opens;
                if ($candidate->gte($shuts) || ($this->ends_at && $candidate->gte($this->ends_at))) {
                    continue;
                }

                if (!$next || $candidate->lt($next)) {
                    $next = $candidate;
                }
            }
        }

        return $next?->setTimezone(config('app.timezone', 'UTC'));
    }

    /**
     * The code a customer can type for a code-triggered promotion: an active code that is not
     * assigned to one customer, has not expired and can be used more than once. Batches of
     * single-use codes are handed out individually (for example by a campaign), so they are
     * never shown publicly.
     */
    public function shareableCode(?Carbon $moment = null): ?string
    {
        if ($this->trigger !== self::TRIGGER_CODE) {
            return null;
        }

        $moment ??= Carbon::now();
        $codes = $this->relationLoaded('codes') ? $this->codes : $this->codes()->get();

        $code = $codes
            ->filter(fn (PromotionCode $code) => $code->status === PromotionCode::STATUS_ACTIVE
                && empty($code->customer_uuid)
                && (!$code->expires_at || $moment->lt($code->expires_at))
                && ($code->usage_limit === null || $code->usage_limit > 1))
            ->sortByDesc('created_at')
            ->first();

        return $code?->code;
    }

    /**
     * The store or network running the promotion, as customers see it.
     *
     * @return array{type: string, id: ?string, name: ?string, logo_url: ?string}|null
     */
    public function ownerSummary(): ?array
    {
        $owner = $this->owner;
        if (!$owner instanceof Store && !$owner instanceof Network) {
            return null;
        }

        return [
            'type'     => $owner instanceof Store ? 'store' : 'network',
            'id'       => $owner->public_id,
            'name'     => $owner->name,
            'logo_url' => data_get($owner, 'logo.url'),
        ];
    }

    /**
     * `applies_to` with every product, category and store named by its public id, so the app
     * can link "Shop the offer" to them. Ids that no longer resolve are dropped.
     *
     * @return array<string, array<int, string>>
     */
    public function publicAppliesTo(): array
    {
        $appliesTo = (array) ($this->applies_to ?? []);
        $models    = [
            'products'           => Product::class,
            'exclude_products'   => Product::class,
            'stores'             => Store::class,
            'categories'         => Category::class,
            'exclude_categories' => Category::class,
        ];

        $public = [];
        foreach ($models as $key => $model) {
            $ids = array_values(array_filter((array) ($appliesTo[$key] ?? []), 'is_string'));
            if (empty($ids)) {
                continue;
            }

            $public[$key] = $model::query()
                ->where(fn ($query) => $query->whereIn('uuid', $ids)->orWhereIn('public_id', $ids))
                ->pluck('public_id')
                ->filter()
                ->values()
                ->all();
        }

        return $public;
    }

    protected static function minutesOfDay(string $time): int
    {
        [$hours, $minutes] = array_pad(array_map('intval', explode(':', $time)), 2, 0);

        return $hours * 60 + $minutes;
    }
}
