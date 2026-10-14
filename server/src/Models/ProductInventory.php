<?php

namespace Fleetbase\Storefront\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * How many of a product a store location (or food truck) has on hand, and how many are held for
 * checkouts being paid. Available to sell = quantity - reserved.
 */
class ProductInventory extends StorefrontModel
{
    use HasUuid;
    use HasPublicId;
    use HasApiModelBehavior;
    use SoftDeletes;

    protected $table = 'product_inventories';

    protected $publicIdType = 'inventory';

    protected $fillable = ['company_uuid', 'product_uuid', 'location_uuid', 'location_type', 'quantity', 'reserved', 'low_stock_threshold', 'allow_backorder', 'meta'];

    protected $casts = [
        'quantity'            => 'integer',
        'reserved'            => 'integer',
        'low_stock_threshold' => 'integer',
        'allow_backorder'     => 'boolean',
        'meta'                => Json::class,
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_uuid', 'uuid');
    }

    public function location()
    {
        return $this->morphTo(__FUNCTION__, 'location_type', 'location_uuid');
    }

    public function movements()
    {
        return $this->hasMany(InventoryMovement::class, 'inventory_uuid', 'uuid');
    }

    public function available(): int
    {
        return max(0, (int) $this->quantity - (int) $this->reserved);
    }

    public function canSell(int $quantity): bool
    {
        return $this->allow_backorder || $this->available() >= $quantity;
    }

    public function isLow(): bool
    {
        return $this->low_stock_threshold !== null && $this->available() > 0 && $this->available() <= $this->low_stock_threshold;
    }
}
