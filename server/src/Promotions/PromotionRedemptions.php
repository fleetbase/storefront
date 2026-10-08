<?php

namespace Fleetbase\Storefront\Promotions;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Storefront\Models\Checkout;
use Fleetbase\Storefront\Models\Promotion;
use Fleetbase\Storefront\Models\PromotionCode;
use Fleetbase\Storefront\Models\PromotionRedemption;
use Illuminate\Support\Facades\DB;

/**
 * Records promotion uses: reserved when a checkout is created, redeemed when its order is
 * captured, released when the checkout is abandoned.
 */
class PromotionRedemptions
{
    /**
     * Reserve the promotions applied to a checkout. Limits and budgets are re-checked under a
     * row lock so concurrent checkouts cannot overspend a promotion.
     *
     * @throws PromotionUnavailableException when a promotion ran out since the cart was priced
     */
    public static function reserve(Checkout $checkout, PromotionResult $result, ?string $customerUuid): void
    {
        if ($result->isEmpty()) {
            return;
        }

        DB::connection((new PromotionRedemption())->getConnectionName())->transaction(function () use ($checkout, $result, $customerUuid) {
            foreach ($result->applied as $applied) {
                $promotion = Promotion::where('uuid', $applied['promotion_uuid'])->lockForUpdate()->first();
                $amount    = (int) ($applied['amount'] ?? 0) + (int) ($applied['delivery_amount'] ?? 0);

                if (!$promotion || !static::hasCapacity($promotion, $applied['code_uuid'] ?? null, $customerUuid, $amount)) {
                    throw new PromotionUnavailableException($applied['name'] ?? 'promotion');
                }

                PromotionRedemption::create([
                    'company_uuid'        => $promotion->company_uuid,
                    'promotion_uuid'      => $promotion->uuid,
                    'promotion_code_uuid' => $applied['code_uuid'] ?? null,
                    'customer_uuid'       => $customerUuid,
                    'checkout_uuid'       => $checkout->uuid,
                    'amount'              => $amount,
                    'currency'            => $checkout->currency,
                    'status'              => PromotionRedemption::STATUS_RESERVED,
                ]);
            }
        });
    }

    /**
     * Mark a checkout's promotion uses as redeemed by the captured order. The customer already
     * paid the discounted amount, so a reservation that expired meanwhile is redeemed anyway.
     */
    public static function redeem(Checkout $checkout, Order $order): void
    {
        $result = PromotionResult::fromArray(data_get($checkout->options, 'promotions'));
        if ($result->isEmpty()) {
            return;
        }

        $updated = PromotionRedemption::where('checkout_uuid', $checkout->uuid)
            ->whereIn('status', [PromotionRedemption::STATUS_RESERVED, PromotionRedemption::STATUS_RELEASED])
            ->update([
                'status'      => PromotionRedemption::STATUS_REDEEMED,
                'order_uuid'  => $order->uuid,
                'redeemed_at' => now(),
            ]);

        if ($updated > 0) {
            return;
        }

        foreach ($result->applied as $applied) {
            $redemption = new PromotionRedemption([
                'company_uuid'        => $order->company_uuid,
                'promotion_uuid'      => $applied['promotion_uuid'],
                'promotion_code_uuid' => $applied['code_uuid'] ?? null,
                'customer_uuid'       => $order->customer_uuid,
                'checkout_uuid'       => $checkout->uuid,
                'order_uuid'          => $order->uuid,
                'amount'              => (int) ($applied['amount'] ?? 0) + (int) ($applied['delivery_amount'] ?? 0),
                'currency'            => $checkout->currency,
                'status'              => PromotionRedemption::STATUS_REDEEMED,
                'redeemed_at'         => now(),
            ]);
            $redemption->save();
        }
    }

    /**
     * Release a checkout's reserved promotion uses, so the checkout can be priced and
     * reserved again (the customer changed how they pay before capturing).
     */
    public static function releaseFor(Checkout $checkout): int
    {
        return PromotionRedemption::where('checkout_uuid', $checkout->uuid)
            ->where('status', PromotionRedemption::STATUS_RESERVED)
            ->update(['status' => PromotionRedemption::STATUS_RELEASED]);
    }

    /**
     * Release reservations of checkouts that were never captured.
     */
    public static function releaseStale(): int
    {
        return PromotionRedemption::where('status', PromotionRedemption::STATUS_RESERVED)
            ->where('created_at', '<', now()->subMinutes(PromotionRedemption::RESERVATION_MINUTES))
            ->update(['status' => PromotionRedemption::STATUS_RELEASED]);
    }

    protected static function hasCapacity(Promotion $promotion, ?string $codeUuid, ?string $customerUuid, int $amount): bool
    {
        $counting = PromotionRedemption::counting()->where('promotion_uuid', $promotion->uuid);

        if ($promotion->usage_limit !== null && (clone $counting)->count() >= $promotion->usage_limit) {
            return false;
        }

        if ($promotion->budget_amount !== null && (int) (clone $counting)->sum('amount') + $amount > $promotion->budget_amount) {
            return false;
        }

        if ($customerUuid && $promotion->usage_limit_per_customer !== null && (clone $counting)->where('customer_uuid', $customerUuid)->count() >= $promotion->usage_limit_per_customer) {
            return false;
        }

        if ($codeUuid) {
            $code = PromotionCode::where('uuid', $codeUuid)->first();
            if ($code && $code->usage_limit !== null && PromotionRedemption::counting()->where('promotion_code_uuid', $codeUuid)->count() >= $code->usage_limit) {
                return false;
            }
        }

        return true;
    }
}
