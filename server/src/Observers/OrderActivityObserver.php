<?php

namespace Fleetbase\Storefront\Observers;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Storefront\Notifications\StorefrontOrderActivity;
use Fleetbase\Storefront\Support\OrderActivityFlow;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Cache;

/**
 * Tells a storefront customer about each step their order reaches.
 *
 * Fleet-Ops raises no event when an order's activity is updated, only the status change, so
 * this follows the status. Steps that already send their own notification are left to it.
 */
class OrderActivityObserver
{
    /** Statuses notified elsewhere: acceptance, dispatch/ready for pickup, start and completion. */
    public const NOTIFIED_ELSEWHERE = ['created', 'pending', 'accepted', 'dispatched', 'started', 'completed', 'pickup_ready'];

    public function updated(Order $order): void
    {
        if (!$order->wasChanged('status') || !$order->hasMeta('storefront_id')) {
            return;
        }

        $status = (string) $order->status;
        if ($status === '' || in_array($status, static::NOTIFIED_ELSEWHERE, true)) {
            return;
        }

        $orderUuid = $order->uuid;

        // After the change is committed, so the step's tracking status is recorded too.
        dispatch(function () use ($orderUuid, $status) {
            OrderActivityObserver::notify($orderUuid, $status);
        })->afterCommit();
    }

    public static function notify(string $orderUuid, string $status): void
    {
        // An activity update can save the order more than once; each step is told once.
        if (!Cache::add('storefront:order-activity:' . $orderUuid . ':' . $status, true, now()->addDay())) {
            return;
        }

        try {
            $order = Order::where('uuid', $orderUuid)->with(['customer'])->first();
            if (!$order || !$order->customer || $order->status !== $status) {
                return;
            }

            $flow = OrderActivityFlow::forOrder($order);
            $step = collect($flow['steps'] ?? [])->firstWhere('code', $status);
            $label = data_get($step, 'label') ?: ucfirst(str_replace('_', ' ', $status));

            $order->customer->notify(new StorefrontOrderActivity($order, $label, data_get($step, 'details') ?: null));
        } catch (\Throwable $e) {
            app(ExceptionHandler::class)->report($e);
        }
    }
}
