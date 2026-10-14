<?php

namespace Fleetbase\Storefront\Support;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Models\Transaction;
use Illuminate\Support\Arr;

/**
 * An order's payment as one picture: how it was paid, what was paid, refunded or credited since,
 * and what is still due. Adjustments made after checkout (edits, cancellations) are recorded on
 * the order as `meta.payment_adjustments` and as transactions, so the state never has to be
 * guessed from a single capture.
 */
class OrderPayments
{
    public const STATE_PAID               = 'paid';
    public const STATE_TO_COLLECT         = 'to_collect';
    public const STATE_UNPAID             = 'unpaid';
    public const STATE_REFUNDED           = 'refunded';
    public const STATE_PARTIALLY_REFUNDED = 'partially_refunded';
    public const STATE_BALANCE_DUE        = 'balance_due';

    /** Gateways whose API refunds part of a payment. QPay refunds a whole invoice only. */
    public const PARTIAL_REFUND_GATEWAYS = ['stripe'];

    /**
     * The payment summary every client reads (console panel, customer app receipt).
     */
    public static function summary(Order $order): array
    {
        $meta        = static::meta($order);
        $gateway     = static::gateway($order);
        $total       = (int) ($meta['total'] ?? $order->transaction_amount ?? 0);
        $adjustments = static::adjustments($order);
        $refunded    = (int) collect($adjustments)->whereIn('type', ['refund'])->sum('amount');
        $credited    = (int) collect($adjustments)->whereIn('type', ['store_credit'])->sum('amount');
        $balanceDue  = max(0, (int) ($meta['balance_due'] ?? 0));
        $codAmount   = (int) data_get($order, 'payload.cod_amount', 0);
        $isCash      = static::isCashOnDelivery($order);
        $collected   = $isCash && static::isSettled($order);
        $unpaid      = !$isCash && strtolower((string) ($meta['payment_status'] ?? '')) === 'unpaid';

        // What was taken. Cash: what the driver collected. Otherwise what was charged, recorded on the
        // order once anything changes it (see rememberAmountPaid()), else the total.
        $amountPaid = match (true) {
            $isCash  => $collected ? static::collectedAmount($order) : 0,
            $unpaid  => 0,
            default  => (int) ($meta['amount_paid'] ?? $total),
        };

        $state = match (true) {
            $amountPaid > 0 && $refunded >= $amountPaid                => static::STATE_REFUNDED,
            $balanceDue > 0                                            => static::STATE_BALANCE_DUE,
            $isCash && !$collected                                     => static::STATE_TO_COLLECT,
            $unpaid                                                    => static::STATE_UNPAID,
            $refunded > 0 || $credited > 0                             => static::STATE_PARTIALLY_REFUNDED,
            default                                                    => static::STATE_PAID,
        };

        return [
            'state'                   => $state,
            'gateway'                 => $gateway,
            'method_label'            => static::methodLabel($gateway, $isCash),
            'total'                   => $total,
            'amount_paid'             => $amountPaid,
            'amount_refunded'         => $refunded,
            'amount_credited'         => $credited,
            'balance_due'             => $balanceDue,
            'cod_amount'              => $codAmount,
            'currency'                => $meta['currency'] ?? data_get($order, 'transaction.currency'),
            'payment_link'            => $meta['balance_payment_link'] ?? null,
            'store_credit_code'       => collect($adjustments)->where('type', 'store_credit')->pluck('reference')->filter()->last(),
            'supports_partial_refund' => in_array($gateway, static::PARTIAL_REFUND_GATEWAYS, true),
            'adjustments'             => array_values($adjustments),
        ];
    }

    /**
     * Keep `meta.payment` in step with the summary, so every client reads it from the order's own
     * meta whichever resource serves the order (the customer API returns Fleet-Ops' resource).
     */
    public static function snapshot(Order $order): array
    {
        $summary = static::summary($order);
        $order->updateMeta('payment', $summary);

        return $summary;
    }

    /**
     * Whether the order is paid in cash on delivery. A paid order whose driver collects an extra
     * amount after an edit is not: that amount is a balance due (see OrderSettlement::charge()).
     */
    public static function isCashOnDelivery(Order $order): bool
    {
        $gateway = static::gateway($order);

        return $gateway === 'cash' || (in_array($gateway, [null, ''], true) && data_get($order, 'payload.cod_payment_method') === 'cash');
    }

    /**
     * What the driver collected for a cash order.
     */
    protected static function collectedAmount(Order $order): int
    {
        $transaction = $order->relationLoaded('transaction') ? $order->transaction : $order->transaction()->first();

        return (int) ($transaction?->settled_amount ?: data_get($order, 'payload.cod_amount', 0) ?: $transaction?->amount);
    }

    /**
     * Whether the order's capture transaction has been settled (cash collected, or paid online).
     */
    public static function isSettled(Order $order): bool
    {
        $transaction = $order->relationLoaded('transaction') ? $order->transaction : $order->transaction()->first();

        return $transaction ? $transaction->settlement_status === Transaction::SETTLEMENT_STATUS_PAID : false;
    }

    /**
     * Cash on delivery is paid once the order is completed: settle the capture transaction.
     */
    public static function settleCollected(Order $order): void
    {
        if (!static::isCashOnDelivery($order)) {
            static::settleCollectedBalance($order);

            return;
        }

        $transaction = $order->transaction()->first();
        if (!$transaction || $transaction->settlement_status === Transaction::SETTLEMENT_STATUS_PAID) {
            return;
        }

        $amount = (int) data_get($order, 'payload.cod_amount', 0) ?: (int) $transaction->amount;
        $transaction->update([
            'status'            => Transaction::STATUS_SUCCESS,
            'settlement_status' => Transaction::SETTLEMENT_STATUS_PAID,
            'settled_at'        => now(),
            'settled_amount'    => $amount,
            'settled_currency'  => $transaction->currency,
        ]);

        $order->setRelation('transaction', $transaction);
        static::snapshot($order);
    }

