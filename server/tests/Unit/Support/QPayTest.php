<?php

use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\Storefront\Models\Cart;
use Fleetbase\Storefront\Models\Checkout;
use Fleetbase\Storefront\Support\QPay;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

class QPayInvoiceStub extends QPay
{
    public static array $captured = [];

    public function __construct(?string $username = null, ?string $password = null, ?string $callbackUrl = null)
    {
    }

    public function setAuthToken(?string $accessToken = null): QPay
    {
        self::$captured['authenticated'] = true;

        return $this;
    }

    public function createQPayInvoice(array $params = [], $options = [])
    {
        self::$captured['params'] = $params;

        return (object) ['invoice_id' => 'stub-invoice'];
    }
}

function qpayWithResponses(array $responses, array &$history): QPay
{
    $mock    = new MockHandler($responses);
    $handler = HandlerStack::create($mock);
    $handler->push(Middleware::history($history));

    $qpay = new QPay('merchant', 'secret', 'https://storefront.test/qpay');
    $qpay->updateRequestOption('handler', $handler);

    return $qpay;
}

test('qpay client switches namespaces and sandbox hosts without losing its API path', function () {
    $qpay = QPay::instance('merchant', 'secret', 'https://storefront.test/qpay');

    expect((string) $qpay->getClient()->getConfig('base_uri'))
        ->toBe('https://merchant.qpay.mn/v2/');

    expect($qpay->setNamespace('v3'))->toBe($qpay)
        ->and((string) $qpay->getClient()->getConfig('base_uri'))
        ->toBe('https://merchant.qpay.mn/v3/');

    expect($qpay->useSandbox())->toBe($qpay)
        ->and((string) $qpay->getClient()->getConfig('base_uri'))
        ->toBe('https://merchant-sandbox.qpay.mn/v3/');
});

test('qpay sends authenticated HTTP operations with deterministic payloads', function () {
    $history = [];
    $qpay    = qpayWithResponses([
        new Response(200, [], '{"access_token":"token-123"}'),
        new Response(200, [], '{"invoice_id":"invoice-1"}'),
        new Response(200, [], '{"rows":[{"payment_id":"payment-1"}]}'),
        new Response(200, [], '{"status":"cancelled"}'),
        new Response(200, [], '{"status":"refunded"}'),
    ], $history);

    expect($qpay->setAuthToken())->toBe($qpay);

    $invoice = $qpay->createSimpleInvoice(
        12500,
        'ORDER-1',
        'Storefront order',
        'customer-1',
        'sender-1'
    );
    $payment = $qpay->getPayment('invoice-1');
    $cancel  = $qpay->paymentCancel('payment-1');
    $refund  = $qpay->paymentRefund('payment-1');

    expect($invoice->invoice_id)->toBe('invoice-1')
        ->and($payment->payment_id)->toBe('payment-1')
        ->and($cancel->status)->toBe('cancelled')
        ->and($refund->status)->toBe('refunded')
        ->and($history)->toHaveCount(5)
        ->and($history[0]['request']->getMethod())->toBe('POST')
        ->and((string) $history[0]['request']->getUri())->toBe('https://merchant.qpay.mn/v2/auth/token')
        ->and($history[1]['request']->getHeaderLine('Authorization'))->toBe('Bearer token-123')
        ->and(json_decode((string) $history[1]['request']->getBody(), true))->toMatchArray([
            'invoice_code'          => 'ORDER-1',
            'amount'                => 12500,
            'callback_url'          => 'https://storefront.test/qpay',
            'invoice_description'   => 'Storefront order',
            'invoice_receiver_code' => 'customer-1',
            'sender_invoice_no'     => 'sender-1',
        ])
        ->and(json_decode((string) $history[2]['request']->getBody(), true))->toBe([
            'object_type' => 'INVOICE',
            'object_id'   => 'invoice-1',
        ])
        ->and($history[3]['request']->getMethod())->toBe('DELETE')
        ->and((string) $history[3]['request']->getUri())->toContain('payment/cancel')
        ->and((string) $history[4]['request']->getUri())->toContain('payment/refund');
});

