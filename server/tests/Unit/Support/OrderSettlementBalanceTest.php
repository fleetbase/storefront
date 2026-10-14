<?php

require_once __DIR__ . '/../../Support/OrderSettlementTestHelpers.php';

use Fleetbase\FleetOps\Events\OrderChanged;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Models\Transaction;
use Fleetbase\Storefront\Notifications\StorefrontOrderBalancePaid;
use Fleetbase\Storefront\Support\OrderPayments;
use Fleetbase\Storefront\Support\OrderSettlement;
use GuzzleHttp\Psr7\Response;

/** An order whose currency cannot be read: anything announced or receipted about it fails. */
class SettlementCoverageUnreadableOrder extends Order
{
    public function getMeta($key = null, $defaultValue = null)
    {
        if ($key === 'currency') {
            throw new RuntimeException('Currency unreadable');
        }

        return parent::getMeta($key, $defaultValue);
    }
}

beforeEach(function () {
    settlementCoverageSchema();
    SettlementCoverageProbe::$qpay = null;
    if (settlementCoverageRecordsOrderChanged()) {
        OrderChanged::$dispatched = [];
    }
});

afterEach(function () {
    settlementCoverageRestoreStripe();
    SettlementCoverageProbe::$qpay = null;
});

/** A Stripe Payment Link answered by the Stripe double. */
function settlementCoverageStripeLinkRoutes(array $extra = []): array
{
    return array_merge([
        'POST /v1/prices'        => [200, ['id' => 'price_balance', 'object' => 'price']],
        'POST /v1/payment_links' => [200, ['id' => 'plink_balance', 'object' => 'payment_link', 'url' => 'https://buy.stripe.com/balance']],
    ], $extra);
}

test('charging nothing changes nothing', function () {
    $order = settlementCoverageOrder();

    expect(OrderSettlement::charge($order, 0, OrderSettlement::METHOD_PAYMENT_LINK))->toBe([])
        ->and(settlementCoverageStoredMeta())->not->toHaveKey('balance_due');
});

test('an extra on a cash order is added to what the driver collects', function () {
    settlementCoverageDb()->table('payloads')->insert(['uuid' => 'settle_payload_uuid', 'cod_amount' => 5000, 'cod_payment_method' => 'cash']);
    $order = settlementCoverageOrder(['gateway' => 'cash'], ['payload_uuid' => 'settle_payload_uuid']);

    $change = OrderSettlement::charge($order, 700, OrderSettlement::METHOD_PAYMENT_LINK, 'Added a drink');

    expect($change)->toMatchArray(['type' => 'cod_update', 'amount' => 700, 'gateway' => 'cash', 'reason' => 'Added a drink'])
        ->and((int) settlementCoverageDb()->table('payloads')->where('uuid', 'settle_payload_uuid')->value('cod_amount'))->toBe(5700)
        // Collecting more cash is not money moved yet: no transaction is written for it.
        ->and(settlementCoverageDb()->table('transactions')->count())->toBe(0)
        ->and(settlementCoverageStoredMeta()['payment'])->toMatchArray(['state' => OrderPayments::STATE_TO_COLLECT, 'cod_amount' => 5700]);
});

test('lowering the cash to collect never goes below zero, and an order without a payload still records it', function () {
    settlementCoverageDb()->table('payloads')->insert(['uuid' => 'settle_payload_uuid', 'cod_amount' => 400, 'cod_payment_method' => 'cash']);
    $withPayload    = settlementCoverageOrder(['gateway' => 'cash'], ['payload_uuid' => 'settle_payload_uuid']);
    $withoutPayload = settlementCoverageOrder(['gateway' => 'cash'], ['uuid' => 'settle_bare_uuid', 'public_id' => 'order_bare']);

    $lowered = OrderSettlement::updateCashToCollect($withPayload, -900);
    $bare    = OrderSettlement::updateCashToCollect($withoutPayload, -300, 'Removed an item');

    expect($lowered['amount'])->toBe(-900)
        ->and((int) settlementCoverageDb()->table('payloads')->where('uuid', 'settle_payload_uuid')->value('cod_amount'))->toBe(0)
        ->and($bare)->toMatchArray(['type' => 'cod_update', 'amount' => -300, 'reason' => 'Removed an item'])
        ->and(settlementCoverageStoredMeta('settle_bare_uuid')['payment_adjustments'])->toHaveCount(1);
});

