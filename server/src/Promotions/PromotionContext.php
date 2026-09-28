<?php

namespace Fleetbase\Storefront\Promotions;

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Storefront\Models\Cart;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Product;
use Fleetbase\Storefront\Models\Store;
use Illuminate\Support\Carbon;

/**
 * Everything the promotion engine needs to price a cart.
 */
class PromotionContext
{
    /**
     * @param PromotionLine[] $lines
     */
    public function __construct(
        public Store|Network|null $storefront,
        public array $lines,
        public ?string $currency = null,
        public ?Contact $customer = null,
        public bool $isPickup = false,
        public int $deliveryFee = 0,
        public ?Carbon $now = null,
    ) {
        $this->now ??= Carbon::now();
    }

    public static function fromCart(Cart $cart, Store|Network|null $storefront, ?Contact $customer = null, bool $isPickup = false, int $deliveryFee = 0): self
    {
        $items      = collect($cart->items ?? []);
        $products   = Product::whereIn('public_id', $items->pluck('product_id')->filter()->unique()->values()->all())
            ->get(['uuid', 'public_id', 'category_uuid', 'store_uuid'])
            ->keyBy('public_id');
        $storeUuids = Store::whereIn('public_id', $items->pluck('store_id')->filter()->unique()->values()->all())
            ->pluck('uuid', 'public_id');

        $lines = $items->map(function ($item) use ($products, $storeUuids) {
            $product = $products->get(data_get($item, 'product_id'));

            return new PromotionLine(
                (string) data_get($item, 'id'),
                (int) Utils::numbersOnly(data_get($item, 'subtotal', 0)),
                max((int) data_get($item, 'quantity', 1), 1),
                data_get($item, 'product_id'),
                $product?->uuid,
                $product?->category_uuid,
                data_get($item, 'store_id'),
                $storeUuids->get(data_get($item, 'store_id')) ?? $product?->store_uuid,
            );
        })->values()->all();

        return new static($storefront, $lines, $cart->currency, $customer, $isPickup, $isPickup ? 0 : $deliveryFee);
    }

    public function subtotal(): int
    {
        return array_sum(array_map(fn (PromotionLine $line) => $line->subtotal, $this->lines));
    }

    /**
     * Uuids of the stores whose items are in the cart.
     */
    public function storeUuids(): array
    {
        return array_values(array_unique(array_filter(array_map(fn (PromotionLine $line) => $line->storeUuid, $this->lines))));
    }
}
