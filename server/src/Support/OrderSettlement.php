<?php

namespace Fleetbase\Storefront\Support;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Storefront\Models\Checkout;
use Fleetbase\Storefront\Models\Gateway;
use Fleetbase\Storefront\Models\Promotion;
use Fleetbase\Storefront\Models\PromotionCode;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Notifications\StorefrontOrderBalancePaid;
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
        if ($method === static::METHOD_COLLECT) {
            $order->updateMeta('balance_due', $balance);

            return static::updateCashToCollect($order, $amount, $reason);
        }

        // The link first: a gateway that cannot issue one leaves the order as it was.
        $link = static::issueBalanceLink($order, $balance);
        $order->updateMeta('balance_due', $balance);
        OrderPayments::snapshot($order);

        return ['type' => 'balance_due', 'amount' => $amount, 'payment_link' => $link];
    }

    /**
     * Issue a payment link for the whole balance due (replacing an earlier one) and keep it on the
     * order, where the console, the customer's receipt and the edit notification read it.
     *
     * @throws \RuntimeException when the gateway cannot issue one
     */
    public static function issueBalanceLink(Order $order, ?int $amount = null): string
    {
        $amount = $amount ?? (int) $order->getMeta('balance_due', 0);
        if ($amount <= 0) {
            throw new \RuntimeException('Nothing is due on this order.');
        }

        static::retireBalanceLink($order);
        $link = static::balancePaymentLink($order, $amount);
        $order->updateMeta('balance_payment_link', $link['url']);
        $order->updateMeta('balance_payment', ['gateway' => $link['gateway'], 'reference' => $link['reference'], 'amount' => $amount, 'issued_at' => now()->toIso8601String()]);
        OrderPayments::snapshot($order);
        static::announcePayment($order, 'A payment link for the balance is ready');

        return $link['url'];
    }

    /**
     * Whether the customer paid the balance through its link; when they did, the balance is
     * settled: it is added to what was paid, recorded as a charge, and the link is retired.
     * Asked by QPay's callback, the console's "Check payment" and a scheduled sweep, because a
     * Stripe Payment Link has no callback of its own here.
     *
     * @return array|null the recorded charge, or null when nothing was paid (yet)
     */
    public static function checkBalancePayment(Order $order): ?array
    {
        $balance = (int) $order->getMeta('balance_due', 0);
        $issued  = (array) $order->getMeta('balance_payment', []);
        if ($balance <= 0 || empty($issued['reference'])) {
            return null;
        }

        $gateway = static::gatewayFor($order, static::checkout($order));
        if (!$gateway) {
            return null;
        }

        [$paid, $reference] = match (true) {
            $gateway->isStripeGateway => static::stripeLinkPayment($gateway, (string) $issued['reference']),
            $gateway->isQPayGateway   => static::qpayInvoicePayment($gateway, (string) $issued['reference']),
            default                   => [0, null],
        };

        if ($paid < (int) ($issued['amount'] ?? $balance)) {
            return null;
        }

        return static::settleBalance($order, min($paid, $balance), $gateway->code, $reference);
    }

    /**
     * The balance was paid: count it as paid and close the link.
     */
    public static function settleBalance(Order $order, int $amount, ?string $gateway, ?string $reference): array
    {
        // Recording the charge adds it to what was paid.
        $order->updateMeta('balance_due', max(0, (int) $order->getMeta('balance_due', 0) - $amount));
        // A paid Stripe Payment Link stays payable until it is deactivated; a paid QPay invoice is done.
        if ($gateway === 'stripe') {
            static::retireBalanceLink($order);
        }
        $order->updateMeta('balance_payment_link', null);
        $order->updateMeta('balance_payment', null);

        $charge = OrderPayments::recordAdjustment($order, 'charge', $amount, ['gateway' => $gateway, 'reference' => $reference, 'reason' => 'Balance paid by link']);
        static::announcePayment($order, 'The balance is paid');

        // A receipt for the customer.
        try {
            $order->loadMissing('customer');
            $order->customer?->notify(new StorefrontOrderBalancePaid($order, $amount));
        } catch (\Throwable $e) {
            report($e);
        }

        return $charge;
    }

    /**
     * Tell the apps the order's payment changed (`order.changed` with `payment`), so a customer
     * looking at the order sees the link or the paid balance without reopening it.
     */
    protected static function announcePayment(Order $order, string $summary): void
    {
        try {
            if (class_exists(\Fleetbase\FleetOps\Events\OrderChanged::class) && method_exists(\Fleetbase\FleetOps\Events\OrderChanged::class, 'dispatchFor')) {
                \Fleetbase\FleetOps\Events\OrderChanged::dispatchFor($order, ['payment'], $summary, ['currency' => $order->getMeta('currency')], false);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Stop an earlier balance link being paid: deactivate the Stripe Payment Link (a QPay
     * invoice is cancelled). Best effort; a link that cannot be retired is simply replaced.
     */
    protected static function retireBalanceLink(Order $order): void
    {
        $issued = (array) $order->getMeta('balance_payment', []);
        if (empty($issued['reference'])) {
            return;
        }

        try {
            $gateway = static::gatewayFor($order, static::checkout($order));
            if ($gateway?->isStripeGateway && data_get($gateway, 'config.secret_key')) {
                \Stripe\Stripe::setApiKey($gateway->config->secret_key);
                \Stripe\PaymentLink::update($issued['reference'], ['active' => false]);
            } elseif ($gateway?->isQPayGateway) {
                static::qpay($gateway)->delete('invoice/' . $issued['reference']);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * What was paid through a Stripe Payment Link: its completed, paid Checkout Sessions.
     *
     * @return array{0: int, 1: ?string}
     */
    protected static function stripeLinkPayment(Gateway $gateway, string $linkId): array
    {
        \Stripe\Stripe::setApiKey($gateway->config->secret_key);
        $sessions = \Stripe\Checkout\Session::all(['payment_link' => $linkId, 'limit' => 20]);
        $paid     = collect($sessions->data ?? [])->filter(fn ($session) => $session->payment_status === 'paid');

        // A customer paying in their own currency (adaptive pricing) is charged in that currency;
        // the amount in the link's currency is under `currency_conversion`.
        $amount = $paid->sum(fn ($session) => (int) (data_get($session, 'currency_conversion.amount_total') ?? $session->amount_total));

        return [(int) $amount, $paid->first()?->payment_intent];
    }

    /**
     * What was paid against a QPay invoice, from QPay's payment check (never the callback's body).
     *
     * @return array{0: int, 1: ?string}
     */
    protected static function qpayInvoicePayment(Gateway $gateway, string $invoiceId): array
    {
        $check = static::qpay($gateway)->paymentCheck($invoiceId);
        $rows  = collect(data_get($check, 'rows', []))->filter(fn ($row) => data_get($row, 'payment_status') === 'PAID');

        return [(int) round((float) $rows->sum(fn ($row) => (float) data_get($row, 'payment_amount', 0))), data_get($rows->first(), 'payment_id')];
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
     *
     * @return array{url: string, gateway: string, reference: string}
     *
     * @throws \RuntimeException when the gateway cannot issue one
     */
    protected static function balancePaymentLink(Order $order, int $amount): array
    {
        $checkout = static::checkout($order);
        $gateway  = static::gatewayFor($order, $checkout);
        $currency = strtolower((string) ($order->getMeta('currency') ?? 'usd'));
        $label    = 'Balance for order ' . ($order->tracking ?? $order->public_id);

        if ($gateway?->isStripeGateway && data_get($gateway, 'config.secret_key')) {
            try {
                \Stripe\Stripe::setApiKey($gateway->config->secret_key);
                $price = \Stripe\Price::create(['currency' => $currency, 'unit_amount' => $amount, 'product_data' => ['name' => $label]]);
                $link  = \Stripe\PaymentLink::create([
                    'line_items' => [['price' => $price->id, 'quantity' => 1]],
                    'metadata'   => ['order' => $order->public_id, 'kind' => 'order_balance'],
                ]);
            } catch (\Throwable $e) {
                throw new \RuntimeException('Stripe could not create the payment link: ' . $e->getMessage());
            }

            return ['url' => $link->url, 'gateway' => 'stripe', 'reference' => $link->id];
        }

        if ($gateway?->isQPayGateway) {
            // The same merchant invoice code checkout uses (TEST_INVOICE in sandbox).
            $invoiceCode = $gateway->sandbox ? 'TEST_INVOICE' : data_get($gateway, 'config.invoice_id');
            if (!$invoiceCode) {
                throw new \RuntimeException('The QPay gateway has no invoice code, so it cannot issue a payment link.');
            }

            $invoice = static::qpay($gateway)->createSimpleInvoice(
                (int) $amount,
                $invoiceCode,
                $label,
                data_get($order, 'customer.public_id') ?? 'terminal',
                $order->public_id . '-balance-' . now()->timestamp,
                static::balanceCallbackUrl($order)
            );
            $url = data_get($invoice, 'qPay_shortUrl') ?? data_get($invoice, 'urls.0.link');
            if (!data_get($invoice, 'invoice_id') || !$url) {
                throw new \RuntimeException('QPay did not create the invoice' . (data_get($invoice, 'message') ? ': ' . data_get($invoice, 'message') : '.'));
            }

            return ['url' => $url, 'gateway' => 'qpay', 'reference' => (string) data_get($invoice, 'invoice_id')];
        }

        throw new \RuntimeException('This payment method cannot issue a payment link; let the driver collect it instead.');
    }

    /**
     * Where QPay tells us a balance invoice was paid.
     */
    public static function balanceCallbackUrl(Order $order): string
    {
        $prefix = trim((string) config('storefront.api.routing.prefix', 'storefront'), '/');

        return \Fleetbase\Support\Utils::apiUrl($prefix . '/v1/orders/balance-paid', ['order' => $order->public_id]);
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
