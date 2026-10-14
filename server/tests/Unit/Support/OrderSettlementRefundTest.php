<?php

require_once __DIR__ . '/../../Support/OrderSettlementTestHelpers.php';

use Fleetbase\Models\Transaction;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Support\OrderPayments;
use Fleetbase\Storefront\Support\OrderSettlement;
use GuzzleHttp\Psr7\Response;

beforeEach(function () {
    settlementCoverageSchema();
    SettlementCoverageProbe::$qpay = null;
    session(['user' => 'settle_user_uuid']);
});

afterEach(function () {
    settlementCoverageRestoreStripe();
    SettlementCoverageProbe::$qpay = null;
    session(['user' => null]);
});

test('refunding nothing changes nothing', function () {
    $order = settlementCoverageOrder();

    expect(OrderSettlement::refund($order, 0))->toBe([])
        ->and(OrderSettlement::refund($order, -100, OrderSettlement::METHOD_STORE_CREDIT))->toBe([])
        ->and(settlementCoverageStoredMeta())->not->toHaveKey('payment_adjustments')
        ->and(settlementCoverageDb()->table('transactions')->count())->toBe(0);
});

test('store credit is a single-use code only this customer can spend at the same store', function () {
    $order = settlementCoverageOrder(['currency' => null]);

    $credit = OrderSettlement::refund($order, 1500, OrderSettlement::METHOD_STORE_CREDIT, 'A pastry was missing');

    $promotion = settlementCoverageDb()->table('promotions')->first();
    $code      = settlementCoverageDb()->table('promotion_codes')->first();
    $ledger    = settlementCoverageDb()->table('transactions')->first();
    $meta      = settlementCoverageStoredMeta();

    expect($credit)->toMatchArray(['type' => 'store_credit', 'amount' => 1500, 'gateway' => 'store_credit', 'reason' => 'A pastry was missing', 'by' => 'settle_user_uuid'])
        ->and($credit['reference'])->toMatch('/^CREDIT-[A-Z0-9]{8}$/')
        // The promotion belongs to the store the order came from, in its currency.
        ->and($promotion->owner_uuid)->toBe('settle_store_uuid')
        ->and($promotion->owner_type)->toBe(Store::class)
        ->and($promotion->currency)->toBe('MNT')
        ->and($promotion->created_by_uuid)->toBe('settle_user_uuid')
        ->and($promotion->name)->toBe('Store credit for order_settle')
        ->and((float) $promotion->value)->toBe(1500.0)
        ->and((int) $promotion->usage_limit)->toBe(1)
        ->and((int) $promotion->usage_limit_per_customer)->toBe(1)
        ->and((bool) $promotion->is_public)->toBeFalse()
        ->and(json_decode($promotion->meta, true))->toBe(['store_credit' => true, 'order_id' => 'order_settle'])
        // The code is the reference recorded on the order, for this customer only.
        ->and($code->code)->toBe($credit['reference'])
        ->and($code->customer_uuid)->toBe('settle_customer_uuid')
        ->and((int) $code->usage_limit)->toBe(1)
        ->and($ledger->type)->toBe(Transaction::TYPE_ADJUSTMENT)
        ->and($ledger->direction)->toBe(Transaction::DIRECTION_DEBIT)
        ->and((int) $ledger->amount)->toBe(1500)
        ->and($ledger->gateway_transaction_id)->toBe($credit['reference'])
        ->and($ledger->description)->toBe('Storefront order store credit')
        // What was charged is pinned before the credit, so the order reads as partly given back.
        ->and($meta['amount_paid'])->toBe(5000)
        ->and($meta['payment'])->toMatchArray(['state' => OrderPayments::STATE_PARTIALLY_REFUNDED, 'amount_credited' => 1500, 'store_credit_code' => $credit['reference']]);
});

test('a payment method without refunds is refused and nothing is recorded', function (?string $gateway, string $message) {
    $order = settlementCoverageOrder(['gateway' => $gateway]);

    expect(fn () => OrderSettlement::refund($order, 500))->toThrow(RuntimeException::class, $message)
        ->and(settlementCoverageStoredMeta())->not->toHaveKey('payment_adjustments');
})->with([
    'paypal'         => ['paypal', 'Refunds to paypal are not supported; give store credit instead.'],
    'unknown method' => [null, 'Refunds to this payment method are not supported; give store credit instead.'],
]);

