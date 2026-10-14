<?php

namespace Fleetbase\Storefront\Models;

use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasUuid;

class PromotionRedemption extends StorefrontModel
{
    use HasUuid;
    use HasApiModelBehavior;

    public const STATUS_RESERVED = 'reserved';
    public const STATUS_REDEEMED = 'redeemed';
    public const STATUS_RELEASED = 'released';

    /**
     * How long an uncaptured checkout holds its promotion before the use is released.
     */
    public const RESERVATION_MINUTES = 60;

    protected $table = 'promotion_redemptions';

    protected $fillable = ['company_uuid', 'promotion_uuid', 'promotion_code_uuid', 'customer_uuid', 'checkout_uuid', 'order_uuid', 'amount', 'currency', 'status', 'redeemed_at'];

    protected $casts = [
        'amount'      => 'integer',
        'redeemed_at' => 'datetime',
    ];

    public function promotion()
    {
        return $this->belongsTo(Promotion::class, 'promotion_uuid', 'uuid');
    }

    public function customer()
    {
        return $this->setConnection(config('fleetbase.connection.db'))->belongsTo(\Fleetbase\FleetOps\Models\Contact::class, 'customer_uuid', 'uuid');
    }

    public function order()
    {
        return $this->setConnection(config('fleetbase.connection.db'))->belongsTo(\Fleetbase\FleetOps\Models\Order::class, 'order_uuid', 'uuid');
    }

    public function code()
    {
        return $this->belongsTo(PromotionCode::class, 'promotion_code_uuid', 'uuid');
    }

    /**
     * Redemptions that count towards limits and budgets: redeemed ones, and reservations
     * that have not expired yet.
     */
    public function scopeCounting($query)
    {
        return $query->where(function ($query) {
            $query->where('status', self::STATUS_REDEEMED)
                ->orWhere(function ($query) {
                    $query->where('status', self::STATUS_RESERVED)
                        ->where('created_at', '>=', now()->subMinutes(self::RESERVATION_MINUTES));
                });
        });
    }
}
