<?php

namespace Fleetbase\Storefront\Promotions;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Storefront\Models\Promotion;
use Fleetbase\Storefront\Models\PromotionCode;
use Fleetbase\Storefront\Models\PromotionRedemption;
use Illuminate\Support\Collection;

/**
 * Prices a cart against the storefront's promotions.
 *
 * Candidates are the live automatic promotions of the storefront (and, in a network, of the
 * stores in the cart) plus the promotions of any codes the customer entered. Each candidate is
 * checked for eligibility and limits, then stacking is resolved: stackable promotions combine
 * (each on what the previous ones left), a non-stackable promotion applies alone, and whichever
 * option saves the customer more wins.
 */
class PromotionEngine
{
    public const REASON_INVALID_CODE           = 'invalid_code';
    public const REASON_NOT_ACTIVE             = 'not_active';
    public const REASON_NOT_APPLICABLE         = 'not_applicable';
    public const REASON_CURRENCY_MISMATCH      = 'currency_mismatch';
    public const REASON_FIRST_ORDER_ONLY       = 'first_order_only';
    public const REASON_USAGE_LIMIT            = 'usage_limit_reached';
    public const REASON_CUSTOMER_USAGE_LIMIT   = 'customer_usage_limit_reached';
    public const REASON_BUDGET_EXHAUSTED       = 'budget_exhausted';
    public const REASON_NOT_COMBINABLE         = 'not_combinable';

    public function __construct(protected PromotionCalculator $calculator)
    {
    }

    /**
     * @param string[] $codes promotion codes entered by the customer
     */
    public function evaluate(PromotionContext $context, array $codes = []): PromotionResult
    {
        $rejected = [];
        $codes    = array_values(array_unique(array_filter(array_map([PromotionCode::class, 'normalize'], $codes))));

        // Resolve entered codes to their promotions.
        $codeModels = $this->findCodes($context, $codes);
        foreach ($codes as $code) {
            $model  = $codeModels->get($code);
            $reason = $model ? $this->codeRejection($model, $context) : self::REASON_INVALID_CODE;
            if ($reason) {
                $rejected[] = ['code' => $code, 'reason' => $reason];
                $codeModels->forget($code);
            }
        }

        $codeByPromotion = $codeModels->keyBy('promotion_uuid');
        $candidates      = $this->candidates($context, $codeByPromotion->keys()->all());
        $usage           = $this->usage($candidates->pluck('uuid')->all(), $context);

        // Check each candidate on its own, at full prices.
        $eligible = [];
        foreach ($candidates as $promotion) {
            $code   = $codeByPromotion->get($promotion->uuid);
            $reason = $this->promotionRejection($promotion, $context, $usage[$promotion->uuid] ?? []);
            $single = $reason ?? $this->discountFor($promotion, $context, $this->fullAmounts($context), $context->deliveryFee, $usage[$promotion->uuid] ?? []);

            if (is_string($single)) {
                if ($code) {
                    $rejected[] = ['code' => $code->code, 'reason' => $single];
                }
                continue;
            }

            $eligible[] = ['promotion' => $promotion, 'code' => $code, 'total' => $single['total']];
        }

        usort($eligible, fn ($a, $b) => [$b['promotion']->priority, $b['total']] <=> [$a['promotion']->priority, $a['total']]);

        // Option A: every stackable promotion, applied in priority order on the remaining amounts.
        $remaining = $this->fullAmounts($context);
        $delivery  = $context->deliveryFee;
        $stacked   = [];
        foreach (array_filter($eligible, fn ($candidate) => $candidate['promotion']->stackable) as $candidate) {
            $discount = $this->discountFor($candidate['promotion'], $context, $remaining, $delivery, $usage[$candidate['promotion']->uuid] ?? []);
            if (is_string($discount)) {
                continue;
            }

            foreach ($discount['lines'] as $lineId => $amount) {
                $remaining[$lineId] -= $amount;
            }
            $delivery -= $discount['delivery'];
            $stacked[] = $this->appliedEntry($candidate, $discount, $context);
        }
        $stackedTotal = array_sum(array_map(fn ($entry) => $entry['amount'] + $entry['delivery_amount'], $stacked));

        // Option B: the best single non-stackable promotion.
        $exclusive = null;
        foreach ($eligible as $candidate) {
            if (!$candidate['promotion']->stackable && (!$exclusive || $candidate['total'] > $exclusive['total'])) {
                $exclusive = $candidate;
            }
        }

        if ($exclusive && $exclusive['total'] > $stackedTotal) {
            $discount = $this->discountFor($exclusive['promotion'], $context, $this->fullAmounts($context), $context->deliveryFee, $usage[$exclusive['promotion']->uuid] ?? []);
            $applied  = [$this->appliedEntry($exclusive, $discount, $context)];
        } else {
            $applied = $stacked;
        }

        // Codes the customer entered that lost out to a better combination.
        $appliedUuids = array_column($applied, 'promotion_uuid');
        foreach ($eligible as $candidate) {
            if ($candidate['code'] && !in_array($candidate['promotion']->uuid, $appliedUuids, true)) {
                $rejected[] = ['code' => $candidate['code']->code, 'reason' => self::REASON_NOT_COMBINABLE];
            }
        }

        return new PromotionResult($applied, $rejected);
    }

