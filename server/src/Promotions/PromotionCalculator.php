<?php

namespace Fleetbase\Storefront\Promotions;

use Fleetbase\Storefront\Models\Promotion;
use Fleetbase\Storefront\Models\Store;

/**
 * Computes the discount one promotion gives on a cart.
 *
 * Works on the amounts still payable per line (so stacked promotions apply to what earlier
 * promotions left) and never discounts a line or the delivery fee below zero.
 */
class PromotionCalculator
{
    public const REASON_NO_ELIGIBLE_ITEMS = 'no_eligible_items';
    public const REASON_MIN_SUBTOTAL      = 'min_subtotal';
    public const REASON_MIN_ITEMS         = 'min_items';
    public const REASON_PICKUP_ORDER      = 'pickup_order';
    public const REASON_NO_DISCOUNT       = 'no_discount';

    /**
     * @param array<string, int> $remaining         amount still payable per line id
     * @param int                $remainingDelivery delivery fee still payable
     *
     * @return array{lines: array<string, int>, delivery: int}|string the discount, or the reason it does not apply
     */
    public function calculate(Promotion $promotion, PromotionContext $context, array $remaining, int $remainingDelivery): array|string
    {
        $lines = $this->eligibleLines($promotion, $context);
        if (empty($lines)) {
            return self::REASON_NO_ELIGIBLE_ITEMS;
        }

        $eligibleSubtotal = array_sum(array_map(fn (PromotionLine $line) => $line->subtotal, $lines));
        $eligibleQuantity = array_sum(array_map(fn (PromotionLine $line) => $line->quantity, $lines));

        if ($promotion->min_subtotal && $eligibleSubtotal < $promotion->min_subtotal) {
            return self::REASON_MIN_SUBTOTAL;
        }

        if ($promotion->min_items && $eligibleQuantity < $promotion->min_items) {
            return self::REASON_MIN_ITEMS;
        }

        $available = [];
        foreach ($lines as $line) {
            $available[$line->id] = max(0, (int) ($remaining[$line->id] ?? $line->subtotal));
        }

        $discount = match ($promotion->type) {
            Promotion::TYPE_PERCENTAGE    => ['lines' => $this->percentageOf($available, (float) $promotion->value), 'delivery' => 0],
            Promotion::TYPE_FIXED_AMOUNT  => ['lines' => static::allocate((int) round((float) $promotion->value), $available), 'delivery' => 0],
            Promotion::TYPE_FREE_DELIVERY => $context->isPickup ? self::REASON_PICKUP_ORDER : ['lines' => [], 'delivery' => max(0, $remainingDelivery)],
            Promotion::TYPE_BOGO          => ['lines' => $this->buyXGetY($promotion, $lines, $available), 'delivery' => 0],
            default                       => ['lines' => [], 'delivery' => 0],
        };

        if (is_string($discount)) {
            return $discount;
        }

        $discount['lines'] = array_filter($discount['lines']);
        if (array_sum($discount['lines']) + $discount['delivery'] <= 0) {
            return self::REASON_NO_DISCOUNT;
        }

        return $discount;
    }

    /**
     * Lines the promotion targets: limited to the owning store for store promotions, then
     * filtered by the promotion's `applies_to` include and exclude lists.
     *
     * @return PromotionLine[]
     */
    public function eligibleLines(Promotion $promotion, PromotionContext $context): array
    {
        $appliesTo         = (array) ($promotion->applies_to ?? []);
        $products          = (array) ($appliesTo['products'] ?? []);
        $categories        = (array) ($appliesTo['categories'] ?? []);
        $stores            = (array) ($appliesTo['stores'] ?? []);
        $excludeProducts   = (array) ($appliesTo['exclude_products'] ?? []);
        $excludeCategories = (array) ($appliesTo['exclude_categories'] ?? []);
        $ownerStore        = static::ownerIsStore($promotion) ? $promotion->owner_uuid : null;

        return array_values(array_filter($context->lines, function (PromotionLine $line) use ($ownerStore, $products, $categories, $stores, $excludeProducts, $excludeCategories) {
            if ($ownerStore && $line->storeUuid !== $ownerStore) {
                return false;
            }

            if ($line->matchesProduct($excludeProducts) || $line->matchesCategory($excludeCategories)) {
                return false;
            }

            if ($stores && !$line->matchesStore($stores)) {
                return false;
            }

            if ($products || $categories) {
                return $line->matchesProduct($products) || $line->matchesCategory($categories);
            }

            return true;
        }));
    }

    public static function ownerIsStore(Promotion $promotion): bool
    {
        return in_array($promotion->owner_type, [Store::class, 'storefront:store'], true);
    }

    /**
     * @param array<string, int> $available
     *
     * @return array<string, int>
     */
    protected function percentageOf(array $available, float $percent): array
    {
        $percent = min(max($percent, 0), 100);

        return static::allocate((int) round(array_sum($available) * $percent / 100), $available);
    }

    /**
     * Buy X get Y: for every group of `buy_quantity + get_quantity` eligible units, the cheapest
     * `get_quantity` units are discounted by `discount_percent` (100 = free).
     *
     * @param PromotionLine[]    $lines
     * @param array<string, int> $available
     *
     * @return array<string, int>
     */
    protected function buyXGetY(Promotion $promotion, array $lines, array $available): array
    {
        $config  = (array) ($promotion->bogo_config ?? []);
        $buy     = max((int) ($config['buy_quantity'] ?? 1), 1);
        $get     = max((int) ($config['get_quantity'] ?? 1), 1);
        $percent = min(max((float) ($config['discount_percent'] ?? 100), 0), 100);

        $units = [];
        foreach ($lines as $line) {
            for ($i = 0; $i < $line->quantity; $i++) {
                $units[] = ['line' => $line->id, 'price' => $line->unitPrice()];
            }
        }

        $freeUnits = intdiv(count($units), $buy + $get) * $get;
        if ($freeUnits === 0) {
            return [];
        }

        usort($units, fn ($a, $b) => $a['price'] <=> $b['price']);

        $discounts = [];
        foreach (array_slice($units, 0, $freeUnits) as $unit) {
            $discounts[$unit['line']] = ($discounts[$unit['line']] ?? 0) + $unit['price'] * $percent / 100;
        }

        $result = [];
        foreach ($discounts as $lineId => $amount) {
            $result[$lineId] = min((int) round($amount), $available[$lineId] ?? 0);
        }

        return $result;
    }

    /**
     * Split an amount across lines in proportion to their available amounts, using the largest
     * remainder method so the parts add up exactly, and never exceeding a line's amount.
     *
     * @param array<string, int> $available
     *
     * @return array<string, int>
     */
    public static function allocate(int $amount, array $available): array
    {
        $total  = array_sum($available);
        $amount = min(max($amount, 0), $total);
        if ($amount === 0) {
            return array_map(fn () => 0, $available);
        }

        $parts      = [];
        $remainders = [];
        foreach ($available as $key => $value) {
            $exact            = $amount * $value / $total;
            $parts[$key]      = (int) floor($exact);
            $remainders[$key] = $exact - $parts[$key];
        }

        arsort($remainders);
        $left = $amount - array_sum($parts);
        foreach (array_keys($remainders) as $key) {
            if ($left <= 0) {
                break;
            }
            if ($parts[$key] < $available[$key]) {
                $parts[$key]++;
                $left--;
            }
        }

        return $parts;
    }
}
