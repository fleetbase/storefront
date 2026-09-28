<?php

namespace Fleetbase\Storefront\Http\Controllers;

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Storefront\Models\Campaign;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Promotions\CampaignDispatcher;
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
        $start = $request->has('start') ? Carbon::parse($request->input('start'))->startOfDay() : Carbon::now()->startOfMonth();
        $end   = $request->has('end') ? Carbon::parse($request->input('end'))->endOfDay() : Carbon::now()->endOfDay();

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
            ->whereNull('deleted_at')
            ->get()
            ->sum(function ($order) {
                $orderTotal = data_get($order, 'meta.total');

                return is_numeric($orderTotal) ? (float) $orderTotal : (float) data_get($order, 'transaction.amount', 0);
            });

        return response()->json($metrics);
    }

    /**
     * Send a promotional notification to selected customers, or to all of them.
     *
     * The message is sent as a campaign (push and inbox), so it is recorded, honors customers'
     * promotion preferences and is delivered in queued batches. `sent_count` and `total` are
     * the number of customers targeted.
     *
     * @return \Illuminate\Http\Response
     */
    public function sendPushNotification(Request $request)
    {
        $title       = $request->input('title');
        $body        = $request->input('body');
        $customerIds = $request->input('customers', []);
        $storeId     = $request->input('store');
        $selectAll   = $request->boolean('select_all', false);

        // Validate inputs
        if (!$title || !$body) {
            return response()->json(['error' => 'Title and body are required'], 400);
        }

        if (!$selectAll && empty($customerIds)) {
            return response()->json(['error' => 'At least one customer must be selected'], 400);
        }

        // Get the store
        $store = Store::where('public_id', $storeId)->where('company_uuid', session('company'))->first();
        if (!$store) {
            return response()->json(['error' => 'Store not found'], 404);
        }

        // Only the company's own customers can be targeted
        $recipients = null;
        if (!$selectAll) {
            $recipients = Contact::whereIn('uuid', (array) $customerIds)
                ->where('company_uuid', session('company'))
                ->where('type', 'customer')
                ->pluck('uuid')
                ->all();

            if (empty($recipients)) {
                return response()->json(['status' => 'OK', 'sent_count' => 0, 'total' => 0]);
            }
        }

        $campaign = Campaign::create([
            'company_uuid'    => $store->company_uuid,
            'created_by_uuid' => session('user'),
            'owner_uuid'      => $store->uuid,
            'owner_type'      => Store::class,
            'name'            => 'Push notification: ' . $title,
            'status'          => Campaign::STATUS_SCHEDULED,
            'channels'        => [Campaign::CHANNEL_PUSH, Campaign::CHANNEL_INBOX],
            'title'           => $title,
            'body'            => $body,
            'recipients'      => $recipients,
            'send_at'         => now(),
        ]);
        app(CampaignDispatcher::class)->dispatch($campaign);

        $targeted = (int) data_get($campaign->refresh()->stats, 'targeted', 0);

        return response()->json([
            'status'     => 'OK',
            'sent_count' => $targeted,
            'total'      => $targeted,
            'campaign'   => $campaign->public_id,
        ]);
    }
}
