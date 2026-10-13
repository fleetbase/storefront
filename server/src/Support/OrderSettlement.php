<?php

namespace Fleetbase\Storefront\Support;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Storefront\Models\Checkout;
use Fleetbase\Storefront\Models\Gateway;
use Fleetbase\Storefront\Models\Promotion;
use Fleetbase\Storefront\Models\PromotionCode;
use Fleetbase\Storefront\Models\Store;
use Illuminate\Support\Str;

/**
 * Moves money after an order is placed: refunds to the gateway that took it, store credit as a
 * one-customer promotion code, and the cash a driver collects. Each move is recorded on the order
 * (OrderPayments::recordAdjustment) so the payment picture stays true.
 */
class OrderSettlement
{
    public const METHOD_REFUND       = 'refund';
    public const METHOD_STORE_CREDIT = 'store_credit';
    public const METHOD_COLLECT      = 'collect';
    public const METHOD_PAYMENT_LINK = 'payment_link';

    /**
     * How a difference would be settled, for the edit preview: what the console offers and what
     * it would do by default.
     */
    public static function plan(Order $order, int $difference): array
    {
        $payment = OrderPayments::summary($order);
        $gateway = $payment['gateway'];
        $isCash  = OrderPayments::isCashOnDelivery($order);
        $paid    = $payment['amount_paid'] - $payment['amount_refunded'];

        if ($difference === 0) {
            return ['action' => 'none', 'amount' => 0, 'options' => [], 'gateway' => $gateway];
        }

        // Cash not yet collected: the driver simply collects the new amount.
        if ($isCash && $payment['state'] === OrderPayments::STATE_TO_COLLECT) {
            return ['action' => 'cod_update', 'amount' => $difference, 'options' => ['cod_update'], 'gateway' => 'cash'];
        }

        if ($difference < 0) {
            $amount  = abs($difference);
            $options = [static::METHOD_STORE_CREDIT];
            $partial = in_array($gateway, OrderPayments::PARTIAL_REFUND_GATEWAYS, true);
            // A gateway that refunds whole payments only can refund when nothing paid remains.
            if ($partial || ($gateway === 'qpay' && $amount >= $paid)) {
                array_unshift($options, static::METHOD_REFUND);
            }

            return [
                'action'                  => $options[0],
                'amount'                  => $amount,
                'options'                 => $options,
                'gateway'                 => $gateway,
                'supports_partial_refund' => $partial,
            ];
        }

        return [
            'action'  => $isCash ? static::METHOD_COLLECT : static::METHOD_PAYMENT_LINK,
            'amount'  => $difference,
            'options' => [static::METHOD_PAYMENT_LINK, static::METHOD_COLLECT],
            'gateway' => $gateway,
        ];
    }

    /**
     * What canceling the order would give back: what was paid and is still held, and the ways it
     * can go back (a refund where the gateway can refund it, else store credit).
     */
    public static function cancelPlan(Order $order): array
    {
        $payment    = OrderPayments::summary($order);
        $refundable = max(0, $payment['amount_paid'] - $payment['amount_refunded'] - $payment['amount_credited']);
        $plan       = $refundable > 0 ? static::plan($order, -$refundable) : null;
        $options    = array_values(array_intersect($plan['options'] ?? [], [static::METHOD_REFUND, static::METHOD_STORE_CREDIT]));

        return [
            'refundable'              => $options ? $refundable : 0,
            'currency'                => $payment['currency'],
            'gateway'                 => $payment['gateway'],
            'method_label'            => $payment['method_label'],
            'options'                 => $options,
            'default'                 => $options[0] ?? null,
            'supports_partial_refund' => (bool) ($plan['supports_partial_refund'] ?? false),
        ];
    }

    /**
     * Return money to the customer, by refund or store credit.
     *
     * @throws \RuntimeException when the gateway cannot refund this amount
     */
    public static function refund(Order $order, int $amount, string $method = self::METHOD_REFUND, ?string $reason = null): array
    {
        if ($amount <= 0) {
            return [];
        }

        if ($method === static::METHOD_STORE_CREDIT) {
            return static::storeCredit($order, $amount, $reason);
        }

        $gateway   = OrderPayments::gateway($order);
        $reference = match ($gateway) {
            'stripe' => static::refundStripe($order, $amount),
            'qpay'   => static::refundQPay($order, $amount),
            default  => throw new \RuntimeException('Refunds to ' . ($gateway ?: 'this payment method') . ' are not supported; give store credit instead.'),
        };

        return OrderPayments::recordAdjustment($order, 'refund', $amount, ['reference' => $reference, 'reason' => $reason, 'gateway' => $gateway]);
    }

