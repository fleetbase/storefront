<?php

namespace Fleetbase\Storefront\Promotions;

/**
 * A cart item as seen by the promotion engine.
 */
class PromotionLine
{
    public function __construct(
        public string $id,
        public int $subtotal,
        public int $quantity,
        public ?string $productId = null,
        public ?string $productUuid = null,
        public ?string $categoryUuid = null,
        public ?string $storeId = null,
        public ?string $storeUuid = null,
    ) {
    }

    public function unitPrice(): float
    {
        return $this->quantity > 0 ? $this->subtotal / $this->quantity : 0.0;
    }

    /**
     * Whether any of the given identifiers (uuids or public ids) refers to this line's product.
     */
    public function matchesProduct(array $ids): bool
    {
        return !empty(array_intersect($ids, array_filter([$this->productId, $this->productUuid])));
    }

    public function matchesCategory(array $ids): bool
    {
        return $this->categoryUuid !== null && in_array($this->categoryUuid, $ids, true);
    }

    public function matchesStore(array $ids): bool
    {
        return !empty(array_intersect($ids, array_filter([$this->storeId, $this->storeUuid])));
    }
}
