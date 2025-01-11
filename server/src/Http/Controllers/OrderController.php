<?php

namespace Fleetbase\Storefront\Http\Controllers;

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrderController as FleetbaseOrderController;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Storefront\Notifications\StorefrontOrderPreparing;
use Fleetbase\Storefront\Support\Storefront;
use Illuminate\Http\Request;

class OrderController extends FleetbaseOrderController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'order';

    /**
     * The filter to use.
     *
     * @var \Fleetbase\Http\Filter\Filter
     */
    public $filter = \Fleetbase\Storefront\Http\Filter\OrderFilter::class;

    /**
     * Accept an order by incrementing status to preparing.
     *
     * @return \Illuminate\Http\Response
     */
    public function acceptOrder(Request $request)
    {
        $order = Order::where('uuid', $request->order)->whereNull('deleted_at')->with(['customer'])->first();

        if (!$order) {
            return response()->json([
                'error' => 'No order to accept!',
            ], 400);
        }

        // Patch order config
        Storefront::patchOrderConfig($order);

        // update activity to prepating
        $order->updateStatus('preparing');
        $order->customer->notify(new StorefrontOrderPreparing($order));

        return response()->json([
            'status' => 'ok',
            'order'  => $order->public_id,
            'status' => $order->status,
        ]);
    }

    /**
     * Accept an order by incrementing status to preparing.
     *
     * @return \Illuminate\Http\Response
     */
    public function markOrderAsReady(Request $request)
    {
        $adhoc  = $request->boolean('adhoc');
        $driver = $request->input('driver');
        /** @var Order $order */
        $order = Order::where('uuid', $request->order)->whereNull('deleted_at')->with(['customer'])->first();

        if (!$order) {
            return response()->json([
                'error' => 'No order to update!',
            ], 400);
        }

        // Patch order config
        Storefront::patchOrderConfig($order);

        if ($order->isMeta('is_pickup')) {
            $order->updateStatus('pickup_ready');

            return response()->json([
                'status' => 'ok',
                'order'  => $order->public_id,
                'status' => $order->status,
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
            'order'  => $order->public_id,
            'status' => $order->status,
        ]);
    }

    /**
     * Accept an order by incrementing status to preparing.
     *
     * @return \Illuminate\Http\Response
     */
    public function markOrderAsCompleted(Request $request)
    {
        /** @var Order */
        $order = Order::where('uuid', $request->order)->whereNull('deleted_at')->with(['customer'])->first();

        if (!$order) {
            return response()->json([
                'error' => 'No order to update!',
            ], 400);
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

    /**
     * Reject order and notify customer order is rejected/canceled.
     *
     * @return \Illuminate\Http\Response
     */
    public function rejectOrder(Request $request)
    {
        $order = Order::where('uuid', $request->order)->whereNull('deleted_at')->with(['customer'])->first();

        if (!$order) {
            return response()->json([
                'error' => 'No order to cancel!',
            ], 400);
        }

        // Patch order config
        Storefront::patchOrderConfig($order);

        // update activity to dispatched
        $order->updateStatus('cancel');

        return response()->json([
            'status' => 'ok',
            'order'  => $order->public_id,
            'status' => $order->status,
        ]);
    }
}
