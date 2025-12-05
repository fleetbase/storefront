<?php

namespace Fleetbase\Storefront\Support;

use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\Storefront\Models\Cart;
use Fleetbase\Storefront\Models\Checkout;
use Fleetbase\Storefront\Models\Product;
use Fleetbase\Support\Utils;
use GuzzleHttp\Client;
use Illuminate\Support\Str;

/**
 * QPay Payment Gateway Integration Class.
 *
 * This class provides a comprehensive interface for integrating with the QPay payment gateway,
 * supporting both simple and eBarimt (Mongolian electronic receipt) invoice creation, payment
 * processing, and transaction management. It handles authentication, API communication, and
 * provides utilities for tax calculations and classification code validation.
 *
 * @author  Fleetbase Pte Ltd
 */
class QPay
{
    /**
     * The base URL for the QPay merchant API.
     */
    private string $host = 'https://merchant.qpay.mn/';

    /**
     * The API version namespace.
     */
    private string $namespace = 'v2';

    /**
     * The callback URL for payment notifications.
     */
    private ?string $callbackUrl;

    /**
     * HTTP request options for the Guzzle client.
     */
    private array $requestOptions = [];

    /**
     * The Guzzle HTTP client instance.
     */
    private Client $client;

    /**
     * Classification codes that are exempt from tax in Mongolia.
     *
     * These codes represent various categories of goods and services that are
     * subject to zero tax rate under Mongolian tax regulations.
     */
    public static array $zeroTaxClassificationCodes = [
        '2111100',
        '2111300',
        '2111500',
        '2111600',
        '2112100',
        '2112200',
        '2112300',
        '2113100',
        '2113300',
        '2113500',
        '2113600',
        '2113700',
        '2113800',
        '2113900',
        '2114100',
        '2114200',
        '2114300',
        '2114400',
        '2115100',
        '2115200',
        '2115300',
        '2115500',
        '2115600',
        '2115910',
        '2115920',
        '2115930',
        '2115940',
        '2115990',
        '2116000',
        '2117100',
        '2117210',
        '2117290',
        '2117300',
        '2117410',
        '2117490',
        '2117500',
        '2117600',
        '2117900',
        '2118000',
        '2119000',
    ];

    /**
     * QPay constructor.
     *
     * Initializes the QPay client with authentication credentials and sets up
     * the HTTP client with appropriate headers and base URI.
     *
     * @param string|null $username    QPay merchant username
     * @param string|null $password    QPay merchant password
     * @param string|null $callbackUrl URL to receive payment notifications
     */
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

    /**
     * Update a specific request option and reinitialize the HTTP client.
     *
     * @param string $key   The option key to update
     * @param mixed  $value The new value for the option
     *
     * @return void
     */
    public function updateRequestOption($key, $value)
    {
        $this->requestOptions[$key] = $value;
        $this->client               = new Client($this->requestOptions);
    }

    /**
     * Get the current Guzzle HTTP client instance.
     *
     * @return Client The HTTP client instance
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * Set the callback URL for payment notifications.
     *
     * @param string $url The callback URL
     *
     * @return $this
     */
    public function setCallback(string $url)
    {
        $this->callbackUrl = $url;

        return $this;
    }

    /**
     * Set the API namespace/version and update the base URI.
     *
     * @param string $namespace The API version namespace (e.g., 'v2')
     */
    public function setNamespace(string $namespace): ?QPay
    {
        $this->namespace = $namespace;
        $this->updateRequestOption('base_uri', $this->buildRequestUrl());

        return $this;
    }

    /**
     * Build the complete request URL from host, namespace, and path.
     *
     * @param string $path Optional path to append to the base URL
     *
     * @return string The complete request URL
     */
    private function buildRequestUrl(string $path = ''): string
    {
        $url = trim($this->host . $this->namespace . '/' . $path);

        return $url;
    }

