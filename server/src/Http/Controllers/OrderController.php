<?php

namespace Fleetbase\Storefront\Http\Controllers;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrderController as FleetbaseOrderController;
use Fleetbase\Storefront\Notifications\StorefrontOrderPreparing;
use Illuminate\Http\Request;

class OrderController extends FleetbaseOrderController
{
    /**
     * The resource to query
     *
     * @var string
     */
    public $resource = 'order';

    /**
     * The filter to use
     *
     * @var \Fleetbase\Http\Filter\Filter
     */
    public $filter = \Fleetbase\Storefront\Http\Filter\OrderFilter::class;

    /**
     * Accept an order by incrementing status to preparing.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function acceptOrder(Request $request)
    {
        $order = Order::where('uuid', $request->order)->whereNull('deleted_at')->with(['customer'])->first();

        if (!$order) {
            return response()->json([
                'error' => 'No order to accept!'
            ], 400);
        }

        // update activity to prepating
        $order->updateStatus('preparing');
        $order->customer->notify(new StorefrontOrderPreparing($order));

        return response()->json([
            'status' => 'ok',
            'order' => $order->public_id,
            'status' => $order->status
        ]);
    }

    /**
     * Accept an order by incrementing status to preparing.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function markOrderAsReady(Request $request)
    {
        $adhoc = $request->boolean('adhoc');
        $driver = $request->input('driver');
        /** @var \Fleetbase\Models\Order $order */
        $order = Order::where('uuid', $request->order)->whereNull('deleted_at')->with(['customer'])->first();

        if (!$order) {
            return response()->json([
                'error' => 'No order to update!'
            ], 400);
        }

        if ($order->isMeta('is_pickup')) {
            $order->updateStatus('ready');

            return response()->json([
                'status' => 'ok',
                'order' => $order->public_id,
                'status' => $order->status
            ]);
        }

        // toggle order to adhoc
        if ($order->adhoc === false && $adhoc === true) {
            $order->update(['adhoc' => true]);
        }

        // if driver then assign driver
        if ($driver) {
            $order->assignDriver($driver);
        }

        // update activity to dispatched
        $order->updateStatus('dispatched');

        return response()->json([
            'status' => 'ok',
            'order' => $order->public_id,
            'status' => $order->status
        ]);
    }

    /**
     * Accept an order by incrementing status to preparing.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function markOrderAsCompleted(Request $request)
    {
        /** @var Order */
        $order = Order::where('uuid', $request->order)->whereNull('deleted_at')->with(['customer'])->first();

        if (!$order) {
            return response()->json([
                'error' => 'No order to update!'
            ], 400);
        }

        // update activity to completed
        $order->updateStatus('completed');

        return response()->json([
            'status' => 'ok',
            'order' => $order->public_id,
            'status' => $order->status
        ]);
    }

    /**
     * Reject order and notify customer order is rejected/canceled.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function rejectOrder(Request $request)
    {
        $order = Order::where('uuid', $request->order)->whereNull('deleted_at')->with(['customer'])->first();

        if (!$order) {
            return response()->json([
                'error' => 'No order to cancel!'
            ], 400);
        }

        // update activity to dispatched
        $order->updateStatus('cancel');

        return response()->json([
            'status' => 'ok',
            'order' => $order->public_id,
            'status' => $order->status
        ]);
    }
}