    /**
     * A paid order whose driver collected an extra amount after an edit: the balance is paid.
     */
    protected static function settleCollectedBalance(Order $order): void
    {
        $cod     = (int) data_get($order, 'payload.cod_amount', 0);
        $balance = (int) $order->getMeta('balance_due', 0);
        if ($cod <= 0 || $balance <= 0) {
            return;
        }

        $collected = min($cod, $balance);
        $order->updateMeta('balance_due', $balance - $collected);
        static::recordAdjustment($order, 'charge', $collected, ['gateway' => 'cash', 'reason' => 'Collected on delivery']);
    }

    /**
     * Record a refund, store credit, extra charge or cash change against the order.
     *
     * @param string $type refund|store_credit|charge|cod_update
     */
    public static function recordAdjustment(Order $order, string $type, int $amount, array $details = []): array
    {
        $adjustment = array_filter([
            'type'      => $type,
            'amount'    => $amount,
            'gateway'   => $details['gateway'] ?? static::gateway($order),
            'reference' => $details['reference'] ?? null,
            'reason'    => $details['reason'] ?? null,
            'by'        => $details['by'] ?? session('user'),
            'at'        => now()->toIso8601String(),
        ], fn ($value) => $value !== null);

        static::rememberAmountPaid($order);

        $adjustments   = static::adjustments($order);
        $adjustments[] = $adjustment;
        $order->updateMeta('payment_adjustments', $adjustments);

        if ($type === 'charge') {
            $order->updateMeta('amount_paid', (int) static::meta($order)['amount_paid'] + $amount);
        }

        static::recordTransaction($order, $type, $amount, $adjustment);
        static::snapshot($order);

        return $adjustment;
    }

    /**
     * Pin what was charged at checkout before an edit changes the total, so later states compare
     * refunds with what was actually paid, not with the new total.
     */
    public static function rememberAmountPaid(Order $order): void
    {
        $meta = static::meta($order);
        if (array_key_exists('amount_paid', $meta) || static::isCashOnDelivery($order)) {
            return;
        }

        $unpaid = strtolower((string) ($meta['payment_status'] ?? '')) === 'unpaid';
        $order->updateMeta('amount_paid', $unpaid ? 0 : (int) ($meta['total'] ?? $order->transaction_amount ?? 0));
    }

    public static function adjustments(Order $order): array
    {
        $adjustments = static::meta($order)['payment_adjustments'] ?? [];

        return is_array($adjustments) ? array_values($adjustments) : [];
    }

    public static function gateway(Order $order): ?string
    {
        $meta = static::meta($order);

        return $meta['gateway'] ?? data_get($order, 'transaction.gateway') ?? data_get($order, 'payload.payment_method');
    }

    protected static function recordTransaction(Order $order, string $type, int $amount, array $adjustment): void
    {
        if ($amount === 0 || $type === 'cod_update') {
            return;
        }

        $capture = $order->transaction()->first();
        $types   = [
            'refund'       => Transaction::TYPE_GATEWAY_REFUND,
            'store_credit' => Transaction::TYPE_ADJUSTMENT,
            'charge'       => Transaction::TYPE_GATEWAY_CHARGE,
        ];

        Transaction::create([
            'company_uuid'           => $order->company_uuid,
            'customer_uuid'          => $order->customer_uuid,
            'customer_type'          => $order->customer_type,
            'gateway'                => $adjustment['gateway'] ?? null,
            'gateway_uuid'           => $capture?->gateway_uuid,
            'gateway_transaction_id' => $adjustment['reference'] ?? Transaction::generateNumber(),
            'amount'                 => $amount,
            'currency'               => static::meta($order)['currency'] ?? $capture?->currency,
            'description'            => 'Storefront order ' . str_replace('_', ' ', $type),
            'type'                   => $types[$type] ?? Transaction::TYPE_ADJUSTMENT,
            'direction'              => $type === 'charge' ? Transaction::DIRECTION_CREDIT : Transaction::DIRECTION_DEBIT,
            'status'                 => Transaction::STATUS_SUCCESS,
            'settlement_status'      => Transaction::SETTLEMENT_STATUS_PAID,
            'settled_at'             => now(),
            'settled_amount'         => $amount,
            'meta'                   => [
                'order_id'           => $order->public_id,
                'storefront_id'      => static::meta($order)['storefront_id'] ?? null,
                'parent_transaction' => $capture?->public_id,
                'adjustment'         => $adjustment,
            ],
        ]);
    }

    protected static function methodLabel(?string $gateway, bool $isCash): string
    {
        if ($isCash) {
            return 'Cash on delivery';
        }

        return match ($gateway) {
            'qpay'    => 'QPay',
            'stripe'  => 'Card (Stripe)',
            'paypal'  => 'PayPal',
            'console' => 'Recorded in the console',
            null, ''  => 'Unknown',
            default   => ucfirst($gateway),
        };
    }

    protected static function meta(Order $order): array
    {
        $meta = $order->meta ?? [];

        if (is_object($meta) && method_exists($meta, 'toArray')) {
            $meta = $meta->toArray();
        }

        return Arr::wrap(is_object($meta) ? (array) $meta : $meta);
    }
}