    /**
     * Switch to using the QPay sandbox environment for testing.
     *
     * @return $this
     */
    public function useSandbox()
    {
        $this->host = 'https://merchant-sandbox.qpay.mn/';
        $this->updateRequestOption('base_uri', $this->buildRequestUrl());

        return $this;
    }

    /**
     * Create a new QPay instance (static factory method).
     *
     * @param string|null $username    QPay merchant username
     * @param string|null $password    QPay merchant password
     * @param string|null $callbackUrl URL to receive payment notifications
     *
     * @return QPay A new QPay instance
     */
    public static function instance(?string $username = null, ?string $password = null, ?string $callbackUrl = null): QPay
    {
        return new static($username, $password, $callbackUrl);
    }

    /**
     * Check if a callback URL has been set.
     *
     * @return bool True if callback URL is set, false otherwise
     */
    private function hasCallbackUrl()
    {
        return isset($this->callbackUrl);
    }

    /**
     * Make an HTTP request to the QPay API.
     *
     * @param string $method  HTTP method (GET, POST, DELETE, etc.)
     * @param string $path    API endpoint path
     * @param array  $options Additional request options
     *
     * @return object|null Decoded JSON response
     */
    private function request(string $method, string $path, array $options = [])
    {
        $options['http_errors'] = false;

        $response = $this->client->request($method, $path, $options);
        $body     = $response->getBody();
        $contents = $body->getContents();
        $json     = json_decode($contents);

        return $json;
    }

    /**
     * Make a POST request to the QPay API.
     *
     * @param string $path    API endpoint path
     * @param array  $params  Request parameters
     * @param array  $options Additional request options
     *
     * @return object|null Decoded JSON response
     */
    public function post(string $path, array $params = [], array $options = [])
    {
        $options = ['json' => $params];

        return $this->request('POST', $path, $options);
    }

    /**
     * Make a DELETE request to the QPay API.
     *
     * @param string $path    API endpoint path
     * @param array  $params  Request parameters
     * @param array  $options Additional request options
     *
     * @return object|null Decoded JSON response
     */
    public function delete(string $path, array $params = [], array $options = [])
    {
        $options = ['json' => $params];

        return $this->request('DELETE', $path, $options);
    }

    /**
     * Make a GET request to the QPay API.
     *
     * @param string $path    API endpoint path
     * @param array  $options Additional request options
     *
     * @return object|null Decoded JSON response
     */
    public function get(string $path, array $options = [])
    {
        return $this->request('GET', $path, $options);
    }

    /**
     * Obtain an authentication token from QPay.
     *
     * @return object|null Response containing access token
     */
    public function getAuthToken()
    {
        return $this->post('auth/token');
    }

    /**
     * Refresh the current authentication token.
     *
     * @return object|null Response containing refreshed access token
     */
    public function refreshAuthToken()
    {
        return $this->post('auth/refresh');
    }

    /**
     * Configure the client to use Bearer token authentication.
     *
     * @param string $token The Bearer token
     */
    private function useBearerToken(string $token): QPay
    {
        unset($this->requestOptions['auth']);
        $headers = array_merge($this->requestOptions['headers'] ?? [], ['Authorization' => "Bearer $token"]);
        $this->updateRequestOption('headers', $headers);

        return $this;
    }

    /**
     * Set the authentication token for API requests.
     *
     * If no token is provided, automatically obtains one using credentials.
     *
     * @param string|null $accessToken Optional access token to use
     */
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

    /**
     * Create a simple invoice without eBarimt (electronic receipt).
     *
     * @param int         $amount              Invoice amount
     * @param string|null $invoiceCode         Unique invoice code
     * @param string|null $invoiceDescription  Description of the invoice
     * @param string|null $invoiceReceiverCode Receiver identification code
     * @param string|null $senderInvoiceNo     Sender's invoice number
     * @param string|null $callbackUrl         URL for payment notifications
     *
     * @return object|null Created invoice response
     */
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

