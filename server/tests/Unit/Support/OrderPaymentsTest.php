<?php

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\Models\Transaction;
use Fleetbase\Storefront\Support\OrderEditor;
use Fleetbase\Storefront\Support\OrderPayments;
use Fleetbase\Storefront\Support\OrderSettlement;

/** An order as checkout leaves it, without a database: meta, payload and capture transaction. */
function paymentOrder(array $meta = [], array $payload = [], ?array $transaction = null, string $status = 'created'): Order
{
    $order = new Order();
    $order->forceFill(['uuid' => 'order_uuid', 'public_id' => 'order_1', 'status' => $status, 'meta' => array_merge(['total' => 5000, 'currency' => 'USD', 'gateway' => 'stripe'], $meta)]);

    $model = new Payload();
    $model->forceFill($payload);
    $order->setRelation('payload', $model);

    $capture = null;
    if ($transaction !== null) {
        $capture = new Transaction();
        $capture->forceFill($transaction);
    }
    $order->setRelation('transaction', $capture);

    return $order;
}

test('an order paid online is paid in full until something changes it', function () {
    $summary = OrderPayments::summary(paymentOrder());

    expect($summary['state'])->toBe(OrderPayments::STATE_PAID)
        ->and($summary['amount_paid'])->toBe(5000)
        ->and($summary['supports_partial_refund'])->toBeTrue()
        ->and($summary['method_label'])->toBe('Card (Stripe)');
});

test('cash on delivery is to collect until the driver settles it, then paid with what was collected', function () {
    $unpaid = paymentOrder(['gateway' => 'cash'], ['cod_amount' => 5000, 'cod_payment_method' => 'cash'], ['settlement_status' => Transaction::SETTLEMENT_STATUS_UNPAID, 'amount' => 5000]);
    $paid   = paymentOrder(['gateway' => 'cash'], ['cod_amount' => 5000, 'cod_payment_method' => 'cash'], ['settlement_status' => Transaction::SETTLEMENT_STATUS_PAID, 'settled_amount' => 4800, 'amount' => 5000]);

    expect(OrderPayments::summary($unpaid))->toMatchArray(['state' => OrderPayments::STATE_TO_COLLECT, 'amount_paid' => 0, 'cod_amount' => 5000, 'method_label' => 'Cash on delivery'])
        ->and(OrderPayments::summary($paid))->toMatchArray(['state' => OrderPayments::STATE_PAID, 'amount_paid' => 4800]);
});

test('a paid order whose driver collects an extra amount stays a card order with a balance due', function () {
    $order = paymentOrder(['gateway' => 'qpay', 'balance_due' => 700], ['cod_amount' => 700]);

    expect(OrderPayments::isCashOnDelivery($order))->toBeFalse()
        ->and(OrderPayments::summary($order))->toMatchArray(['state' => OrderPayments::STATE_BALANCE_DUE, 'amount_paid' => 5000, 'balance_due' => 700]);
});

test('refunds and credits are measured against what was paid, not the edited total', function () {
    $partly = paymentOrder(['total' => 4000, 'amount_paid' => 5000, 'payment_adjustments' => [['type' => 'refund', 'amount' => 1000]]]);
    $fully  = paymentOrder(['total' => 0, 'amount_paid' => 5000, 'payment_adjustments' => [['type' => 'refund', 'amount' => 5000]]]);
    $credit = paymentOrder(['gateway' => 'qpay', 'amount_paid' => 5000, 'payment_adjustments' => [['type' => 'store_credit', 'amount' => 600, 'reference' => 'CREDIT-AB12']]]);

    expect(OrderPayments::summary($partly))->toMatchArray(['state' => OrderPayments::STATE_PARTIALLY_REFUNDED, 'amount_paid' => 5000, 'amount_refunded' => 1000, 'total' => 4000])
        ->and(OrderPayments::summary($fully)['state'])->toBe(OrderPayments::STATE_REFUNDED)
        ->and(OrderPayments::summary($credit))->toMatchArray(['state' => OrderPayments::STATE_PARTIALLY_REFUNDED, 'amount_credited' => 600, 'store_credit_code' => 'CREDIT-AB12']);
});