    /**
     * An extra amount the customer owes after an edit: added to the cash the driver collects,
     * or left as a balance due with a payment link.
     */
    public static function charge(Order $order, int $amount, string $method, ?string $reason = null): array
    {
        if ($amount <= 0) {
            return [];
        }

        if (OrderPayments::isCashOnDelivery($order)) {
            return static::updateCashToCollect($order, $amount, $reason);
        }

        // Paid online: the extra is a balance due, collected by the driver or through a payment link.
        $balance = (int) $order->getMeta('balance_due', 0) + $amount;
        $order->updateMeta('balance_due', $balance);
        if ($method === static::METHOD_COLLECT) {
            return static::updateCashToCollect($order, $amount, $reason);
        }

        $link = static::balancePaymentLink($order, $balance);
        if ($link) {
            $order->updateMeta('balance_payment_link', $link);
        }
        OrderPayments::snapshot($order);

        return ['type' => 'balance_due', 'amount' => $amount, 'payment_link' => $link];
    }

    /**
     * Change the cash the driver collects by `$delta` (negative lowers it).
     */
    public static function updateCashToCollect(Order $order, int $delta, ?string $reason = null): array
    {
        $payload = $order->payload;
        if ($payload) {
            $current = (int) $payload->cod_amount;
            $payload->forceFill(['cod_amount' => max(0, $current + $delta)])->save();
            $order->setRelation('payload', $payload);
        }

        return OrderPayments::recordAdjustment($order, 'cod_update', $delta, ['gateway' => 'cash', 'reason' => $reason]);
    }

    /**
     * Credit as a single-use, fixed-amount code only this customer can use at the same store.
     */
    public static function storeCredit(Order $order, int $amount, ?string $reason = null): array
    {
        $store    = Store::where('public_id', $order->getMeta('storefront_id'))->first();
        $currency = $order->getMeta('currency') ?? $store?->currency;

        $promotion = Promotion::create([
            'company_uuid'             => $order->company_uuid,
            'created_by_uuid'          => session('user'),
            'owner_uuid'               => $store?->uuid,
            'owner_type'               => $store ? Store::class : null,
            'name'                     => 'Store credit for ' . ($order->tracking ?? $order->public_id),
            'description'              => $reason,
            'status'                   => Promotion::STATUS_ACTIVE,
            'trigger'                  => Promotion::TRIGGER_CODE,
            'type'                     => Promotion::TYPE_FIXED_AMOUNT,
            'value'                    => $amount,
            'currency'                 => $currency,
            'usage_limit'              => 1,
            'usage_limit_per_customer' => 1,
            'stackable'                => true,
            'is_public'                => false,
            'starts_at'                => now(),
            'ends_at'                  => now()->addYear(),
            'meta'                     => ['store_credit' => true, 'order_id' => $order->public_id],
        ]);

        $code = PromotionCode::create([
            'company_uuid'   => $order->company_uuid,
            'promotion_uuid' => $promotion->uuid,
            'code'           => 'CREDIT-' . strtoupper(Str::random(8)),
            'status'         => PromotionCode::STATUS_ACTIVE,
            'usage_limit'    => 1,
            'customer_uuid'  => $order->customer_uuid,
            'expires_at'     => now()->addYear(),
            'meta'           => ['store_credit' => true, 'order_id' => $order->public_id],
        ]);

        return OrderPayments::recordAdjustment($order, 'store_credit', $amount, ['reference' => $code->code, 'reason' => $reason, 'gateway' => 'store_credit']);
    }

    protected static function refundStripe(Order $order, int $amount): string
    {
        $checkout = static::checkout($order);
        $gateway  = static::gatewayFor($order, $checkout);
        $intent   = $checkout?->stripe_payment_intent_id ?? data_get($order, 'transaction.gateway_transaction_id');

        if (!$gateway || empty(data_get($gateway, 'config.secret_key')) || !$intent) {
            throw new \RuntimeException('This order has no Stripe payment to refund.');
        }

        \Stripe\Stripe::setApiKey($gateway->config->secret_key);
        $refund = \Stripe\Refund::create([
            'payment_intent' => $intent,
            'amount'         => $amount,
            'metadata'       => ['order' => $order->public_id],
        ]);

        return $refund->id;
    }