    /**
     * Create an eBarimt invoice (Mongolian electronic receipt).
     *
     * @param string|null $invoiceCode         Unique invoice code
     * @param string|null $senderInvoiceNo     Sender's invoice number
     * @param string|null $invoiceReceiverCode Receiver identification code
     * @param array       $invoiceReceiverData Receiver details (name, register number, etc.)
     * @param string|null $invoiceDescription  Description of the invoice
     * @param string|null $taxType             Tax type code (default: '1')
     * @param string|null $districtCode        District code for tax purposes
     * @param array       $lines               Invoice line items with classification codes and taxes
     * @param string|null $callbackUrl         URL for payment notifications
     *
     * @return object|null Created invoice response
     */
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

    /**
     * Create a QPay invoice with custom parameters.
     *
     * @param array $params  Invoice parameters
     * @param array $options Additional request options
     *
     * @return object|null Created invoice response
     */
    public function createQPayInvoice(array $params = [], $options = [])
    {
        if (!isset($params['callback_url']) && $this->hasCallbackUrl()) {
            $params['callback_url'] = $this->callbackUrl;
        }

        return $this->post('invoice', $params, $options);
    }

    /**
     * Get payment information by payment ID.
     *
     * @param string $paymentId The payment ID
     *
     * @return object|null Payment information
     */
    public function paymentGet(string $paymentId)
    {
        return $this->get('payment/' . $paymentId);
    }

    /**
     * Check the payment status for an invoice.
     *
     * @param string $invoiceId The invoice ID
     * @param array  $options   Additional request options
     *
     * @return object|null Payment check response
     */
    public function paymentCheck(string $invoiceId, $options = [])
    {
        $params = [
            'object_type' => 'INVOICE',
            'object_id'   => $invoiceId,
        ];

        return $this->post('payment/check', $params, $options);
    }

    /**
     * Get the first payment record for an invoice.
     *
     * @param string $invoiceId The invoice ID
     *
     * @return object|null First payment record or null if none found
     */
    public function getPayment(string $invoiceId)
    {
        $paymentCheck = $this->paymentCheck($invoiceId);
        $rows         = data_get($paymentCheck, 'rows');

        return $paymentCheck && is_array($rows) && count($rows) ? $rows[0] : null;
    }

    /**
     * Cancel a payment for an invoice.
     *
     * @param string $invoiceId The invoice ID
     * @param array  $options   Additional request options
     *
     * @return object|null Cancellation response
     */
    public function paymentCancel(string $invoiceId, $options = [])
    {
        $params = [
            'callback_url' => '"https://qpay.mn/payment/result?payment_id=' . $invoiceId,
        ];

        return $this->delete('payment/cancel', $params, $options);
    }

    /**
     * Refund a payment for an invoice.
     *
     * @param string $invoiceId The invoice ID
     * @param array  $options   Additional request options
     *
     * @return object|null Refund response
     */
    public function paymentRefund(string $invoiceId, $options = [])
    {
        $params = [
            'callback_url' => '"https://qpay.mn/payment/result?payment_id=' . $invoiceId,
        ];

        return $this->delete('payment/refund', $params, $options);
    }

    /**
     * Create an invoice using static method (convenience wrapper).
     *
     * @param string $username      QPay merchant username
     * @param string $password      QPay merchant password
     * @param array  $invoiceParams Invoice parameters
     *
     * @return object|null Created invoice response
     */
    public static function createInvoice(string $username, string $password, array $invoiceParams = [])
    {
        return static::instance($username, $password)->setAuthToken()->createQPayInvoice($invoiceParams);
    }

    /**
     * Generate a unique code based on current date and ID.
     *
     * @param string $id Identifier to append to date
     *
     * @return string Generated code in format YYYYMMDD{id}
     */
    public static function generateCode(string $id)
    {
        return date('Ymd') . $id;
    }

    /**
     * Clean a code by removing spaces and special characters.
     *
     * @param string $code The code to clean
     *
     * @return string Cleaned code containing only alphanumeric characters and hyphens
     */
    public static function cleanCode(string $code)
    {
        $code = str_replace(' ', '-', $code);

        return preg_replace('/[^A-Za-z0-9\-]/', '', $code);
    }