test('an extra on a paid order the driver collects is a balance due, collected in cash', function () {
    settlementCoverageDb()->table('payloads')->insert(['uuid' => 'settle_payload_uuid', 'cod_amount' => 0]);
    $order = settlementCoverageOrder(['balance_due' => 200], ['payload_uuid' => 'settle_payload_uuid']);

    $change = OrderSettlement::charge($order, 700, OrderSettlement::METHOD_COLLECT);
    $meta   = settlementCoverageStoredMeta();

    expect($change)->toMatchArray(['type' => 'cod_update', 'amount' => 700, 'gateway' => 'cash'])
        ->and($meta['balance_due'])->toBe(900)
        ->and((int) settlementCoverageDb()->table('payloads')->where('uuid', 'settle_payload_uuid')->value('cod_amount'))->toBe(700)
        ->and($meta['payment'])->toMatchArray(['state' => OrderPayments::STATE_BALANCE_DUE, 'balance_due' => 900, 'method_label' => 'Card (Stripe)']);
});

test('an extra on a card order is a balance due with a stripe payment link for it', function () {
    settlementCoverageGateway('stripe', ['secret_key' => 'sk_test_settlement']);
    settlementCoverageCheckout();
    $stripe = settlementCoverageStripe(settlementCoverageStripeLinkRoutes());
    $order  = settlementCoverageOrder();
    $order->setRelation('trackingNumber', (object) ['tracking_number' => 'TRK-1001']);

    $charge = OrderSettlement::charge($order, 700, OrderSettlement::METHOD_PAYMENT_LINK, 'Upsized');
    $meta   = settlementCoverageStoredMeta();

    expect($charge)->toBe(['type' => 'balance_due', 'amount' => 700, 'payment_link' => 'https://buy.stripe.com/balance'])
        ->and($stripe->paths())->toBe(['POST /v1/prices', 'POST /v1/payment_links'])
        ->and($stripe->requests[0]['params'])->toMatchArray(['currency' => 'usd', 'unit_amount' => 700, 'product_data' => ['name' => 'Balance for order TRK-1001']])
        ->and($stripe->requests[1]['params'])->toMatchArray([
            'line_items' => [['price' => 'price_balance', 'quantity' => 1]],
            'metadata'   => ['order' => 'order_settle', 'kind' => 'order_balance'],
        ])
        ->and($meta['balance_due'])->toBe(700)
        ->and($meta['balance_payment_link'])->toBe('https://buy.stripe.com/balance')
        ->and($meta['balance_payment'])->toMatchArray(['gateway' => 'stripe', 'reference' => 'plink_balance', 'amount' => 700])
        ->and($meta['payment'])->toMatchArray(['state' => OrderPayments::STATE_BALANCE_DUE, 'payment_link' => 'https://buy.stripe.com/balance']);

    if (settlementCoverageRecordsOrderChanged()) {
        // The customer's app is told the order's payment changed, in the order's currency.
        expect(OrderChanged::$dispatched)->toHaveCount(1)
            ->and(OrderChanged::$dispatched[0][1])->toBe(['payment'])
            ->and(OrderChanged::$dispatched[0][2])->toBe('A payment link for the balance is ready')
            ->and(OrderChanged::$dispatched[0][3])->toBe(['currency' => 'USD'])
            ->and(OrderChanged::$dispatched[0][4])->toBeFalse();
    }
});

test('a gateway that cannot issue a link leaves the order as it was', function () {
    settlementCoverageGateway('paypal');
    settlementCoverageCheckout();
    $order = settlementCoverageOrder(['gateway' => 'paypal']);

    expect(fn () => OrderSettlement::charge($order, 700, OrderSettlement::METHOD_PAYMENT_LINK))
        ->toThrow(RuntimeException::class, 'This payment method cannot issue a payment link; let the driver collect it instead.')
        ->and(settlementCoverageStoredMeta())->not->toHaveKey('balance_due')
        ->and(settlementCoverageStoredMeta())->not->toHaveKey('balance_payment_link');
});

