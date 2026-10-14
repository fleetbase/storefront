<?php

namespace Fleetbase\Storefront\Models;

use Fleetbase\Traits\HasUuid;

/**
 * Stock held for a checkout while the customer pays: `held` until the order is placed
 * (`committed`) or the checkout is abandoned or expires (`released`).
 */
class InventoryReservation extends StorefrontModel
{
    use HasUuid;

    public const STATUS_HELD      = 'held';
    public const STATUS_COMMITTED = 'committed';
    public const STATUS_RELEASED  = 'released';

    protected $table = 'inventory_reservations';

    protected $fillable = ['company_uuid', 'inventory_uuid', 'checkout_uuid', 'order_uuid', 'cart_item_id', 'quantity', 'expires_at', 'status'];

    protected $casts = [
        'quantity'   => 'integer',
        'expires_at' => 'datetime',
    ];

    public function inventory()
    {
        return $this->belongsTo(ProductInventory::class, 'inventory_uuid', 'uuid');
    }
}
