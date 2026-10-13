<?php

namespace Fleetbase\Storefront\Models;

use Fleetbase\Traits\HasUuid;

/**
 * One change to a product's stock at a location, with why it happened: an adjustment or restock
 * by staff, a reservation or its release, a sale, a cancellation or an order edit.
 */
class InventoryMovement extends StorefrontModel
{
    use HasUuid;

    public const TYPE_ADJUSTMENT   = 'adjustment';
    public const TYPE_RESTOCK      = 'restock';
    public const TYPE_RESERVATION  = 'reservation';
    public const TYPE_RELEASE      = 'release';
    public const TYPE_SALE         = 'sale';
    public const TYPE_CANCELLATION = 'cancellation';
    public const TYPE_EDIT         = 'edit';

    protected $table = 'inventory_movements';

    protected $fillable = ['company_uuid', 'inventory_uuid', 'type', 'quantity', 'quantity_after', 'reference_uuid', 'reference_type', 'actor_uuid', 'note'];

    protected $casts = [
        'quantity'       => 'integer',
        'quantity_after' => 'integer',
    ];

    public function inventory()
    {
        return $this->belongsTo(ProductInventory::class, 'inventory_uuid', 'uuid');
    }
}