test('qpay supports direct tokens refreshes and individual payment lookups', function () {
    $history = [];
    $qpay    = qpayWithResponses([
        new Response(200, [], '{"ok":true}'),
        new Response(200, [], '{"access_token":"refreshed"}'),
        new Response(200, [], '{"payment_id":"payment-7"}'),
    ], $history);

    expect($qpay->setCallback('https://changed.test/qpay'))->toBe($qpay)
        ->and($qpay->setAuthToken('direct-token'))->toBe($qpay)
        ->and($qpay->get('health')->ok)->toBeTrue()
        ->and($qpay->refreshAuthToken()->access_token)->toBe('refreshed')
        ->and($qpay->paymentGet('payment-7')->payment_id)->toBe('payment-7')
        ->and($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer direct-token')
        ->and((string) $history[1]['request']->getUri())->toContain('auth/refresh')
        ->and((string) $history[2]['request']->getUri())->toContain('payment/payment-7');
});

test('qpay invoice factory authenticates and forwards invoice parameters', function () {
    QPayInvoiceStub::$captured = [];

    $invoice = QPayInvoiceStub::createInvoice('merchant', 'secret', [
        'invoice_code' => 'ORDER-FACTORY',
        'amount'       => 9900,
    ]);

    expect($invoice->invoice_id)->toBe('stub-invoice')
        ->and(QPayInvoiceStub::$captured)->toBe([
            'authenticated' => true,
            'params'        => [
                'invoice_code' => 'ORDER-FACTORY',
                'amount'       => 9900,
            ],
        ]);
});

test('qpay returns null when a payment check has no usable rows', function ($response) {
    $history = [];
    $qpay    = qpayWithResponses([new Response(200, [], json_encode($response))], $history);

    expect($qpay->getPayment('invoice-empty'))->toBeNull();
})->with([
    'empty rows'   => [['rows' => []]],
    'missing rows' => [['count' => 0]],
    'null body'    => [null],
]);

test('qpay creates ebarimt invoices with callback defaults and explicit overrides', function () {
    $history = [];
    $qpay    = qpayWithResponses([
        new Response(200, [], '{"invoice_id":"default-callback"}'),
        new Response(200, [], '{"invoice_id":"explicit-callback"}'),
    ], $history);

    $qpay->createEbarimtInvoice(
        'ORDER-2',
        'sender-2',
        'customer-2',
        ['name' => 'Ada'],
        'Tax invoice',
        '1',
        '3505',
        [['line_description' => 'Product']]
    );
    $qpay->createQPayInvoice([
        'invoice_code' => 'ORDER-3',
        'callback_url' => 'https://override.test/qpay',
    ]);

    $defaultPayload  = json_decode((string) $history[0]['request']->getBody(), true);
    $explicitPayload = json_decode((string) $history[1]['request']->getBody(), true);

    expect($defaultPayload)->toMatchArray([
        'invoice_code'          => 'ORDER-2',
        'sender_invoice_no'     => 'sender-2',
        'invoice_receiver_code' => 'customer-2',
        'invoice_receiver_data' => ['name' => 'Ada'],
        'invoice_description'   => 'Tax invoice',
        'tax_type'              => '1',
        'district_code'         => '3505',
        'callback_url'          => 'https://storefront.test/qpay',
    ])->and($explicitPayload['callback_url'])->toBe('https://override.test/qpay');
});

test('qpay inserts its configured callback when raw invoice parameters omit one', function () {
    $history = [];
    $qpay    = qpayWithResponses([
        new Response(200, [], '{"invoice_id":"invoice-default"}'),
    ], $history);

    $qpay->createQPayInvoice(['invoice_code' => 'ORDER-DEFAULT']);
    $payload = json_decode((string) $history[0]['request']->getBody(), true);

    expect($payload)->toBe([
        'invoice_code' => 'ORDER-DEFAULT',
        'callback_url' => 'https://storefront.test/qpay',
    ]);
});

test('qpay code and tax helpers enforce gateway formats and deterministic fallbacks', function () {
    expect(QPay::generateCode('order-1'))->toBe(date('Ymd') . 'order-1')
        ->and(QPay::cleanCode(' Order #1 / paid '))->toBe('-Order-1--paid-')
        ->and(QPay::calculateTax(110))->toBe(10.0)
        ->and(QPay::isValidClassificationCode('2111100'))->toBeTrue()
        ->and(QPay::isValidClassificationCode(2111100))->toBeTrue()
        ->and(QPay::isValidClassificationCode(null))->toBeFalse()
        ->and(QPay::isValidClassificationCode('21111'))->toBeFalse()
        ->and(QPay::isValidTaxProductCode('319'))->toBeTrue()
        ->and(QPay::isValidTaxProductCode(null))->toBeFalse()
        ->and(QPay::isValidTaxProductCode('31A'))->toBeFalse()
        ->and(QPay::isTaxFreeClassificationCode('2111100'))->toBeTrue()
        ->and(QPay::isTaxFreeClassificationCode('6511100'))->toBeFalse()
        ->and(QPay::isTaxFreeClassificationCode('invalid'))->toBeFalse();

    expect(QPay::getCartItemClassificationCode((object) [
        'meta'       => '{"classification_code":"2111300"}',
        'product_id' => null,
    ]))->toBe('2111300')
        ->and(QPay::getCartItemClassificationCode((object) [
            'meta'       => ['classification_code' => 'bad'],
            'product_id' => null,
        ]))->toBe('6511100')
        ->and(QPay::getCartItemTaxProductCode((object) [
            'meta'       => (object) ['tax_product_code' => '201'],
            'product_id' => null,
        ]))->toBe('201')
        ->and(QPay::getCartItemTaxProductCode((object) [
            'meta'       => ['tax_product_code' => 'bad'],
            'product_id' => null,
        ]))->toBe('319');
});

test('qpay tax helpers fall back to persisted product metadata', function () {
    $connection = Illuminate\Database\Eloquent\Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('products');
    $schema->create('products', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $connection->table('products')->insert([
        'uuid'      => 'product_uuid',
        'public_id' => 'product_abcdefgh',
        'meta'      => json_encode([
            'classification_code'  => '2111500',
            'tax_product_code'     => '201',
        ]),
    ]);
    $item           = (object) ['product_id' => 'product_abcdefgh'];
    $stringMetaItem = (object) [
        'meta'       => json_encode(['tax_product_code' => '202']),
        'product_id' => null,
    ];

    expect(QPay::getCartItemClassificationCode($item))->toBe('2111500')
        ->and(QPay::getCartItemTaxProductCode($item))->toBe('201')
        ->and(QPay::getCartItemTaxProductCode($stringMetaItem))->toBe('202');
});

test('qpay builds receipt lines for tips delivery and pickup rules', function () {
    $cart = new Cart();
    $cart->forceFill([
        'items' => [
            ['subtotal' => 10000, 'quantity' => 1],
        ],
    ]);

    $quote = new ServiceQuote();
    $quote->forceFill(['amount' => 2500]);

    $deliveryLines = QPay::createQpayInitialLines($cart, $quote, [
        'tip'          => '10%',
        'delivery_tip' => 500,
        'is_pickup'    => false,
    ]);
    $pickupLines = QPay::createQpayInitialLines($cart, null, [
        'tip'          => 750,
        'delivery_tip' => 500,
        'is_pickup'    => true,
    ]);

    expect(array_column($deliveryLines, 'line_description'))->toBe(['Tip', 'Delivery Tip', 'Delivery Fee'])
        ->and(array_column($deliveryLines, 'line_unit_price'))->toBe(['1000.00', '500.00', '2500.00'])
        ->and($deliveryLines[0]['taxes'][0]['amount'])->toBe(QPay::calculateTax(1000))
        ->and(array_column($pickupLines, 'line_description'))->toBe(['Tip'])
        ->and($pickupLines[0]['line_unit_price'])->toBe('750.00');
});

test('qpay preserves decimal tip percentages when building receipt lines', function () {
    $cart = new Cart();
    $cart->forceFill([
        'items' => [
            ['subtotal' => 20000, 'quantity' => 1],
        ],
    ]);

    $lines = QPay::createQpayInitialLines($cart, null, [
        'tip'       => '12.5%',
        'is_pickup' => true,
    ]);

    expect($lines[0]['line_unit_price'])->toBe('2500.00');
});

test('qpay creates deterministic test-payment shape from checkout state', function () {
    $checkout = new Checkout();
    $checkout->forceFill([
        'amount'   => 4200,
        'currency' => 'MNT',
        'options'  => ['qpay_invoice_id' => 'invoice-42'],
    ]);

    $payment = QPay::createTestPaymentDataFromCheckout($checkout);
    $ebarimt = QPay::mockEbarimtResponse();

    expect($payment)->toMatchArray([
        'payment_status'   => 'PAID',
        'payment_amount'   => 4200,
        'payment_currency' => 'MNT',
        'object_type'      => 'INVOICE',
        'object_id'        => 'invoice-42',
    ])->and($payment['payment_id'])->toBeString()
        ->and($payment['payment_date'])->not->toBeNull()
        ->and($ebarimt)->toMatchArray([
            'ebarimt_by'   => 'QPAY',
            'object_type'  => 'INVOICE',
            'status'       => true,
            'barimt_status'=> 'REGISTERED',
        ]);
});