test('a stripe refund returns part of the payment intent checkout captured', function () {
    $gateway = settlementCoverageGateway('stripe', ['secret_key' => 'sk_test_settlement']);
    settlementCoverageCheckout(['stripe_payment_intent_id' => 'pi_settle']);
    $stripe = settlementCoverageStripe(['POST /v1/refunds' => [200, ['id' => 're_settle', 'object' => 'refund', 'amount' => 1200]]]);
    $order  = settlementCoverageOrder();
    $order->setRelation('trackingNumber', (object) ['tracking_number' => 'TRK-1001']);

    $refund = OrderSettlement::refund($order, 1200, OrderSettlement::METHOD_REFUND, 'Late delivery');
    $ledger = settlementCoverageDb()->table('transactions')->first();

    expect($gateway->isStripeGateway)->toBeTrue()
        ->and($refund)->toMatchArray(['type' => 'refund', 'amount' => 1200, 'reference' => 're_settle', 'gateway' => 'stripe', 'reason' => 'Late delivery'])
        ->and($stripe->paths())->toBe(['POST /v1/refunds'])
        ->and($stripe->requests[0]['params'])->toMatchArray(['payment_intent' => 'pi_settle', 'amount' => 1200, 'metadata' => ['order' => 'order_settle']])
        ->and($ledger->type)->toBe(Transaction::TYPE_GATEWAY_REFUND)
        ->and($ledger->gateway_transaction_id)->toBe('re_settle')
        ->and(settlementCoverageStoredMeta()['payment'])->toMatchArray(['state' => OrderPayments::STATE_PARTIALLY_REFUNDED, 'amount_refunded' => 1200, 'amount_paid' => 5000]);
});

test('a stripe refund finds the checkout by the id on the order and the intent on its transaction', function () {
    settlementCoverageGateway('stripe', ['secret_key' => 'sk_test_settlement']);
    // A checkout saved before it was linked to its order: found by meta.checkout_id instead.
    settlementCoverageCheckout(['order_uuid' => null]);
    settlementCoverageDb()->table('transactions')->insert(['uuid' => 'settle_capture_uuid', 'public_id' => 'transaction_capture', 'gateway' => 'stripe', 'gateway_uuid' => 'settle_gateway_uuid', 'gateway_transaction_id' => 'pi_from_capture', 'amount' => 5000, 'currency' => 'USD']);
    $stripe = settlementCoverageStripe(['POST /v1/refunds' => [200, ['id' => 're_capture', 'object' => 'refund']]]);
    $order  = settlementCoverageOrder(['checkout_id' => 'checkout_settle'], ['transaction_uuid' => 'settle_capture_uuid']);

    $refund = OrderSettlement::refund($order, 5000);
    $ledger = settlementCoverageDb()->table('transactions')->where('gateway_transaction_id', 're_capture')->first();

    expect($refund['reference'])->toBe('re_capture')
        ->and($stripe->requests[0]['params']['payment_intent'])->toBe('pi_from_capture')
        // The refund is linked to the capture it gives money back from.
        ->and($ledger->gateway_uuid)->toBe('settle_gateway_uuid')
        ->and(json_decode($ledger->meta, true)['parent_transaction'])->toBe('transaction_capture')
        ->and(settlementCoverageStoredMeta()['payment']['state'])->toBe(OrderPayments::STATE_REFUNDED);
});

test('a stripe refund needs a gateway with a key and a payment to refund', function (array $config, ?string $intent) {
    settlementCoverageGateway('stripe', $config);
    settlementCoverageCheckout(['stripe_payment_intent_id' => $intent]);
    $stripe = settlementCoverageStripe([]);

    expect(fn () => OrderSettlement::refund(settlementCoverageOrder(), 1000))->toThrow(RuntimeException::class, 'This order has no Stripe payment to refund.')
        ->and($stripe->requests)->toBe([]);
})->with([
    'no secret key'      => [[], 'pi_settle'],
    'no payment intent'  => [['secret_key' => 'sk_test_settlement'], null],
]);

test('a stripe refund without any checkout or capture is refused', function () {
    expect(fn () => OrderSettlement::refund(settlementCoverageOrder(), 1000))->toThrow(RuntimeException::class, 'This order has no Stripe payment to refund.');
});

test('qpay gives back whole payments only', function () {
    $history                       = [];
    SettlementCoverageProbe::$qpay = settlementCoverageQPay([], $history);
    $order                         = settlementCoverageOrder(['gateway' => 'qpay']);

    expect(fn () => SettlementCoverageProbe::refund($order, 4999))->toThrow(RuntimeException::class, 'QPay refunds whole payments only; give store credit for part of it.')
        ->and($history)->toBe([]);
});

