<?php

namespace Fleetbase\Storefront\Http\Controllers\v1;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Storefront\Models\Checkout;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Support\OrderActivityFlow;
use Fleetbase\Storefront\Support\QPay;
use Fleetbase\Storefront\Support\Storefront;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    /**
     * Marks a pickup order as completed by "customer pickup".
     *
     * @return \Illuminate\Http\Response
     */
    public function completeOrderPickup(Request $request)
    {
        $customer = Storefront::getCustomerFromToken();
        if (!$customer) {
            return response()->apiError('Customer is not authenticated.');
        }

        $order    = Order::where('public_id', $request->order)->whereNull('deleted_at')->with(['customer'])->first();
        if (!$order) {
            return response()->apiError('No order found.');
        }

        // Confirm the completion is done by the customer
        if ($order->customer_uuid !== $customer->uuid) {
            return response()->apiError('Not authorized to pickup this order for completion.');
        }

        // Patch order config
        $this->patchOrderConfig($order);

        // update activity to completed
        $this->updateOrderStatus($order, 'completed');

        return response()->json([
            'status' => 'ok',
            'order'  => $order->public_id,
            'status' => $order->status,
        ]);
    }

    protected function patchOrderConfig(Order $order)
    {
        return Storefront::patchOrderConfig($order);
    }

    protected function updateOrderStatus(Order $order, string $status)
    {
        return $order->updateStatus($status);
    }

    /**
     * The stores a multi-store (network) order was placed with: each store's own order, its
     * progress, the items it is preparing and its subtotal. The customer's order is the one
     * delivery that brings them together; this is its breakdown by store. Empty for an order
     * from a single store.
     */
    public function getStores(string $id)
    {
        $customer = Storefront::getCustomerFromToken();
        if (!$customer) {
            return response()->apiError('Customer is not authenticated.');
        }

        $order = Order::where('public_id', $id)->whereNull('deleted_at')->first();
        if (!$order) {
            return response()->apiError('No order found.', 404);
        }

        if ($order->customer_uuid !== $customer->uuid) {
            return response()->apiError('Not authorized to view this order.', 403);
        }

        $related = array_values(array_filter((array) $order->getMeta('related_orders', [])));
        if (!$order->getMeta('is_master_order') || empty($related)) {
            return response()->json(['stores' => []]);
        }

        $children = Order::whereIn('public_id', $related)
            ->where('customer_uuid', $customer->uuid)
            ->whereNull('deleted_at')
            ->with(['payload.pickup', 'payload.entities'])
            ->get()
            ->sortBy(fn ($child) => array_search($child->public_id, $related))
            ->values();

        $storeIds = $children->map(fn ($child) => $child->getMeta('storefront_id'))->filter()->unique()->values();
        $stores   = Store::whereIn('public_id', $storeIds)->get()->keyBy('public_id');

        $sections = $children->map(function (Order $child) use ($stores) {
            $store  = $stores->get($child->getMeta('storefront_id'));
            $pickup = data_get($child, 'payload.pickup');
            $flow   = OrderActivityFlow::forOrder($child);
            $step   = collect($flow['steps'] ?? [])->firstWhere('state', 'current') ?? collect($flow['steps'] ?? [])->last();

            return [
                'order'    => $child->public_id,
                'status'   => $child->status,
                'label'    => data_get($step, 'label'),
                'store'    => [
                    'id'       => $store?->public_id ?? $child->getMeta('storefront_id'),
                    'name'     => $store?->name ?? $child->getMeta('storefront'),
                    'logo_url' => $store?->logo_url,
                    'phone'    => $store?->phone,
                    'address'  => $pickup ? ($pickup->address ?? null) : null,
                ],
                'items'    => collect(data_get($child, 'payload.entities', []))->map(fn ($entity) => [
                    'id'        => $entity->public_id,
                    'name'      => $entity->name,
                    'quantity'  => (int) (data_get($entity, 'meta.quantity') ?: 1),
                    'subtotal'  => (int) data_get($entity, 'meta.subtotal', 0),
                    'variants'  => data_get($entity, 'meta.variants', []),
                    'addons'    => data_get($entity, 'meta.addons', []),
                    'image_url' => data_get($entity, 'meta.image_url') ?? $entity->photo_url ?? null,
                ])->values(),
                'subtotal' => (int) $child->getMeta('subtotal', 0),
                'discount' => (int) $child->getMeta('discount', 0),
                'tip'      => (int) $child->getMeta('tip', 0),
                'currency' => $child->getMeta('currency'),
            ];
        })->values();

        return response()->json(['stores' => $sections]);
    }

    /**
     * The customer's order as a line of steps from its own order config: where it has been,
     * where it is, and where it is expected to go (see OrderActivityFlow).
     *
     * GET storefront/v1/orders/{id}/activity-flow
     */
    public function getActivityFlow(string $id)
    {
        $customer = Storefront::getCustomerFromToken();
        if (!$customer) {
            return response()->apiError('Customer is not authenticated.');
        }

        $order = Order::where('public_id', $id)
            ->whereNull('deleted_at')
            ->first();

        if (!$order) {
            return response()->apiError('No order found.', 404);
        }

        if ($order->customer_uuid !== $customer->uuid) {
            return response()->apiError('Not authorized to view this order.', 403);
        }

        return response()->json(OrderActivityFlow::forOrder($order));
    }

    /**
     * Get receipt for an order based on the payment method type.
     *
     * This method acts as a router that delegates receipt generation to the appropriate
     * handler based on the order's payment method. It performs authentication and authorization
     * checks before routing to the specific receipt handler.
     *
     * @param Request $request The HTTP request containing the order identifier
     *
     * @return JsonResponse The receipt data or error response
     */
    public function getReceipt(Request $request)
    {
        // Authenticate customer
        $customer = Storefront::getCustomerFromToken();
        if (!$customer) {
            return response()->apiError('Customer is not authenticated.');
        }

        // Retrieve order
        $order = Order::where('public_id', $request->order)
            ->whereNull('deleted_at')
            ->with(['customer', 'payload'])
            ->first();

        if (!$order) {
            return response()->apiError('No order found.');
        }

        // Authorize customer for this order
        if ($order->customer_uuid !== $customer->uuid) {
            return response()->apiError('Not authorized to get receipt for this order.');
        }

        // Route to appropriate receipt handler based on payment method
        $paymentMethod = $order->payload->payment_method ?? null;

        switch ($paymentMethod) {
            case 'qpay':
                return $this->getQpayEbarimtReceipt($request, $order);

                // Add more payment methods here in the future
                // case 'stripe':
                //     return $this->getStripeReceipt($request, $order);
                // case 'paypal':
                //     return $this->getPaypalReceipt($request, $order);

            default:
                return response()->json([
                    'message'        => 'No receipt available for this payment method.',
                    'payment_method' => $paymentMethod,
                ]);
        }
    }

    /**
     * Get Ebarimt receipt for QPay payment.
     *
     * This helper method handles the complete flow of generating an Ebarimt receipt
     * for orders paid through QPay. It validates the request parameters, retrieves
     * the payment information from QPay, creates the Ebarimt receipt, and stores
     * the receipt data in the order metadata.
     *
     * @param Request $request The HTTP request containing Ebarimt parameters
     * @param Order   $order   The order instance for which to generate the receipt
     *
     * @return JsonResponse The Ebarimt receipt data or error response
     */
    private function getQpayEbarimtReceipt(Request $request, Order $order)
    {
        // Extract and validate Ebarimt parameters
        $ebarimtReceiverType = strtoupper($request->input('ebarimt_receiver_type', 'CITIZEN'));
        $ebarimtReceiver     = $request->input('ebarimt_receiver');

        if ($ebarimtReceiverType === 'COMPANY' && empty($ebarimtReceiver)) {
            return response()->apiError('Company registration number is required.');
        }

        // Verify payment method is QPay
        $order->loadMissing('payload');
        if ($order->payload && $order->payload->payment_method !== 'qpay') {
            return response()->apiError('This order was not paid using QPay.');
        }

        // Retrieve checkout record
        $checkout = $order->getMeta('checkout_id')
            ? Checkout::where('public_id', $order->getMeta('checkout_id'))->first()
            : null;

        if (!$checkout) {
            return response()->apiError('No checkout found for this order.');
        }

        // Initialize QPay gateway
        $qpay = $this->initializeQpayGateway();
        if ($qpay instanceof JsonResponse) {
            return $qpay; // Return error response if gateway initialization failed
        }

        // Retrieve payment information from QPay
        $invoiceId = $checkout->getOption('qpay_invoice_id');
        $payment   = $qpay->getPayment($invoiceId);

        // Create Ebarimt receipt
        $ebarimt = $this->createEbarimtReceipt($qpay, $payment, $ebarimtReceiverType, $ebarimtReceiver);

        if ($ebarimt instanceof JsonResponse) {
            return $ebarimt; // Return error response if creation failed
        }

        // get ebarimt qr data
        $qrData = data_get($ebarimt, 'ebarimt_qr_data');
        if (!empty($qrData)) {
            $order->updateMeta('ebarimt_qr_data', $qrData);
        }

        // Store receipt in order metadata
        if ($order->doesntHaveMeta('ebarimt')) {
            $order->updateMeta('ebarimt', $ebarimt);
        }

        return response()->json($ebarimt);
    }

    /**
     * Initialize and configure the QPay gateway instance.
     *
     * This method retrieves the QPay gateway configuration, creates a QPay instance
     * with the appropriate credentials, configures sandbox mode if enabled, and
     * sets the authentication token.
     *
     * @return QPay|JsonResponse The configured QPay instance or error response
     */
    protected function initializeQpayGateway()
    {
        // Resolve gateway configuration
        $gateway = Storefront::findGateway('qpay');
        if (!$gateway) {
            return response()->apiError('QPay is not configured.');
        }

        // Create QPay instance with credentials
        $qpay = $this->createQpay(
            $gateway->config->username,
            $gateway->config->password,
            $gateway->callback_url
        );

        // Configure sandbox mode if enabled
        if ($gateway->sandbox) {
            $qpay = $qpay->useSandbox();
        }

        // Set authentication token
        $qpay = $qpay->setAuthToken();

        return $qpay;
    }

    protected function createQpay(?string $username, ?string $password, ?string $callbackUrl): QPay
    {
        return QPay::instance($username, $password, $callbackUrl);
    }

    /**
     * Create an Ebarimt receipt via QPay API.
     *
     * This method prepares the parameters for the Ebarimt receipt creation request,
     * sends the request to the QPay Ebarimt API, and handles any errors that occur
     * during the process.
     *
     * @param QPay        $qpay         The configured QPay instance
     * @param mixed       $payment      The payment information retrieved from QPay
     * @param string      $receiverType The type of receiver (CITIZEN or COMPANY)
     * @param string|null $receiver     The company registration number (required if receiverType is COMPANY)
     *
     * @return mixed|JsonResponse The Ebarimt receipt data or error response
     */
    protected function createEbarimtReceipt(QPay $qpay, $payment, string $receiverType, ?string $receiver = null)
    {
        // Prepare request parameters
        $params = [
            'payment_id'            => data_get($payment, 'payment_id'),
            'ebarimt_receiver_type' => $receiverType,
        ];

        // Add company registration number if receiver type is COMPANY
        if ($receiverType === 'COMPANY' && !empty($receiver)) {
            $params['ebarimt_receiver'] = $receiver;
        }

        // Send request to QPay Ebarimt API
        $ebarimt = $qpay->post('ebarimt_v3/create', $params);

        // Check for API errors
        if (isset($ebarimt->error)) {
            return response()->apiError(
                $ebarimt->error ?? $ebarimt->message ?? 'Unable to create ebarimt receipt'
            );
        }

        return $ebarimt;
    }
}
