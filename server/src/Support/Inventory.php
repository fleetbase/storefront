<?php

namespace Fleetbase\Storefront\Support;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Storefront\Models\Cart;
use Fleetbase\Storefront\Models\Checkout;
use Fleetbase\Storefront\Models\FoodTruck;
use Fleetbase\Storefront\Models\InventoryMovement;
use Fleetbase\Storefront\Models\InventoryReservation;
use Fleetbase\Storefront\Models\Product;
use Fleetbase\Storefront\Models\ProductInventory;
use Fleetbase\Storefront\Models\StoreLocation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Simple stock for products that track inventory: a quantity on hand per store location or food
 * truck. A checkout holds what it sells while the customer pays; placing the order takes it; an
 * abandoned checkout gives it back after a while; cancelling or editing an order puts it back.
 *
 * Products that do not track inventory are never limited, and nothing here touches them.
 */
class Inventory
{
    /** How long a checkout holds stock before giving it back. */
    public const RESERVATION_MINUTES = 15;

    protected static ?bool $enabled = null;

    /**
     * Whether the stock tables exist; until the migration has run nothing here does anything.
     */
    public static function enabled(): bool
    {
        if (static::$enabled === null) {
            try {
                static::$enabled = Schema::connection((new ProductInventory())->getConnectionName())->hasTable('inventory_reservations');
            } catch (\Throwable $e) {
                static::$enabled = false;
            }
        }

        return static::$enabled;
    }

    /** Forget whether the tables exist (tests create them). */
    public static function flushEnabled(): void
    {
        static::$enabled = null;
    }

    /**
     * Hold stock for every tracked line of the checkout's cart. All or nothing: when a line is
     * short nothing is held and the short lines are returned.
     *
     * @return array<int, array{id: ?string, product_id: ?string, name: ?string, available: int}> the lines that cannot be sold
     */
    public static function reserve(Checkout $checkout, ?Cart $cart = null): array
    {
        if (!static::enabled()) {
            return [];
        }

        $cart ??= $checkout->cart_uuid ? Cart::where('uuid', $checkout->cart_uuid)->first() : null;
        $lines = static::trackedLines($cart?->items ?? []);
        if ($lines->isEmpty()) {
            return [];
        }

        return DB::connection((new ProductInventory())->getConnectionName())->transaction(function () use ($checkout, $lines) {
            $shortages = [];
            $holds     = [];

            foreach ($lines as $line) {
                $inventory = $line['inventory'] ? ProductInventory::where('uuid', $line['inventory']->uuid)->lockForUpdate()->first() : null;
                if (!$inventory || !$inventory->canSell($line['quantity'])) {
                    $shortages[] = ['id' => $line['id'], 'product_id' => $line['product']->public_id, 'name' => $line['name'] ?? $line['product']->name, 'available' => $inventory?->available() ?? 0];
                    continue;
                }
                $holds[] = [$inventory, $line];
            }

            if ($shortages) {
                return $shortages;
            }

            foreach ($holds as [$inventory, $line]) {
                $inventory->increment('reserved', $line['quantity']);
                InventoryReservation::create([
                    'company_uuid'   => $inventory->company_uuid,
                    'inventory_uuid' => $inventory->uuid,
                    'checkout_uuid'  => $checkout->uuid,
                    'cart_item_id'   => $line['id'],
                    'quantity'       => $line['quantity'],
                    'expires_at'     => now()->addMinutes(static::RESERVATION_MINUTES),
                    'status'         => InventoryReservation::STATUS_HELD,
                ]);
                static::record($inventory, InventoryMovement::TYPE_RESERVATION, -$line['quantity'], $checkout);
            }

            return [];
        });
    }

    /**
     * The order was placed: what the checkout held is sold. A hold that expired while the customer
     * paid is sold all the same, since the customer has paid for it.
     */
    public static function commit(Checkout $checkout, Order $order): void
    {
        if (!static::enabled()) {
            return;
        }

        $reservations = InventoryReservation::where('checkout_uuid', $checkout->uuid)
            ->whereIn('status', [InventoryReservation::STATUS_HELD, InventoryReservation::STATUS_RELEASED])
            ->get();
        if ($reservations->isEmpty()) {
            return;
        }

        DB::connection((new ProductInventory())->getConnectionName())->transaction(function () use ($reservations, $order) {
            foreach ($reservations as $reservation) {
                $inventory = ProductInventory::where('uuid', $reservation->inventory_uuid)->lockForUpdate()->first();
                if (!$inventory) {
                    continue;
                }

                $wasHeld = $reservation->status === InventoryReservation::STATUS_HELD;
                $inventory->quantity -= $reservation->quantity;
                if ($wasHeld) {
                    $inventory->reserved = max(0, $inventory->reserved - $reservation->quantity);
                }
                $inventory->save();

                $reservation->update(['status' => InventoryReservation::STATUS_COMMITTED, 'order_uuid' => $order->uuid]);
                static::record($inventory, InventoryMovement::TYPE_SALE, -$reservation->quantity, $order);
            }
        });
    }

