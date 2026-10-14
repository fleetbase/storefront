<?php

require_once __DIR__ . '/../../Support/OrderSettlementTestHelpers.php';

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Models\Transaction;
use Fleetbase\Storefront\Support\OrderPayments;

/** An order whose meta is handed over as a collection rather than an array. */
class SettlementCoverageCollectionMetaOrder extends Order
{
    public function getMetaAttribute($value)
    {
        return collect(json_decode((string) $value, true) ?: []);
    }
}

beforeEach(function () {
    settlementCoverageSchema();
});

/** A cash order whose driver collects `cod_amount`, with its capture transaction. */
function settlementCoverageCashOrder(array $capture = [], int $codAmount = 5200): Order
{
    settlementCoverageDb()->table('payloads')->insert(['uuid' => 'settle_payload_uuid', 'cod_amount' => $codAmount, 'cod_payment_method' => 'cash']);
    settlementCoverageDb()->table('transactions')->insert(array_merge([
        'uuid'              => 'settle_capture_uuid',
        'public_id'         => 'transaction_capture',
        'gateway'           => 'cash',
        'amount'            => 5000,
        'currency'          => 'USD',
        'status'            => Transaction::STATUS_PENDING,
        'settlement_status' => Transaction::SETTLEMENT_STATUS_UNPAID,
    ], $capture));

    return settlementCoverageOrder(['gateway' => 'cash'], ['payload_uuid' => 'settle_payload_uuid', 'transaction_uuid' => 'settle_capture_uuid']);
}

test('a completed cash order settles its capture with what the driver collected', function () {
    $order = settlementCoverageCashOrder();

    expect(OrderPayments::summary($order)['state'])->toBe(OrderPayments::STATE_TO_COLLECT);

    OrderPayments::settleCollected($order);
    $capture = settlementCoverageDb()->table('transactions')->where('uuid', 'settle_capture_uuid')->first();

    expect($capture->status)->toBe(Transaction::STATUS_SUCCESS)
        ->and($capture->settlement_status)->toBe(Transaction::SETTLEMENT_STATUS_PAID)
        ->and((int) $capture->settled_amount)->toBe(5200)
        ->and($capture->settled_currency)->toBe('USD')
        ->and($order->transaction->settlement_status)->toBe(Transaction::SETTLEMENT_STATUS_PAID)
        // The order's payment now reads as paid, with what was actually collected.
        ->and(settlementCoverageStoredMeta()['payment'])->toMatchArray(['state' => OrderPayments::STATE_PAID, 'amount_paid' => 5200, 'method_label' => 'Cash on delivery']);
});

test('a cash order without an amount to collect settles for its capture amount', function () {
    $order = settlementCoverageCashOrder([], 0);

    OrderPayments::settleCollected($order);

    expect((int) settlementCoverageDb()->table('transactions')->where('uuid', 'settle_capture_uuid')->value('settled_amount'))->toBe(5000);
});

test('a cash capture already settled, or missing, is left alone', function () {
    $settled = settlementCoverageCashOrder(['status' => Transaction::STATUS_SUCCESS, 'settlement_status' => Transaction::SETTLEMENT_STATUS_PAID, 'settled_amount' => 4800]);

    OrderPayments::settleCollected($settled);

    $missing = settlementCoverageOrder(['gateway' => 'cash'], ['uuid' => 'settle_missing_uuid', 'public_id' => 'order_missing', 'transaction_uuid' => 'no_such_transaction']);
    OrderPayments::settleCollected($missing);

    expect((int) settlementCoverageDb()->table('transactions')->where('uuid', 'settle_capture_uuid')->value('settled_amount'))->toBe(4800)
        ->and(settlementCoverageDb()->table('transactions')->count())->toBe(1)
        ->and(settlementCoverageStoredMeta())->not->toHaveKey('payment')
        ->and(settlementCoverageStoredMeta('settle_missing_uuid'))->not->toHaveKey('payment');
});

test('completing a paid order whose driver collected an extra settles that balance in cash', function () {
    settlementCoverageDb()->table('payloads')->insert(['uuid' => 'settle_payload_uuid', 'cod_amount' => 900]);
    $order = settlementCoverageOrder(['balance_due' => 700], ['payload_uuid' => 'settle_payload_uuid']);

    OrderPayments::settleCollected($order);
    $meta   = settlementCoverageStoredMeta();
    $ledger = settlementCoverageDb()->table('transactions')->first();

    // Never more than the balance is counted, whatever the driver was told to collect.
    expect($meta['balance_due'])->toBe(0)
        ->and($meta['amount_paid'])->toBe(5700)
        ->and($meta['payment_adjustments'][0])->toMatchArray(['type' => 'charge', 'amount' => 700, 'gateway' => 'cash', 'reason' => 'Collected on delivery'])
        ->and($meta['payment'])->toMatchArray(['state' => OrderPayments::STATE_PAID, 'amount_paid' => 5700, 'method_label' => 'Card (Stripe)'])
        ->and($ledger->type)->toBe(Transaction::TYPE_GATEWAY_CHARGE)
        ->and($ledger->direction)->toBe(Transaction::DIRECTION_CREDIT)
        ->and($ledger->gateway)->toBe('cash')
        ->and((int) $ledger->amount)->toBe(700)
        ->and($ledger->description)->toBe('Storefront order charge')
        ->and(json_decode($ledger->meta, true))->toMatchArray(['order_id' => 'order_settle', 'storefront_id' => 'store_settle', 'parent_transaction' => null]);
});