    /**
     * QPay refunds a whole payment; only allowed when the whole remaining payment goes back.
     */
    protected static function refundQPay(Order $order, int $amount): string
    {
        $payment = OrderPayments::summary($order);
        if ($amount < $payment['amount_paid'] - $payment['amount_refunded']) {
            throw new \RuntimeException('QPay refunds whole payments only; give store credit for part of it.');
        }

        $checkout  = static::checkout($order);
        $gateway   = static::gatewayFor($order, $checkout);
        $paymentId = data_get($checkout?->getOption('qpay_payment'), 'payment_id') ?? data_get($order, 'transaction.gateway_transaction_id');

        if (!$gateway || !$paymentId) {
            throw new \RuntimeException('This order has no QPay payment to refund.');
        }

        $qpay     = static::qpay($gateway);
        $response = $qpay->refundPayment((string) $paymentId, 'Refund for ' . ($order->tracking ?? $order->public_id));
        if ($response === null || data_get($response, 'error')) {
            throw new \RuntimeException('QPay did not accept the refund' . (data_get($response, 'message') ? ': ' . data_get($response, 'message') : '.'));
        }

        return (string) $paymentId;
    }

    /**
     * A payment link for the balance: a Stripe Payment Link or a QPay invoice for the amount.
     * Returns null when the gateway cannot issue one (the balance stays due; staff collect it).
     */
    protected static function balancePaymentLink(Order $order, int $amount): ?string
    {
        $checkout = static::checkout($order);
        $gateway  = static::gatewayFor($order, $checkout);
        $currency = strtolower((string) ($order->getMeta('currency') ?? 'usd'));

        try {
            if ($gateway && $gateway->isStripeGateway && data_get($gateway, 'config.secret_key')) {
                \Stripe\Stripe::setApiKey($gateway->config->secret_key);
                $price = \Stripe\Price::create([
                    'currency'     => $currency,
                    'unit_amount'  => $amount,
                    'product_data' => ['name' => 'Balance for order ' . ($order->tracking ?? $order->public_id)],
                ]);
                $link = \Stripe\PaymentLink::create([
                    'line_items' => [['price' => $price->id, 'quantity' => 1]],
                    'metadata'   => ['order' => $order->public_id, 'kind' => 'order_balance'],
                ]);

                return $link->url;
            }

            if ($gateway && $gateway->isQPayGateway) {
                $qpay    = static::qpay($gateway);
                $invoice = $qpay->createSimpleInvoice(
                    (int) $amount,
                    data_get($gateway, 'config.invoice_code'),
                    'Balance for order ' . ($order->tracking ?? $order->public_id),
                    data_get($order, 'customer.public_id') ?? 'terminal',
                    $order->public_id . '-balance-' . now()->timestamp
                );
                if (data_get($invoice, 'invoice_id')) {
                    $order->updateMeta('balance_invoice_id', data_get($invoice, 'invoice_id'));
                }

                return data_get($invoice, 'qPay_shortUrl') ?? data_get($invoice, 'urls.0.link');
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return null;
    }

    protected static function qpay(Gateway $gateway): QPay
    {
        $qpay = QPay::instance(data_get($gateway, 'config.username'), data_get($gateway, 'config.password'));
        if ($gateway->sandbox) {
            $qpay->useSandbox();
        }
        $qpay->setAuthToken();

        return $qpay;
    }

    protected static function checkout(Order $order): ?Checkout
    {
        return Checkout::where('order_uuid', $order->uuid)->first()
            ?? ($order->getMeta('checkout_id') ? Checkout::where('public_id', $order->getMeta('checkout_id'))->first() : null);
    }

    protected static function gatewayFor(Order $order, ?Checkout $checkout): ?Gateway
    {
        $uuid = $checkout?->gateway_uuid ?? data_get($order, 'transaction.gateway_uuid');

        return $uuid ? Gateway::where('uuid', $uuid)->first() : null;
    }
}
