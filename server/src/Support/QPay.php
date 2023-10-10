<?php

namespace Fleetbase\Storefront\Support;

use GuzzleHttp\Client;

class QPay
{
    private string $host = 'https://merchant.qpay.mn/';
    private string $namespace = 'v2';
    private ?string $callbackUrl;
    private array $requestOptions = [];
    private Client $client;

    public function __construct(?string $username = null, ?string $password = null, ?string $callbackUrl = null)
    {
        $this->callbackUrl = $callbackUrl;
        $this->requestOptions = [
            'base_uri' => $this->buildRequestUrl(),
            'auth' => [$username, $password],
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ]
        ];
        $this->client = new Client($this->requestOptions);
    }

    public function updateRequestOption($key, $value)
    {
        $this->requestOptions[$key] = $value;
        $this->client = new Client($this->requestOptions);
    }

    public function getClient()
    {
        return $this->client;
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
        $body = $response->getBody();
        $contents = $body->getContents();
        $json = json_decode($contents);

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
            $token = $response->access_token;

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
            'invoice_code' => $invoiceCode,
            'amount' => $amount,
            'callback_url' => $callbackUrl,
            'invoice_description' => $invoiceDescription,
            'invoice_receiver_code' => $invoiceReceiverCode,
            'sender_invoice_no' => $senderInvoiceNo,
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

    public function paymentCheck(string $invoiceId, $options = [])
    {
        $params = [
            'object_type' => 'INVOICE',
            'object_id' => $invoiceId
        ];

        return $this->post('payment/check', $params, $options);
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
}
