<?php

namespace Fleetbase\Storefront\Promotions;

/**
 * The discounts applied to a cart, serializable onto a checkout's options.
 */
class PromotionResult
{
    /**
     * @param array<int, array> $applied  applied promotions with their amounts
     * @param array<int, array> $rejected codes that could not be applied, with a reason
     */
    public function __construct(
        public array $applied = [],
        public array $rejected = [],
    ) {
    }

    public function discountSubtotal(): int
    {
        return array_sum(array_column($this->applied, 'amount'));
    }

    public function discountDelivery(): int
    {
        return array_sum(array_column($this->applied, 'delivery_amount'));
    }

    public function discount(): int
    {
        return $this->discountSubtotal() + $this->discountDelivery();
    }

    public function isEmpty(): bool
    {
        return empty($this->applied);
    }

    /**
     * Item discount per store public id, used to split multi-store orders.
     */
    public function allocationsByStore(): array
    {
        $allocations = [];
        foreach ($this->applied as $applied) {
            foreach ($applied['store_amounts'] ?? [] as $storeId => $amount) {
                $allocations[$storeId] = ($allocations[$storeId] ?? 0) + $amount;
            }
        }

        return $allocations;
    }

    public function toArray(): array
    {
        return [
            'discount'          => $this->discount(),
            'discount_subtotal' => $this->discountSubtotal(),
            'discount_delivery' => $this->discountDelivery(),
            'applied'           => $this->applied,
            'rejected'          => $this->rejected,
            'allocations'       => $this->allocationsByStore(),
        ];
    }

    /**
     * Rebuild a result from the array stored on a checkout (arrays or decoded objects).
     */
    public static function fromArray($data): self
    {
        $data = json_decode(json_encode($data ?? []), true) ?: [];

        return new static((array) ($data['applied'] ?? []), (array) ($data['rejected'] ?? []));
    }

    /**
     * Public representation for API responses (no internal uuids or per-line details).
     */
    public function toPublicArray(): array
    {
        return [
            'discount'          => $this->discount(),
            'discount_subtotal' => $this->discountSubtotal(),
            'discount_delivery' => $this->discountDelivery(),
            'applied'           => array_map(fn ($applied) => [
                'promotion'       => $applied['promotion_id'] ?? null,
                'name'            => $applied['name'] ?? null,
                'type'            => $applied['type'] ?? null,
                'code'            => $applied['code'] ?? null,
                'amount'          => $applied['amount'] ?? 0,
                'delivery_amount' => $applied['delivery_amount'] ?? 0,
            ], $this->applied),
            'rejected' => $this->rejected,
        ];
    }
}