test('an order recorded unpaid in the console is unpaid', function () {
    expect(OrderPayments::summary(paymentOrder(['gateway' => 'console', 'payment_status' => 'unpaid']))['state'])->toBe(OrderPayments::STATE_UNPAID);
});

test('a lower total is refunded where the gateway can, else credited', function () {
    expect(OrderSettlement::plan(paymentOrder(), -1200))->toMatchArray(['action' => 'refund', 'amount' => 1200, 'options' => ['refund', 'store_credit']])
        ->and(OrderSettlement::plan(paymentOrder(['gateway' => 'qpay']), -1200))->toMatchArray(['action' => 'store_credit', 'options' => ['store_credit'], 'supports_partial_refund' => false])
        ->and(OrderSettlement::plan(paymentOrder(['gateway' => 'qpay']), -5000))->toMatchArray(['action' => 'refund', 'options' => ['refund', 'store_credit']]);
});

test('canceling gives back what is still paid, by refund where the gateway can, else store credit', function () {
    expect(OrderSettlement::cancelPlan(paymentOrder()))->toMatchArray(['options' => ['refund', 'store_credit'], 'default' => 'refund', 'supports_partial_refund' => true])
        ->and(OrderSettlement::cancelPlan(paymentOrder(['gateway' => 'qpay'])))->toMatchArray(['options' => ['refund', 'store_credit'], 'default' => 'refund', 'supports_partial_refund' => false])
        ->and(OrderSettlement::cancelPlan(paymentOrder(['gateway' => 'cash'], ['cod_amount' => 5000, 'cod_payment_method' => 'cash'], ['settlement_status' => Transaction::SETTLEMENT_STATUS_UNPAID, 'amount' => 5000])))
        ->toMatchArray(['refundable' => 0, 'options' => [], 'default' => null]);
});

test('a higher total asks for a payment link, and cash orders just change what the driver collects', function () {
    $cash = paymentOrder(['gateway' => 'cash'], ['cod_amount' => 5000, 'cod_payment_method' => 'cash'], ['settlement_status' => Transaction::SETTLEMENT_STATUS_UNPAID]);

    expect(OrderSettlement::plan(paymentOrder(), 800))->toMatchArray(['action' => 'payment_link', 'options' => ['payment_link', 'collect']])
        ->and(OrderSettlement::plan($cash, 800))->toMatchArray(['action' => 'cod_update', 'amount' => 800])
        ->and(OrderSettlement::plan($cash, -800))->toMatchArray(['action' => 'cod_update', 'amount' => -800])
        ->and(OrderSettlement::plan(paymentOrder(), 0)['action'])->toBe('none');
});

test('what an edit may change narrows as the order moves on', function () {
    expect(OrderEditor::stage(paymentOrder(status: 'preparing')))->toBe(OrderEditor::STAGE_OPEN)
        ->and(OrderEditor::locks(paymentOrder(status: 'preparing')))->toBe([])
        ->and(OrderEditor::locks(paymentOrder(status: 'ready')))->toMatchArray([
            'items'    => ['rule' => 'removal_only', 'reason' => 'The order is ready; items can only be removed.'],
            'schedule' => ['rule' => 'earlier_only', 'reason' => 'The order is ready; it can only be moved earlier.'],
        ])
        ->and(array_keys(OrderEditor::locks(paymentOrder(status: 'driver_enroute'))))->toBe(['items', 'schedule'])
        ->and(OrderEditor::stage(paymentOrder(status: 'completed')))->toBe(OrderEditor::STAGE_CLOSED)
        ->and(array_keys(OrderEditor::locks(paymentOrder(status: 'canceled'))))->toBe(OrderEditor::FIELDS);
});

test('a balance with no link issued, or nothing due, is not checked with the gateway', function () {
    expect(OrderSettlement::checkBalancePayment(paymentOrder(['balance_due' => 0, 'balance_payment' => ['reference' => 'plink_1']])))->toBeNull()
        ->and(OrderSettlement::checkBalancePayment(paymentOrder(['balance_due' => 1200])))->toBeNull();
});

test('a balance invoice tells QPay to call back for its order', function () {
    expect(OrderSettlement::balanceCallbackUrl(paymentOrder()))->toContain('/v1/orders/balance-paid')->toContain('order=order_1');
});
