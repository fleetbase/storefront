<?php

namespace Fleetbase\Storefront\Support;

use Fleetbase\Storefront\Models\Checkout;
use Fleetbase\Support\Utils;
use GuzzleHttp\Client;
use Illuminate\Support\Str;

class QPay
{
    private string $host      = 'https://merchant.qpay.mn/';
    private string $namespace = 'v2';
    private ?string $callbackUrl;
    private array $requestOptions = [];
    private Client $client;

    public function __construct(?string $username = null, ?string $password = null, ?string $callbackUrl = null)
    {
        $this->callbackUrl    = $callbackUrl ?? Utils::apiUrl('storefront/v1/checkouts/process-qpay');
        $this->requestOptions = [
            'base_uri' => $this->buildRequestUrl(),
            'auth'     => [$username, $password],
            'headers'  => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
        ];
        $this->client = new Client($this->requestOptions);
    }

    public function updateRequestOption($key, $value)
    {
        $this->requestOptions[$key] = $value;
        $this->client               = new Client($this->requestOptions);
    }

    public function getClient()
    {
        return $this->client;
    }

    public function setCallback(string $url)
    {
        $this->callbackUrl = $url;

        return $this;
    }

    public function setNamespace(string $namespace): ?QPay
    {
        $this->namespace = $namespace;
        $this->updateRequestOption('base_uri', $this->buildRequestUrl());

        return $this;
    }

    private function buildRequestUrl(string $path = ''): string
    {
        $url = trim($this->host . $this->namespace . '/' . $path);

        return $url;
    }

    public function useSandbox()
    {
        $this->host = 'https://merchant-sandbox.qpay.mn/';
        $this->updateRequestOption('base_uri', $this->buildRequestUrl());

        return $this;
    }

    public static function instance(?string $username = null, ?string $password = null, ?string $callbackUrl = null): QPay
    {
        return new static($username, $password, $callbackUrl);
    }

    private function hasCallbackUrl()
    {
        return isset($this->callbackUrl);
    }

    private function request(string $method, string $path, array $options = [])
    {
        $options['http_errors'] = false;

        $response = $this->client->request($method, $path, $options);
        $body     = $response->getBody();
        $contents = $body->getContents();
        $json     = json_decode($contents);

        return $json;
    }

    public function post(string $path, array $params = [], array $options = [])
    {
        $options = ['json' => $params];

        return $this->request('POST', $path, $options);
    }

    public function delete(string $path, array $params = [], array $options = [])
    {
        $options = ['json' => $params];

        return $this->request('DELETE', $path, $options);
    }

    public function get(string $path, array $options = [])
    {
        return $this->request('GET', $path, $options);
    }

    public function getAuthToken()
    {
        return $this->post('auth/token');
    }

    public function refreshAuthToken()
    {
        return $this->post('auth/refresh');
    }

    private function useBearerToken(string $token): QPay
    {
        unset($this->requestOptions['auth']);
        $headers = array_merge($this->requestOptions['headers'] ?? [], ['Authorization' => "Bearer $token"]);
        $this->updateRequestOption('headers', $headers);

        return $this;
    }

    public function setAuthToken(?string $accessToken = null): QPay
    {
        if ($accessToken) {
            $this->useBearerToken($accessToken);
        } else {
            $response = $this->getAuthToken();
            $token    = $response->access_token;

            if (isset($token)) {
                $this->useBearerToken($token);
            }
        }

        return $this;
    }

    public function createSimpleInvoice(int $amount, ?string $invoiceCode = '', ?string $invoiceDescription = '', ?string $invoiceReceiverCode = '', ?string $senderInvoiceNo = '', ?string $callbackUrl = null)
    {
        if (!$callbackUrl && $this->hasCallbackUrl()) {
            $callbackUrl = $this->callbackUrl;
        }

        $params = array_filter([
            'invoice_code'          => $invoiceCode,
            'amount'                => $amount,
            'callback_url'          => $callbackUrl,
            'invoice_description'   => $invoiceDescription,
            'invoice_receiver_code' => $invoiceReceiverCode,
            'sender_invoice_no'     => $senderInvoiceNo,
        ]);

        return $this->createQPayInvoice($params);
    }

    public function createEbarimtInvoice(?string $invoiceCode = '', ?string $senderInvoiceNo = '', ?string $invoiceReceiverCode = '', array $invoiceReceiverData = [], ?string $invoiceDescription = '', ?string $taxType = '1', ?string $districtCode = '', array $lines = [], ?string $callbackUrl = null)
    {
        if (!$callbackUrl && $this->hasCallbackUrl()) {
            $callbackUrl = $this->callbackUrl;
        }

        $params = array_filter([
            'invoice_code'          => $invoiceCode,
            'sender_invoice_no'     => $senderInvoiceNo,
            'invoice_receiver_code' => $invoiceReceiverCode,
            'invoice_receiver_data' => $invoiceReceiverData,
            'invoice_description'   => $invoiceDescription,
            'tax_type'              => $taxType,
            'district_code'         => $districtCode,
            'lines'                 => $lines,
            'callback_url'          => $callbackUrl,
        ]);

        return $this->createQPayInvoice($params);
    }