test('a link is only issued for something due', function () {
    expect(fn () => OrderSettlement::issueBalanceLink(settlementCoverageOrder()))->toThrow(RuntimeException::class, 'Nothing is due on this order.')
        ->and(fn () => OrderSettlement::issueBalanceLink(settlementCoverageOrder(['balance_due' => 500]), 0))->toThrow(RuntimeException::class, 'Nothing is due on this order.');
});

test('reissuing a stripe link for the balance deactivates the earlier one first', function () {
    settlementCoverageGateway('stripe', ['secret_key' => 'sk_test_settlement']);
    settlementCoverageCheckout();
    $stripe = settlementCoverageStripe(settlementCoverageStripeLinkRoutes([
        'POST /v1/payment_links/plink_old' => [200, ['id' => 'plink_old', 'object' => 'payment_link', 'active' => false]],
    ]));
    $order = settlementCoverageOrder(['balance_due' => 1100, 'balance_payment' => ['gateway' => 'stripe', 'reference' => 'plink_old', 'amount' => 600]]);

    $url  = OrderSettlement::issueBalanceLink($order);
    $meta = settlementCoverageStoredMeta();

    expect($url)->toBe('https://buy.stripe.com/balance')
        ->and($stripe->paths())->toBe(['POST /v1/payment_links/plink_old', 'POST /v1/prices', 'POST /v1/payment_links'])
        ->and($stripe->requests[0]['params'])->toBe(['active' => 'false'])
        // The whole balance, read from the order, is on the new link.
        ->and($stripe->requests[1]['params']['unit_amount'])->toBe(1100)
        ->and($meta['balance_payment'])->toMatchArray(['reference' => 'plink_balance', 'amount' => 1100]);
});

test('an earlier link that cannot be retired is simply replaced', function () {
    settlementCoverageGateway('stripe', ['secret_key' => 'sk_test_settlement']);
    settlementCoverageCheckout();
    // Stripe refuses to deactivate the old link (no route answers it).
    $stripe = settlementCoverageStripe(settlementCoverageStripeLinkRoutes());
    $order  = settlementCoverageOrder(['balance_due' => 800, 'balance_payment' => ['gateway' => 'stripe', 'reference' => 'plink_old']]);

    expect(OrderSettlement::issueBalanceLink($order))->toBe('https://buy.stripe.com/balance')
        ->and($stripe->paths())->toBe(['POST /v1/payment_links/plink_old', 'POST /v1/prices', 'POST /v1/payment_links'])
        ->and(settlementCoverageStoredMeta()['balance_payment']['reference'])->toBe('plink_balance');
});

test('a stripe link stripe will not create is refused with stripe reason, and nothing is kept', function () {
    settlementCoverageGateway('stripe', ['secret_key' => 'sk_test_settlement']);
    settlementCoverageCheckout();
    settlementCoverageStripe(['POST /v1/prices' => [400, ['error' => ['message' => 'Amount must be at least 50 cents', 'type' => 'invalid_request_error']]]]);
    $order = settlementCoverageOrder(['balance_due' => 30]);

    expect(fn () => OrderSettlement::issueBalanceLink($order))->toThrow(RuntimeException::class, 'Stripe could not create the payment link: Amount must be at least 50 cents')
        ->and(settlementCoverageStoredMeta())->not->toHaveKey('balance_payment_link');
});