    /**
     * Create test payment data from a checkout object for testing purposes.
     *
     * @param Checkout $checkout The checkout object
     *
     * @return array Mock payment data structure
     */
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

    /**
     * Generate a mock eBarimt response for testing purposes.
     *
     * @return array Mock eBarimt response data structure
     */
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

    /**
     * Calculate VAT (Value Added Tax) from a total amount.
     *
     * Assumes 10% VAT rate is included in the amount. Calculates the VAT portion
     * by dividing by 1.1 and multiplying by 0.10, then truncates to 4 decimal places.
     *
     * @param float|int $amount The total amount including VAT
     *
     * @return float The calculated VAT amount truncated to 4 decimal places
     */
    public static function calculateTax($amount): float
    {
        $result    = ((float) $amount / 1.1) * 0.10;
        $truncated = floor($result * 10000) / 10000;

        return $truncated;
    }

    /**
     * Create initial invoice lines for QPay from cart and service quote.
     *
     * Generates line items for tips, delivery tips, and delivery fees with proper
     * classification codes and tax calculations for eBarimt invoices.
     *
     * @param Cart              $cart            The shopping cart
     * @param ServiceQuote|null $serviceQuote    The delivery service quote
     * @param array|object      $checkoutOptions Checkout options including tips and pickup flag
     *
     * @return array Array of line items with descriptions, quantities, prices, and taxes
     */
    public static function createQpayInitialLines(Cart $cart, ?ServiceQuote $serviceQuote, $checkoutOptions): array
    {
        // Prepare dependencies
        $checkoutOptions = (object) $checkoutOptions;
        $subtotal        = (int) $cart->subtotal;
        $total           = $subtotal;
        $tip             = $checkoutOptions->tip ?? false;
        $deliveryTip     = $checkoutOptions->delivery_tip ?? false;
        $isPickup        = $checkoutOptions->is_pickup ?? false;

        // Initialize lines
        $lines = [];

        if ($tip) {
            $tipAmount = Storefront::calculateTipAmount($tip, $subtotal);
            $lines[]   = [
                'line_description'    => 'Tip',
                'line_quantity'       => number_format(1, 2, '.', ''),
                'line_unit_price'     => number_format($tipAmount, 2, '.', ''),
                'note'                => 'Tip',
                'classification_code' => '6511100',
                'tax_product_code'    => '319',
                'taxes'               => [
                    [
                        'tax_code'    => 'VAT',
                        'description' => 'VAT',
                        'amount'      => QPay::calculateTax($tipAmount),
                        'note'        => 'Tip',
                    ],
                ],
            ];
        }

        if ($deliveryTip && !$isPickup) {
            $deliveryTipAmount = Storefront::calculateTipAmount($deliveryTip, $subtotal);
            $lines[]           = [
                'line_description'    => 'Delivery Tip',
                'line_quantity'       => number_format(1, 2, '.', ''),
                'line_unit_price'     => number_format($deliveryTipAmount, 2, '.', ''),
                'note'                => 'Delivery Tip',
                'classification_code' => '6511100',
                'tax_product_code'    => '319',
                'taxes'               => [
                    [
                        'tax_code'    => 'VAT',
                        'description' => 'VAT',
                        'amount'      => QPay::calculateTax($deliveryTipAmount),
                        'note'        => 'Delivery Tip',
                    ],
                ],
            ];
        }

        if (!$isPickup) {
            $serviceQuoteAmount = Utils::numbersOnly($serviceQuote->amount);
            $lines[]            = [
                'line_description'    => 'Delivery Fee',
                'line_quantity'       => number_format(1, 2, '.', ''),
                'line_unit_price'     => number_format($serviceQuoteAmount, 2, '.', ''),
                'note'                => 'Delivery Fee',
                'classification_code' => '6511100',
                'tax_product_code'    => '319',
                'taxes'               => [
                    [
                        'tax_code'    => 'VAT',
                        'description' => 'VAT',
                        'amount'      => QPay::calculateTax($serviceQuoteAmount),
                        'note'        => 'Delivery Fee',
                    ],
                ],
            ];
        }

        return $lines;
    }