test('completing a paid order with nothing extra collected, or nothing due, changes nothing', function (int $cod, int $balance) {
    settlementCoverageDb()->table('payloads')->insert(['uuid' => 'settle_payload_uuid', 'cod_amount' => $cod]);
    $order = settlementCoverageOrder(['balance_due' => $balance], ['payload_uuid' => 'settle_payload_uuid']);

    OrderPayments::settleCollected($order);

    expect(settlementCoverageStoredMeta()['balance_due'])->toBe($balance)
        ->and(settlementCoverageStoredMeta())->not->toHaveKey('payment_adjustments')
        ->and(settlementCoverageDb()->table('transactions')->count())->toBe(0);
})->with([
    'no cash collected' => [0, 700],
    'no balance due'    => [700, 0],
]);

test('an order recorded unpaid pins nothing paid before its first adjustment, and a zero amount writes no transaction', function () {
    $order = settlementCoverageOrder(['gateway' => 'console', 'payment_status' => 'unpaid']);

    $adjustment = OrderPayments::recordAdjustment($order, 'refund', 0, ['by' => 'settle_manager_uuid', 'reason' => 'Recount']);
    $meta       = settlementCoverageStoredMeta();

    expect($adjustment)->toMatchArray(['type' => 'refund', 'amount' => 0, 'gateway' => 'console', 'by' => 'settle_manager_uuid', 'reason' => 'Recount'])
        ->and($adjustment)->not->toHaveKey('reference')
        ->and($meta['amount_paid'])->toBe(0)
        ->and($meta['payment'])->toMatchArray(['state' => OrderPayments::STATE_UNPAID, 'method_label' => 'Recorded in the console'])
        ->and(settlementCoverageDb()->table('transactions')->count())->toBe(0);
});

test('an order without a total pins what its capture transaction charged', function () {
    settlementCoverageDb()->table('transactions')->insert(['uuid' => 'settle_capture_uuid', 'public_id' => 'transaction_capture', 'gateway' => 'qpay', 'amount' => 8000, 'currency' => 'MNT']);
    $order = settlementCoverageOrder(['gateway' => null, 'total' => null, 'currency' => null], ['transaction_uuid' => 'settle_capture_uuid']);

    OrderPayments::rememberAmountPaid($order);
    OrderPayments::rememberAmountPaid($order);

    expect(settlementCoverageStoredMeta()['amount_paid'])->toBe(8000)
        ->and(OrderPayments::summary($order))->toMatchArray(['gateway' => 'qpay', 'method_label' => 'QPay', 'currency' => 'MNT', 'amount_paid' => 8000]);
});

test('every payment method has a label people recognise', function (?string $gateway, string $label) {
    $order = new Order();
    $order->forceFill(['meta' => ['total' => 1000, 'gateway' => $gateway]]);
    $order->setRelation('payload', null);
    $order->setRelation('transaction', null);

    expect(OrderPayments::summary($order)['method_label'])->toBe($label);
})->with([
    'qpay'    => ['qpay', 'QPay'],
    'stripe'  => ['stripe', 'Card (Stripe)'],
    'paypal'  => ['paypal', 'PayPal'],
    'console' => ['console', 'Recorded in the console'],
    'unknown' => [null, 'Unknown'],
    'empty'   => ['', 'Unknown'],
    'other'   => ['square', 'Square'],
]);

test('meta handed over as a collection is read like an array', function () {
    $order = new SettlementCoverageCollectionMetaOrder();
    $order->setRawAttributes(['meta' => json_encode(['total' => 3000, 'gateway' => 'stripe', 'currency' => 'EUR', 'payment_adjustments' => [['type' => 'refund', 'amount' => 3000]]])]);
    $order->setRelation('payload', null);
    $order->setRelation('transaction', null);

    expect(OrderPayments::summary($order))->toMatchArray(['state' => OrderPayments::STATE_REFUNDED, 'total' => 3000, 'currency' => 'EUR', 'amount_refunded' => 3000])
        ->and(OrderPayments::adjustments($order))->toBe([['type' => 'refund', 'amount' => 3000]]);
});