test('a qpay balance link is an invoice for the balance that calls back for its order', function () {
    $history                       = [];
    SettlementCoverageProbe::$qpay = settlementCoverageQPay([
        new Response(200, [], '{"status":"cancelled"}'),
        new Response(200, [], '{"invoice_id":"inv_balance","qPay_shortUrl":"https://qpay.mn/s/balance","urls":[{"link":"qpay://wallet"}]}'),
    ], $history);
    settlementCoverageGateway('qpay', ['username' => 'settle-merchant', 'password' => 'settle-secret'], true);
    settlementCoverageCheckout();
    $order = settlementCoverageOrder(['gateway' => 'qpay', 'currency' => 'MNT', 'balance_due' => 12000, 'balance_payment' => ['gateway' => 'qpay', 'reference' => 'inv_old']]);

    $url     = SettlementCoverageProbe::issueBalanceLink($order);
    $invoice = settlementCoverageQPayBody($history[1]);

    expect($url)->toBe('https://qpay.mn/s/balance')
        // The earlier invoice is cancelled first.
        ->and($history[0]['request']->getMethod())->toBe('DELETE')
        ->and((string) $history[0]['request']->getUri())->toEndWith('/v2/invoice/inv_old')
        // Sandbox gateways invoice with QPay's test invoice code.
        ->and($invoice)->toMatchArray(['invoice_code' => 'TEST_INVOICE', 'amount' => 12000, 'invoice_description' => 'Balance for order order_settle', 'invoice_receiver_code' => 'terminal'])
        ->and($invoice['sender_invoice_no'])->toStartWith('order_settle-balance-')
        ->and($invoice['callback_url'])->toBe(OrderSettlement::balanceCallbackUrl($order))
        ->and(settlementCoverageStoredMeta()['balance_payment'])->toMatchArray(['gateway' => 'qpay', 'reference' => 'inv_balance', 'amount' => 12000]);
});

test('a live qpay gateway invoices with its own invoice code and links to the first app url', function () {
    $history                       = [];
    SettlementCoverageProbe::$qpay = settlementCoverageQPay([new Response(200, [], '{"invoice_id":"inv_live","urls":[{"link":"https://qpay.mn/pay/live"}]}')], $history);
    settlementCoverageGateway('qpay', ['username' => 'settle-merchant', 'password' => 'settle-secret', 'invoice_id' => 'MERCHANT_INVOICE']);
    settlementCoverageCheckout();

    $url = SettlementCoverageProbe::issueBalanceLink(settlementCoverageOrder(['gateway' => 'qpay', 'balance_due' => 9000]));

    expect($url)->toBe('https://qpay.mn/pay/live')
        ->and(settlementCoverageQPayBody($history[0])['invoice_code'])->toBe('MERCHANT_INVOICE');
});

test('a live qpay gateway without an invoice code cannot issue a link', function () {
    $history                       = [];
    SettlementCoverageProbe::$qpay = settlementCoverageQPay([], $history);
    settlementCoverageGateway('qpay', ['username' => 'settle-merchant', 'password' => 'settle-secret']);
    settlementCoverageCheckout();

    expect(fn () => SettlementCoverageProbe::issueBalanceLink(settlementCoverageOrder(['gateway' => 'qpay', 'balance_due' => 9000])))
        ->toThrow(RuntimeException::class, 'The QPay gateway has no invoice code, so it cannot issue a payment link.')
        ->and($history)->toBe([]);
});

test('an invoice qpay does not create is refused with qpay reason', function (string $body, string $message) {
    $history                       = [];
    SettlementCoverageProbe::$qpay = settlementCoverageQPay([new Response(400, [], $body)], $history);
    settlementCoverageGateway('qpay', ['username' => 'settle-merchant', 'password' => 'settle-secret'], true);
    settlementCoverageCheckout();

    expect(fn () => SettlementCoverageProbe::issueBalanceLink(settlementCoverageOrder(['gateway' => 'qpay', 'balance_due' => 9000])))
        ->toThrow(RuntimeException::class, $message)
        ->and(settlementCoverageStoredMeta())->not->toHaveKey('balance_payment_link');
})->with([
    'with a message'          => ['{"error":"INVALID_AMOUNT","message":"Amount is too small"}', 'QPay did not create the invoice: Amount is too small'],
    'without one'             => ['{"error":"INVALID_AMOUNT"}', 'QPay did not create the invoice.'],
    'an invoice with no link' => ['{"invoice_id":"inv_without_link"}', 'QPay did not create the invoice.'],
]);

test('a balance on an order whose gateway cannot be found is not checked', function () {
    $order = settlementCoverageOrder(['balance_due' => 700, 'balance_payment' => ['reference' => 'plink_balance', 'amount' => 700]]);

    expect(OrderSettlement::checkBalancePayment($order))->toBeNull()
        ->and(settlementCoverageStoredMeta()['balance_due'])->toBe(700);
});