    /**
     * Discount for one promotion on the given amounts, capped by its maximum and remaining budget.
     *
     * @return array{lines: array<string, int>, delivery: int, total: int}|string
     */
    protected function discountFor(Promotion $promotion, PromotionContext $context, array $remaining, int $delivery, array $usage): array|string
    {
        $discount = $this->calculator->calculate($promotion, $context, $remaining, $delivery);
        if (is_string($discount)) {
            return $discount;
        }

        $caps = array_filter([
            $promotion->max_discount_amount,
            $promotion->budget_amount !== null ? max(0, $promotion->budget_amount - ($usage['spent'] ?? 0)) : null,
        ], fn ($cap) => $cap !== null);

        $total = array_sum($discount['lines']) + $discount['delivery'];
        if ($caps && $total > min($caps)) {
            // Cap the delivery discount first, then scale the item discounts into what is left.
            $cap                  = min($caps);
            $discount['delivery'] = min($discount['delivery'], $cap);
            $discount['lines']    = array_filter(PromotionCalculator::allocate($cap - $discount['delivery'], $discount['lines']));
            $total                = array_sum($discount['lines']) + $discount['delivery'];
        }

        return [...$discount, 'total' => $total];
    }

    protected function appliedEntry(array $candidate, array $discount, PromotionContext $context): array
    {
        $promotion = $candidate['promotion'];
        $code      = $candidate['code'];
        $lines     = collect($context->lines)->keyBy('id');

        $storeAmounts = [];
        foreach ($discount['lines'] as $lineId => $amount) {
            $storeId                = $lines->get($lineId)?->storeId ?? 'unknown';
            $storeAmounts[$storeId] = ($storeAmounts[$storeId] ?? 0) + $amount;
        }

        return [
            'promotion_uuid'  => $promotion->uuid,
            'promotion_id'    => $promotion->public_id,
            'name'            => $promotion->name,
            'type'            => $promotion->type,
            'code'            => $code?->code,
            'code_uuid'       => $code?->uuid,
            'amount'          => array_sum($discount['lines']),
            'delivery_amount' => $discount['delivery'],
            'line_amounts'    => $discount['lines'],
            'store_amounts'   => $storeAmounts,
        ];
    }

    /**
     * Why a promotion cannot apply to this context, if it cannot.
     */
    protected function promotionRejection(Promotion $promotion, PromotionContext $context, array $usage): ?string
    {
        if (!$promotion->isLiveAt($context->now)) {
            return self::REASON_NOT_ACTIVE;
        }

        if ($promotion->type === Promotion::TYPE_FIXED_AMOUNT && $promotion->currency && $context->currency && strcasecmp($promotion->currency, $context->currency) !== 0) {
            return self::REASON_CURRENCY_MISMATCH;
        }

        if ($promotion->usage_limit !== null && ($usage['count'] ?? 0) >= $promotion->usage_limit) {
            return self::REASON_USAGE_LIMIT;
        }

        if ($promotion->budget_amount !== null && ($usage['spent'] ?? 0) >= $promotion->budget_amount) {
            return self::REASON_BUDGET_EXHAUSTED;
        }

        // Customer checks run once the customer is known (at checkout); cart previews skip them.
        if ($context->customer) {
            if ($promotion->usage_limit_per_customer !== null && ($usage['customer_count'] ?? 0) >= $promotion->usage_limit_per_customer) {
                return self::REASON_CUSTOMER_USAGE_LIMIT;
            }

            if ($promotion->first_order_only && $this->customerHasOrdered($context)) {
                return self::REASON_FIRST_ORDER_ONLY;
            }
        }

        return null;
    }

