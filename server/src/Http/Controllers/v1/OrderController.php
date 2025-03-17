<?php

namespace Fleetbase\Storefront\Http\Controllers\v1;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Storefront\Support\Storefront;
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
        $order    = Order::where('public_id', $request->order)->whereNull('deleted_at')->with(['customer'])->first();

        if (!$order) {
            return response()->apiError('No order found.');
        }

        // Confirm the completion is done by the customer
        if ($order->customer_uuid !== $customer->uuid) {
            return response()->apiError('Not authorized to pickup this order for completion.');
        }

        // Patch order config
        Storefront::patchOrderConfig($order);

        // update activity to completed
        $order->updateStatus('completed');

        return response()->json([
            'status' => 'ok',
            'order'  => $order->public_id,
            'status' => $order->status,
        ]);
    }
}