test('a qpay refund needs the payment checkout recorded', function () {
    $history                       = [];
    SettlementCoverageProbe::$qpay = settlementCoverageQPay([], $history);
    settlementCoverageGateway('qpay', ['username' => 'settle-merchant', 'password' => 'settle-secret']);
    settlementCoverageCheckout(['options' => json_encode(['qpay_invoice_id' => 'inv_settle'])]);

    expect(fn () => SettlementCoverageProbe::refund(settlementCoverageOrder(['gateway' => 'qpay']), 5000))->toThrow(RuntimeException::class, 'This order has no QPay payment to refund.')
        ->and($history)->toBe([]);
});

test('a qpay refund QPay refuses is reported with its reason and not recorded', function (Response $response, string $message) {
    $history                       = [];
    SettlementCoverageProbe::$qpay = settlementCoverageQPay([$response], $history);
    settlementCoverageGateway('qpay', ['username' => 'settle-merchant', 'password' => 'settle-secret']);
    settlementCoverageCheckout(['options' => json_encode(['qpay_payment' => ['payment_id' => 'qpay_payment_1']])]);
    $order = settlementCoverageOrder(['gateway' => 'qpay']);

    expect(fn () => SettlementCoverageProbe::refund($order, 5000))->toThrow(RuntimeException::class, $message)
        ->and($history)->toHaveCount(1)
        ->and(settlementCoverageStoredMeta())->not->toHaveKey('payment_adjustments');
})->with([
    'an error with a message' => [new Response(400, [], '{"error":"PAYMENT_NOT_PAID","message":"Payment is not paid"}'), 'QPay did not accept the refund: Payment is not paid'],
    'an error without one'    => [new Response(400, [], '{"error":"PAYMENT_NOT_PAID"}'), 'QPay did not accept the refund.'],
    'no answer'               => [new Response(502, [], ''), 'QPay did not accept the refund.'],
]);

test('a qpay refund returns the whole payment and records it against the order', function () {
    $history                       = [];
    SettlementCoverageProbe::$qpay = settlementCoverageQPay([new Response(200, [], '{}')], $history);
    settlementCoverageGateway('qpay', ['username' => 'settle-merchant', 'password' => 'settle-secret']);
    settlementCoverageCheckout(['options' => json_encode(['qpay_payment' => ['payment_id' => 'qpay_payment_1']])]);
    $order = settlementCoverageOrder(['gateway' => 'qpay']);

    $refund = SettlementCoverageProbe::refund($order, 5000, OrderSettlement::METHOD_REFUND, 'Store closed');

    expect($refund)->toMatchArray(['type' => 'refund', 'amount' => 5000, 'reference' => 'qpay_payment_1', 'gateway' => 'qpay'])
        ->and($history[0]['request']->getMethod())->toBe('DELETE')
        ->and((string) $history[0]['request']->getUri())->toEndWith('/v2/payment/refund/qpay_payment_1')
        ->and(settlementCoverageQPayBody($history[0]))->toMatchArray(['note' => 'Refund for order_settle'])
        ->and(settlementCoverageStoredMeta()['payment']['state'])->toBe(OrderPayments::STATE_REFUNDED);
});

test('the qpay client settlement uses is the gateway merchant, in its environment, authenticated', function () {
    settlementCoverageGateway('qpay', ['username' => 'settle-sandbox-merchant', 'password' => 'settle-sandbox-secret'], true);
    $gateway = Fleetbase\Storefront\Models\Gateway::where('uuid', 'settle_gateway_uuid')->first();
    // A token QPay issued earlier is still good, so none is requested.
    Illuminate\Support\Facades\Cache::put(
        'storefront:qpay:token:' . sha1('https://merchant-sandbox.qpay.mn/|settle-sandbox-merchant|settle-sandbox-secret'),
        ['token' => 'sandbox-token', 'expires_at' => time() + 86400],
        3600
    );

    $qpay = SettlementCoverageProbe::realQPay($gateway);

    expect((string) $qpay->getClient()->getConfig('base_uri'))->toBe('https://merchant-sandbox.qpay.mn/v2/')
        ->and($qpay->getClient()->getConfig('headers')['Authorization'])->toBe('Bearer sandbox-token');
});
