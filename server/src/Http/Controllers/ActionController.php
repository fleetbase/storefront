<?php

namespace Fleetbase\Storefront\Http\Controllers;

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Storefront\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ActionController extends Controller
{
    /**
     * Get the number of storefronts created.
     *
     * @return \Illuminate\Http\Response
     */
    public function getStoreCount(Request $request)
    {
        $count = Store::where('company_uuid', session('company'))->count();

        return response()->json(['storeCount' => $count]);
    }

    /**
     * Get key metrics for storefront.
     *
     * @return \Illuminate\Http\Response
     */
    public function getMetrics(Request $request)
    {
        $store = $request->input('store');
        $start = $request->has('start') ? Carbon::fromString($request->input('start'))->toDateTimeString() : Carbon::now()->startOfMonth()->toDateTimeString();
        $end   = $request->has('end') ? Carbon::fromString($request->input('end'))->toDateTimeString() : Carbon::now()->toDateTimeString();

        // default metrics
        $metrics = [
            'orders_count'    => 0,
            'customers_count' => 0,
            'stores_count'    => 0,
            'earnings_sum'    => 0,
        ];

        // get the current active store
        if (!$store) {
            return response()->json($metrics);
        }

        $store = Store::where('uuid', $store)->first();
        if (!$store) {
            return response()->json($metrics);
        }

        // send back currency
        $metrics['currency'] = $store->currency;

        // - orders count
        $metrics['orders_count'] = Order::where([
            'company_uuid' => session('company'),
            'type'         => 'storefront',
        ])
            ->where('meta->storefront_id', $store->public_id)
            ->whereNotIn('status', ['canceled'])
            ->whereBetween('created_at', [$start, $end])->count();

        // - customers count -- change to where has orders where meta->storefront_id === store
        $metrics['customers_count'] = Contact::where([
            'company_uuid' => session('company'),
            'type'         => 'customer',
        ])->whereHas('customerOrders', function ($q) use ($start, $end, $store) {
            $q->whereBetween('created_at', [$start, $end]);
            $q->where('meta->storefront_id', $store->public_id);
            $q->whereNotIn('status', ['canceled']);
        })->count();

        // - stores count
        $metrics['stores_count'] = Store::where(['company_uuid' => session('company')])->count();

        // - earnings sum
        // $metrics['earnings_sum'] = Transaction::where(['company_uuid' => session('company'), 'type' => 'storefront', 'meta->storefront_id' => $store->public_id])->whereBetween('created_at', [$start, $end])->sum('amount');
        $metrics['earnings_sum'] = Order::where([
            'company_uuid' => session('company'),
            'type'         => 'storefront',
        ])
            ->whereBetween('created_at', [$start, $end])
            ->where('meta->storefront_id', $store->public_id)
            ->with(['transaction'])
            ->whereNotIn('status', ['canceled'])
            ->get()
            ->sum(function ($order) {
                return $order->transaction->amount;
            });

        return response()->json($metrics);
    }
}