    protected function codeRejection(PromotionCode $code, PromotionContext $context): ?string
    {
        if ($code->status !== PromotionCode::STATUS_ACTIVE || ($code->expires_at && $context->now->gte($code->expires_at))) {
            return self::REASON_INVALID_CODE;
        }

        if ($code->customer_uuid && $context->customer && $code->customer_uuid !== $context->customer->uuid) {
            return self::REASON_INVALID_CODE;
        }

        if ($code->usage_limit !== null && PromotionRedemption::counting()->where('promotion_code_uuid', $code->uuid)->count() >= $code->usage_limit) {
            return self::REASON_USAGE_LIMIT;
        }

        $promotion = $code->promotion;
        if (!$promotion || !in_array($promotion->owner_uuid, $this->ownerUuids($context), true)) {
            return self::REASON_NOT_APPLICABLE;
        }

        return null;
    }

    /**
     * @return Collection<string, PromotionCode> keyed by code
     */
    protected function findCodes(PromotionContext $context, array $codes): Collection
    {
        if (empty($codes)) {
            return collect();
        }

        return PromotionCode::with('promotion')
            ->whereIn('code', $codes)
            ->when($context->storefront?->company_uuid, fn ($query, $company) => $query->where('company_uuid', $company))
            ->get()
            ->keyBy('code');
    }

    /**
     * @return Collection<int, Promotion>
     */
    protected function candidates(PromotionContext $context, array $codePromotionUuids): Collection
    {
        $owners = $this->ownerUuids($context);
        if (empty($owners)) {
            return collect();
        }

        return Promotion::query()
            ->whereIn('owner_uuid', $owners)
            ->where('status', Promotion::STATUS_ACTIVE)
            ->where(function ($query) use ($codePromotionUuids) {
                $query->where('trigger', Promotion::TRIGGER_AUTOMATIC);
                if ($codePromotionUuids) {
                    $query->orWhereIn('uuid', $codePromotionUuids);
                }
            })
            ->get();
    }

    /**
     * The storefront the customer is shopping in, plus the stores in the cart (network carts
     * can use each store's own promotions on that store's items).
     */
    protected function ownerUuids(PromotionContext $context): array
    {
        return array_values(array_unique(array_filter([$context->storefront?->uuid, ...$context->storeUuids()])));
    }

    /**
     * Counting uses per promotion: total, per customer, and amount spent from the budget.
     *
     * @return array<string, array{count: int, spent: int, customer_count: int}>
     */
    protected function usage(array $promotionUuids, PromotionContext $context): array
    {
        if (empty($promotionUuids)) {
            return [];
        }

        $rows = PromotionRedemption::counting()
            ->whereIn('promotion_uuid', $promotionUuids)
            ->groupBy('promotion_uuid')
            ->selectRaw('promotion_uuid, COUNT(*) as uses, COALESCE(SUM(amount), 0) as spent, SUM(CASE WHEN customer_uuid = ? THEN 1 ELSE 0 END) as customer_uses', [$context->customer?->uuid])
            ->toBase()
            ->get();

        $usage = [];
        foreach ($rows as $row) {
            $usage[$row->promotion_uuid] = [
                'count'          => (int) $row->uses,
                'spent'          => (int) $row->spent,
                'customer_count' => (int) $row->customer_uses,
            ];
        }

        return $usage;
    }

    protected function customerHasOrdered(PromotionContext $context): bool
    {
        return Order::where('customer_uuid', $context->customer->uuid)
            ->where('type', 'storefront')
            ->where('status', '!=', 'canceled')
            ->exists();
    }

    /**
     * @return array<string, int>
     */
    protected function fullAmounts(PromotionContext $context): array
    {
        $amounts = [];
        foreach ($context->lines as $line) {
            $amounts[$line->id] = $line->subtotal;
        }

        return $amounts;
    }
}