test('a balance on a gateway without links is never paid by one', function () {
    settlementCoverageGateway('paypal');
    settlementCoverageCheckout();
    $order = settlementCoverageOrder(['gateway' => 'paypal', 'balance_due' => 700, 'balance_payment' => ['reference' => 'link_elsewhere', 'amount' => 700]]);

    expect(OrderSettlement::checkBalancePayment($order))->toBeNull()
        ->and(settlementCoverageStoredMeta()['balance_due'])->toBe(700);
});

test('a stripe link not yet paid in full leaves the balance due', function () {
    settlementCoverageGateway('stripe', ['secret_key' => 'sk_test_settlement']);
    settlementCoverageCheckout();
    $stripe = settlementCoverageStripe(['GET /v1/checkout/sessions' => [200, ['object' => 'list', 'data' => [
        ['id' => 'cs_open', 'object' => 'checkout.session', 'payment_status' => 'unpaid', 'amount_total' => 700, 'payment_intent' => null],
        ['id' => 'cs_short', 'object' => 'checkout.session', 'payment_status' => 'paid', 'amount_total' => 300, 'payment_intent' => 'pi_short'],
    ]]]]);
    $order = settlementCoverageOrder(['balance_due' => 700, 'balance_payment' => ['gateway' => 'stripe', 'reference' => 'plink_balance', 'amount' => 700]]);

    expect(OrderSettlement::checkBalancePayment($order))->toBeNull()
        ->and($stripe->paths())->toBe(['GET /v1/checkout/sessions'])
        ->and($stripe->requests[0]['params'])->toMatchArray(['payment_link' => 'plink_balance', 'limit' => 20])
        ->and(settlementCoverageStoredMeta()['balance_due'])->toBe(700);
});

test('a paid stripe link settles the balance, closes the link and sends the customer a receipt', function () {
    settlementCoverageGateway('stripe', ['secret_key' => 'sk_test_settlement']);
    settlementCoverageCheckout();
    $stripe = settlementCoverageStripe([
        'GET /v1/checkout/sessions'          => [200, ['object' => 'list', 'data' => [
            // Paid in the customer's own currency: the link's amount is under currency_conversion.
            ['id' => 'cs_paid', 'object' => 'checkout.session', 'payment_status' => 'paid', 'amount_total' => 2450000, 'currency_conversion' => ['amount_total' => 700], 'payment_intent' => 'pi_balance'],
            ['id' => 'cs_open', 'object' => 'checkout.session', 'payment_status' => 'unpaid', 'amount_total' => 700],
        ]]],
        'POST /v1/payment_links/plink_balance' => [200, ['id' => 'plink_balance', 'object' => 'payment_link', 'active' => false]],
    ]);
    $customer = new SettlementCoverageCustomer();
    $order    = settlementCoverageOrder([
        'balance_due'          => 700,
        'balance_payment_link' => 'https://buy.stripe.com/balance',
        'balance_payment'      => ['gateway' => 'stripe', 'reference' => 'plink_balance', 'amount' => 700],
    ], [], $customer);

    $charge = OrderSettlement::checkBalancePayment($order);
    $meta   = settlementCoverageStoredMeta();
    $ledger = settlementCoverageDb()->table('transactions')->first();

    expect($charge)->toMatchArray(['type' => 'charge', 'amount' => 700, 'gateway' => 'stripe', 'reference' => 'pi_balance', 'reason' => 'Balance paid by link'])
        ->and($stripe->paths())->toBe(['GET /v1/checkout/sessions', 'POST /v1/payment_links/plink_balance'])
        ->and($stripe->requests[1]['params'])->toBe(['active' => 'false'])
        ->and($meta['balance_due'])->toBe(0)
        ->and($meta['balance_payment_link'])->toBeNull()
        ->and($meta['balance_payment'])->toBeNull()
        ->and($meta['amount_paid'])->toBe(5700)
        ->and($meta['payment'])->toMatchArray(['state' => OrderPayments::STATE_PAID, 'amount_paid' => 5700, 'balance_due' => 0])
        ->and($ledger->type)->toBe(Transaction::TYPE_GATEWAY_CHARGE)
        ->and($ledger->direction)->toBe(Transaction::DIRECTION_CREDIT)
        ->and($ledger->gateway_transaction_id)->toBe('pi_balance')
        ->and($customer->notifications)->toHaveCount(1)
        ->and($customer->notifications[0])->toBeInstanceOf(StorefrontOrderBalancePaid::class)
        ->and($customer->notifications[0]->amount)->toBe(700)
        ->and($customer->notifications[0]->subject)->toBe('Payment received for your order from Corner Bakery');

    if (settlementCoverageRecordsOrderChanged()) {
        expect(collect(OrderChanged::$dispatched)->pluck(2)->all())->toBe(['The balance is paid']);
    }
});

