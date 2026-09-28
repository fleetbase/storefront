<?php

namespace Fleetbase\Storefront\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class PromotionCode extends StorefrontModel
{
    use HasUuid;
    use HasPublicId;
    use HasApiModelBehavior;
    use SoftDeletes;

    public const STATUS_ACTIVE   = 'active';
    public const STATUS_DISABLED = 'disabled';

    protected $publicIdType = 'promo_code';

    protected $table = 'promotion_codes';

    protected $searchableColumns = ['code'];

    protected $fillable = ['company_uuid', 'promotion_uuid', 'code', 'status', 'usage_limit', 'customer_uuid', 'expires_at', 'meta'];

    protected $casts = [
        'usage_limit' => 'integer',
        'expires_at'  => 'datetime',
        'meta'        => Json::class,
    ];

    public function promotion()
    {
        return $this->belongsTo(Promotion::class, 'promotion_uuid', 'uuid');
    }

    public function setCodeAttribute($code)
    {
        $this->attributes['code'] = static::normalize($code);
    }

    /**
     * Codes are matched case-insensitively and without surrounding whitespace.
     */
    public static function normalize($code): string
    {
        return Str::upper(trim((string) $code));
    }

    /**
     * Generate a random, unambiguous code (no 0/O/1/I).
     */
    public static function generate(int $length = 8, string $prefix = ''): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code     = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return static::normalize($prefix . $code);
    }
}