    public function createQPayInvoice(array $params = [], $options = [])
    {
        if (!isset($params['callback_url']) && $this->hasCallbackUrl()) {
            $params['callback_url'] = $this->callbackUrl;
        }

        return $this->post('invoice', $params, $options);
    }

    public function paymentGet(string $paymentId)
    {
        return $this->get('payment/' . $paymentId);
    }

    public function paymentCheck(string $invoiceId, $options = [])
    {
        $params = [
            'object_type' => 'INVOICE',
            'object_id'   => $invoiceId,
        ];

        return $this->post('payment/check', $params, $options);
    }

    public function getPayment(string $invoiceId)
    {
        $paymentCheck = $this->paymentCheck($invoiceId);
        $rows         = data_get($paymentCheck, 'rows');

        return $paymentCheck && is_array($rows) && count($rows) ? $rows[0] : null;
    }

    public function paymentCancel(string $invoiceId, $options = [])
    {
        $params = [
            'callback_url' => '"https://qpay.mn/payment/result?payment_id=' . $invoiceId,
        ];

        return $this->delete('payment/cancel', $params, $options);
    }

    public function paymentRefund(string $invoiceId, $options = [])
    {
        $params = [
            'callback_url' => '"https://qpay.mn/payment/result?payment_id=' . $invoiceId,
        ];

        return $this->delete('payment/refund', $params, $options);
    }

    public static function createInvoice(string $username, string $password, array $invoiceParams = [])
    {
        return static::instance($username, $password)->setAuthToken()->createQPayInvoice($invoiceParams);
    }

    public static function generateCode(string $id)
    {
        return date('Ymd') . $id;
    }

    public static function cleanCode(string $code)
    {
        $code = str_replace(' ', '-', $code);

        return preg_replace('/[^A-Za-z0-9\-]/', '', $code);
    }

    public static function createTestPaymentDataFromCheckout(Checkout $checkout): array
    {
        return [
            'payment_id'        => (string) Str::uuid(),
            'payment_status'    => 'PAID',
            'payment_fee'       => '1.00',
            'payment_amount'    => $checkout->amount,
            'payment_currency'  => $checkout->currency,
            'payment_date'      => now(),
            'payment_wallet'    => 'TEST',
            'object_type'       => 'INVOICE',
            'object_id'         => $checkout->getOption('qpay_invoice_id', (string) Str::uuid()),
            'next_payment_date' => null,
            'transaction_type'  => 'P2P',
            'card_transactions' => [],
            'p2p_transactions'  => [],
        ];
    }

    public static function mockEbarimtResponse(): array
    {
        return [
            'id'                     => 'ca48461c-0b85-438d-b8f4-8b46582a668c',
            'ebarimt_by'             => 'QPAY',
            'g_wallet_id'            => '0fc9b71c-cd87-4ffd-9cac-2279ebd9deb0',
            'g_wallet_customer_id'   => 'b8361d3d-2a22-4942-9682-336daf87c025',
            'ebarimt_receiver_type'  => 'CITIZEN',
            'ebarimt_receiver'       => '88614450',
            'ebarimt_district_code'  => '3505',
            'ebarimt_bill_type'      => '1',
            'g_merchant_id'          => 'KKTT',
            'merchant_branch_code'   => 'BRANCH1',
            'merchant_terminal_code' => null,
            'merchant_staff_code'    => 'online',
            'merchant_register_no'   => '5395305',
            'g_payment_id'           => '019276866891878',
            'paid_by'                => 'P2P',
            'object_type'            => 'INVOICE',
            'object_id'              => '18f4d9be-9ad7-42d4-95b9-c0d2f9e75900',
            'amount'                 => '200.00',
            'vat_amount'             => '0.00',
            'city_tax_amount'        => '0.00',
            'ebarimt_qr_data'        => '1384317094375011435297579636399454618202815732865429083584377335965767295542878017999436539632565406872654525818990190095811371347814623214640752059894152037',
            'ebarimt_lottery'        => 'HV 83198235',
            'note'                   => null,
            'barimt_status'          => 'REGISTERED',
            'barimt_status_date'     => '2024-11-04T05:45:42.945Z',
            'ebarimt_sent_email'     => null,
            'ebarimt_receiver_phone' => '88*144*0',
            'tax_type'               => '2',
            'merchant_tin'           => '30101065006',
            'ebarimt_receipt_id'     => '030101065006000090690000210005595',
            'created_by'             => '1',
            'created_date'           => '2024-11-04T05:45:42.295Z',
            'updated_by'             => '1',
            'updated_date'           => '2024-11-04T05:45:42.410Z',
            'status'                 => true,
        ];
    }

    public static function calculateTax($amount): float
    {
        $result    = ((float) $amount / 1.1) * 0.10;
        $truncated = floor($result * 10000) / 10000;

        return $truncated;
    }
}