test('a paid qpay invoice settles the balance from qpay own payment check', function () {
    $history                       = [];
    SettlementCoverageProbe::$qpay = settlementCoverageQPay([new Response(200, [], json_encode(['count' => 2, 'rows' => [
        ['payment_id' => 'qpay_balance_payment', 'payment_status' => 'PAID', 'payment_amount' => '12000.00'],
        ['payment_id' => 'qpay_failed_payment', 'payment_status' => 'FAILED', 'payment_amount' => '12000.00'],
    ]]))], $history);
    settlementCoverageGateway('qpay', ['username' => 'settle-merchant', 'password' => 'settle-secret'], true);
    settlementCoverageCheckout();
    $order = settlementCoverageOrder([
        'gateway'              => 'qpay',
        'balance_due'          => 12000,
        'balance_payment_link' => 'https://qpay.mn/s/balance',
        'balance_payment'      => ['gateway' => 'qpay', 'reference' => 'inv_balance', 'amount' => 12000],
    ], [], new SettlementCoverageCustomer());

    $charge = SettlementCoverageProbe::checkBalancePayment($order);

    // A paid QPay invoice is done: only the payment check is asked, nothing is cancelled.
    expect($history)->toHaveCount(1)
        ->and((string) $history[0]['request']->getUri())->toEndWith('/v2/payment/check')
        ->and(settlementCoverageQPayBody($history[0]))->toMatchArray(['object_type' => 'INVOICE', 'object_id' => 'inv_balance'])
        ->and($charge)->toMatchArray(['type' => 'charge', 'amount' => 12000, 'gateway' => 'qpay', 'reference' => 'qpay_balance_payment'])
        ->and(settlementCoverageStoredMeta()['balance_due'])->toBe(0);
});

test('a receipt that cannot be sent does not undo the settled balance', function () {
    $customer = new SettlementCoverageCustomer(fail: true);
    $order    = settlementCoverageOrder(['balance_due' => 700, 'balance_payment' => ['gateway' => 'console', 'reference' => 'manual']], [], $customer);

    $charge = OrderSettlement::settleBalance($order, 700, null, null);

    expect($charge)->toMatchArray(['type' => 'charge', 'amount' => 700, 'gateway' => 'stripe', 'reason' => 'Balance paid by link'])
        ->and($customer->notifications)->toBe([])
        ->and(settlementCoverageStoredMeta()['balance_due'])->toBe(0)
        ->and(settlementCoverageStoredMeta()['balance_payment'])->toBeNull();
});

test('an announcement that fails does not undo the settled balance', function () {
    $order = new SettlementCoverageUnreadableOrder();
    $order->forceFill([
        'uuid'          => 'settle_order_uuid',
        'public_id'     => 'order_settle',
        'company_uuid'  => 'settle_company_uuid',
        'customer_uuid' => 'settle_customer_uuid',
        'meta'          => ['total' => 5000, 'gateway' => 'stripe', 'storefront_id' => 'store_settle', 'balance_due' => 500],
    ]);
    $order->save();
    $customer = new SettlementCoverageCustomer();
    $order->setRelation('customer', $customer);

    $charge = OrderSettlement::settleBalance($order, 500, 'stripe', 'pi_manual');

    expect($charge)->toMatchArray(['type' => 'charge', 'amount' => 500, 'reference' => 'pi_manual'])
        // The receipt needs the currency too, so it is skipped rather than failing the settlement.
        ->and($customer->notifications)->toBe([])
        ->and(settlementCoverageStoredMeta()['payment']['amount_paid'])->toBe(5500);

    if (settlementCoverageRecordsOrderChanged()) {
        expect(OrderChanged::$dispatched)->toBe([]);
    }
});