    /**
     * Give back what a checkout holds (abandoned, re-priced or failed).
     */
    public static function release(Checkout $checkout): int
    {
        if (!static::enabled()) {
            return 0;
        }

        return static::releaseReservations(InventoryReservation::where('checkout_uuid', $checkout->uuid)->where('status', InventoryReservation::STATUS_HELD)->get());
    }

    /**
     * Give back holds whose checkouts were never paid.
     */
    public static function releaseExpired(): int
    {
        if (!static::enabled()) {
            return 0;
        }

        return static::releaseReservations(InventoryReservation::where('status', InventoryReservation::STATUS_HELD)->where('expires_at', '<', now())->limit(500)->get());
    }

    /**
     * A canceled order's items go back on the shelf. Done once per order.
     */
    public static function restoreForOrder(Order $order): int
    {
        if (!static::enabled() || $order->getMeta('inventory_restored_at')) {
            return 0;
        }

        $sold = InventoryMovement::where('reference_uuid', $order->uuid)
            ->whereIn('type', [InventoryMovement::TYPE_SALE, InventoryMovement::TYPE_EDIT])
            ->get()
            ->groupBy('inventory_uuid')
            ->map(fn (Collection $movements) => -$movements->sum('quantity'))
            ->filter(fn ($quantity) => $quantity > 0);

        foreach ($sold as $inventoryUuid => $quantity) {
            $inventory = ProductInventory::where('uuid', $inventoryUuid)->first();
            if ($inventory) {
                $inventory->increment('quantity', $quantity);
                static::record($inventory->refresh(), InventoryMovement::TYPE_CANCELLATION, $quantity, $order);
            }
        }

        if ($sold->isNotEmpty()) {
            $order->updateMeta('inventory_restored_at', now()->toIso8601String());
        }

        return $sold->count();
    }

    /**
     * An edit changed how many of each product the order has: removed items go back, added ones
     * are taken (from where the order's stock came, else the product's first stocked location).
     *
     * @param array<string, int> $deltas product public id => change in quantity
     */
    public static function applyEdit(Order $order, array $deltas): void
    {
        if (!static::enabled()) {
            return;
        }

        foreach ($deltas as $productId => $delta) {
            if ($delta === 0) {
                continue;
            }

            $product = Product::where('public_id', $productId)->first();
            if (!$product || !$product->track_inventory) {
                continue;
            }

            $inventory = static::inventoryForOrderProduct($order, $product);
            if (!$inventory) {
                continue;
            }

            $inventory->quantity -= $delta;
            $inventory->save();
            static::record($inventory, InventoryMovement::TYPE_EDIT, -$delta, $order);
        }
    }

    /**
     * Set stock by hand from the console: `quantity` sets the count on hand (recorded as the
     * difference), the rest are settings.
     */
    public static function set(Product $product, string $locationUuid, string $locationType, array $values, ?string $note = null): ProductInventory
    {
        $inventory = ProductInventory::firstOrNew(['product_uuid' => $product->uuid, 'location_uuid' => $locationUuid]);
        $inventory->fill([
            'company_uuid'  => $product->company_uuid,
            'location_type' => $locationType,
        ]);

        foreach (['low_stock_threshold', 'allow_backorder'] as $setting) {
            if (array_key_exists($setting, $values)) {
                $inventory->{$setting} = $setting === 'allow_backorder' ? (bool) $values[$setting] : ($values[$setting] === null || $values[$setting] === '' ? null : max(0, (int) $values[$setting]));
            }
        }

        $before = (int) $inventory->quantity;
        if (array_key_exists('quantity', $values)) {
            $inventory->quantity = (int) $values['quantity'];
        }
        $inventory->save();

        $change = (int) $inventory->quantity - $before;
        if ($change !== 0) {
            static::record($inventory, $change > 0 ? InventoryMovement::TYPE_RESTOCK : InventoryMovement::TYPE_ADJUSTMENT, $change, null, $note);
        }

        return $inventory;
    }

    /**
     * A product's stock as clients show it.
     */
    public static function summary(Product $product): array
    {
        if (!$product->track_inventory || !static::enabled()) {
            return ['tracked' => false, 'in_stock' => true, 'available_total' => null, 'low' => false, 'locations' => []];
        }

        $inventories = $product->relationLoaded('inventories') ? $product->inventories : $product->inventories()->get();
        $locations   = $inventories->map(fn (ProductInventory $inventory) => [
            'id'                  => $inventory->public_id,
            'location'            => static::locationPublicId($inventory),
            'location_type'       => static::locationKind($inventory),
            'quantity'            => (int) $inventory->quantity,
            'reserved'            => (int) $inventory->reserved,
            'available'           => $inventory->available(),
            'low_stock_threshold' => $inventory->low_stock_threshold,
            'allow_backorder'     => (bool) $inventory->allow_backorder,
            'low'                 => $inventory->isLow(),
        ])->values();

        return [
            'tracked'         => true,
            'in_stock'        => $locations->contains(fn ($location) => $location['available'] > 0 || $location['allow_backorder']),
            'available_total' => (int) $locations->sum('available'),
            'low'             => $locations->contains(fn ($location) => $location['low']),
            'locations'       => $locations->all(),
        ];
    }