    /**
     * Validate if a classification code is in the correct format.
     *
     * Classification codes must be exactly 7 digits for Mongolian tax purposes.
     *
     * @param mixed $classificationCode The code to validate
     *
     * @return bool True if valid (exactly 7 digits), false otherwise
     */
    public static function isValidClassificationCode($classificationCode): bool
    {
        if ($classificationCode === null) {
            return false;
        }

        $classificationCode = (string) $classificationCode;

        // Must be exactly 7 digits
        return preg_match('/^\d{7}$/', $classificationCode) === 1;
    }

    /**
     * Validate if a tax product code is in the correct format.
     *
     * Tax product codes must be exactly 7 digits for Mongolian tax purposes.
     *
     * @param mixed $taxProductCode The code to validate
     *
     * @return bool True if valid (exactly 3 digits), false otherwise
     */
    public static function isValidTaxProductCode($taxProductCode): bool
    {
        if ($taxProductCode === null) {
            return false;
        }

        $taxProductCode = (string) $taxProductCode;

        // Must be exactly 3 digits
        return preg_match('/^\d{3}$/', $taxProductCode) === 1;
    }

    /**
     * Check if a classification code is tax-free (zero tax rate).
     *
     * @param mixed $classificationCode The code to check
     *
     * @return bool True if the code is in the zero tax list, false otherwise
     */
    public static function isTaxFreeClassificationCode($classificationCode): bool
    {
        if (!self::isValidClassificationCode($classificationCode)) {
            return false;
        }

        return in_array((string) $classificationCode, self::$zeroTaxClassificationCodes, true);
    }

    /**
     * Get the classification code for a cart item.
     *
     * Attempts to retrieve the classification code from the item's meta data first,
     * then falls back to the product's meta data, and finally uses a default code.
     *
     * @param object $item The cart item
     *
     * @return string The classification code (7 digits)
     */
    public static function getCartItemClassificationCode($item): string
    {
        $classificationCode = '6511100';

        // Try item meta
        if (isset($item->meta)) {
            $meta = is_string($item->meta)
                ? json_decode($item->meta, true)  // decode into array
                : (array) $item->meta;

            $metaCode = data_get($meta, 'classification_code');

            if (self::isValidClassificationCode($metaCode)) {
                return $metaCode;
            }
        }

        // Try product meta fallback
        if ($item->product_id) {
            $product = Product::where('public_id', $item->product_id)->first();

            if ($product && $product->hasMeta('classification_code')) {
                $productCode = $product->getMeta('classification_code');
                if (self::isValidClassificationCode($productCode)) {
                    return $productCode;
                }
            }
        }

        // Final fallback
        return $classificationCode;
    }

    /**
     * Get the tax_product_code for a cart item.
     *
     * Attempts to retrieve the tax product code from the item's meta data first,
     * then falls back to the product's meta data, and finally uses a default code.
     *
     * @param object $item The cart item
     *
     * @return string The tax_product_code code (7 digits)
     */
    public static function getCartItemTaxProductCode($item): string
    {
        $taxProductCode = '319';

        // Try item meta
        if (isset($item->meta)) {
            $meta = is_string($item->meta)
                ? json_decode($item->meta, true)  // decode into array
                : (array) $item->meta;

            $metaCode = data_get($meta, 'tax_product_code');

            if (self::isValidTaxProductCode($metaCode)) {
                return $metaCode;
            }
        }

        // Try product meta fallback
        if ($item->product_id) {
            $product = Product::where('public_id', $item->product_id)->first();

            if ($product && $product->hasMeta('tax_product_code')) {
                $productCode = $product->getMeta('tax_product_code');
                if (self::isValidTaxProductCode($productCode)) {
                    return $productCode;
                }
            }
        }

        // Final fallback
        return $taxProductCode;
    }
}