    /**
     * The cart lines that sell tracked products, each with the stock record it sells from.
     */
    protected static function trackedLines(iterable $items): Collection
    {
        $items    = collect($items);
        $products = Product::whereIn('public_id', $items->pluck('product_id')->filter()->unique()->values()->all())->get()->keyBy('public_id');

        return $items
            ->map(function ($item) use ($products) {
                $product = $products->get(data_get($item, 'product_id'));
                if (!$product || !$product->track_inventory) {
                    return null;
                }

                return [
                    'id'        => data_get($item, 'id'),
                    'name'      => data_get($item, 'name'),
                    'product'   => $product,
                    'quantity'  => max(1, (int) data_get($item, 'quantity', 1)),
                    'inventory' => static::inventoryFor($product, data_get($item, 'food_truck_id') ?? data_get($item, 'store_location_id')),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * The stock record a line sells from: the line's store location or food truck, else the
     * product's only stocked location.
     */
    public static function inventoryFor(Product $product, ?string $locationId): ?ProductInventory
    {
        $locationUuid = null;
        if ($locationId && Str::startsWith($locationId, 'food_truck')) {
            $locationUuid = FoodTruck::where('public_id', $locationId)->value('uuid');
        } elseif ($locationId) {
            $locationUuid = StoreLocation::where(fn ($query) => $query->where('public_id', $locationId)->orWhere('uuid', $locationId))->value('uuid');
        }

        $query = ProductInventory::where('product_uuid', $product->uuid);
        if ($locationUuid) {
            return $query->where('location_uuid', $locationUuid)->first();
        }

        $stocked = $query->get();

        return $stocked->count() === 1 ? $stocked->first() : $stocked->sortByDesc(fn ($inventory) => $inventory->available())->first();
    }

    protected static function inventoryForOrderProduct(Order $order, Product $product): ?ProductInventory
    {
        $sold = InventoryMovement::where('reference_uuid', $order->uuid)
            ->whereIn('inventory_uuid', ProductInventory::where('product_uuid', $product->uuid)->pluck('uuid'))
            ->value('inventory_uuid');

        return $sold ? ProductInventory::where('uuid', $sold)->first() : static::inventoryFor($product, null);
    }

    protected static function releaseReservations(Collection $reservations): int
    {
        $released = 0;
        foreach ($reservations as $reservation) {
            DB::connection((new ProductInventory())->getConnectionName())->transaction(function () use ($reservation, &$released) {
                $fresh = InventoryReservation::where('uuid', $reservation->uuid)->lockForUpdate()->first();
                if (!$fresh || $fresh->status !== InventoryReservation::STATUS_HELD) {
                    return;
                }

                $inventory = ProductInventory::where('uuid', $fresh->inventory_uuid)->lockForUpdate()->first();
                if ($inventory) {
                    $inventory->reserved = max(0, $inventory->reserved - $fresh->quantity);
                    $inventory->save();
                    static::record($inventory, InventoryMovement::TYPE_RELEASE, $fresh->quantity, null, 'checkout ' . $fresh->checkout_uuid);
                }
                $fresh->update(['status' => InventoryReservation::STATUS_RELEASED]);
                $released++;
            });
        }

        return $released;
    }

    /**
     * Log a movement. Reservations and releases change what is available, not what is on hand,
     * so `quantity_after` is the count on hand either way.
     */
    protected static function record(ProductInventory $inventory, string $type, int $quantity, $reference = null, ?string $note = null): void
    {
        InventoryMovement::create([
            'company_uuid'   => $inventory->company_uuid,
            'inventory_uuid' => $inventory->uuid,
            'type'           => $type,
            'quantity'       => $quantity,
            'quantity_after' => (int) $inventory->quantity,
            'reference_uuid' => $reference?->uuid,
            'reference_type' => $reference ? get_class($reference) : null,
            'actor_uuid'     => session('user'),
            'note'           => $note,
        ]);
    }

    protected static function locationPublicId(ProductInventory $inventory): ?string
    {
        $class = $inventory->location_type === FoodTruck::class ? FoodTruck::class : StoreLocation::class;

        return $class::where('uuid', $inventory->location_uuid)->value('public_id');
    }

    protected static function locationKind(ProductInventory $inventory): string
    {
        return $inventory->location_type === FoodTruck::class ? 'food_truck' : 'store_location';
    }
}
