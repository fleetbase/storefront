<?php

namespace Fleetbase\Storefront\Support;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\TrackingCode;
use Fleetbase\Models\ChatMessage;
use Fleetbase\Storefront\Models\Store;
use Illuminate\Support\Carbon;

/**
 * What a dispatcher needs on top of the order record to act on it: when it is due, what needs
 * attention, who the customer is to the store, and where to send people (tracking, label).
 */
class OrderInsights
{
    /** Statuses after which nothing is late and no alert is raised. */
    public const FINISHED = ['completed', 'picked_up', 'canceled', 'expired', 'failed', 'order_canceled'];

    /** Minutes a store takes to prepare an order when it has not said (store option `prep_minutes`). */
    public const DEFAULT_PREP_MINUTES = 20;

    public static function forOrder(Order $order): array
    {
        $dueAt = static::dueAt($order);

        return [
            'due_at'           => $dueAt?->toIso8601String(),
            'late_minutes'     => static::lateMinutes($order, $dueAt),
            'alerts'           => static::alerts($order, $dueAt),
            'customer_summary' => static::customerSummary($order),
            'tracking_url'     => static::trackingUrl($order),
        ];
    }

    public static function isFinished(Order $order): bool
    {
        return in_array((string) $order->status, static::FINISHED, true);
    }

    /**
     * When the order is due: the booked or scheduled time, else placed + preparation + the
     * estimated drive.
     */
    public static function dueAt(Order $order): ?Carbon
    {
        $booking = $order->getMeta('booking_at') ?? data_get($order->getMeta('booking'), 'start');
        if ($booking) {
            return Carbon::parse($booking);
        }

        if ($order->scheduled_at) {
            return Carbon::parse($order->scheduled_at);
        }

        if (!$order->created_at) {
            return null;
        }

        $drive = $order->isMeta('is_pickup') ? 0 : (int) ceil(((int) $order->time) / 60);

        return Carbon::parse($order->created_at)->addMinutes(static::prepMinutes($order) + $drive);
    }

    public static function prepMinutes(Order $order): int
    {
        $store = Store::where('public_id', $order->getMeta('storefront_id'))->first();
        $value = $store ? (int) data_get($store->options, 'prep_minutes', 0) : 0;

        return $value > 0 ? $value : static::DEFAULT_PREP_MINUTES;
    }

    public static function lateMinutes(Order $order, ?Carbon $dueAt): int
    {
        if (!$dueAt || static::isFinished($order) || now()->lessThanOrEqualTo($dueAt)) {
            return 0;
        }

        return (int) $dueAt->diffInMinutes(now());
    }

    /**
     * What needs attention, most urgent first. Each alert names its code; the console maps codes
     * to actions (update address, reassign, tell the customer, ...).
     */
    public static function alerts(Order $order, ?Carbon $dueAt = null): array
    {
        if (static::isFinished($order)) {
            return [];
        }

        $alerts = [];

        $request = $order->getMeta('address_change_request');
        if (is_array($request) && ($request['status'] ?? 'open') === 'open') {
            $alerts[] = [
                'code'    => 'address_change_requested',
                'message' => $request['message'] ?? 'The customer asked to change the address.',
                'address' => $request['address'] ?? null,
                'at'      => $request['at'] ?? null,
            ];
        }

        $payment = OrderPayments::summary($order);
        if ($payment['state'] === OrderPayments::STATE_BALANCE_DUE) {
            $alerts[] = [
                'code'    => 'balance_due',
                'message' => 'A balance is due after an edit.',
                'amount'  => $payment['balance_due'],
            ];
        }

        $offer = $order->getMeta('offer');
        if (is_array($offer)) {
            $expired = ($offer['status'] ?? null) === 'expired'
                || (($offer['status'] ?? null) === 'offered' && !empty($offer['expires_at']) && now()->greaterThan(Carbon::parse($offer['expires_at'])));
            if ($expired) {
                $alerts[] = [
                    'code'    => 'driver_not_accepted',
                    'message' => 'The driver has not accepted the order.',
                    'at'      => $offer['offered_at'] ?? null,
                    'driver'  => $offer['driver'] ?? null,
                ];
            }
        }

        $unavailable = collect(data_get($order, 'payload.entities', []))
            ->filter(fn ($entity) => (bool) data_get($entity, 'meta.unavailable'))
            ->map(fn ($entity) => $entity->name ?? data_get($entity, 'name'))
            ->values();
        if ($unavailable->isNotEmpty()) {
            $alerts[] = [
                'code'    => 'item_unavailable',
                'message' => 'Unavailable: ' . $unavailable->implode(', '),
                'items'   => $unavailable->all(),
            ];
        }

        $late = static::lateMinutes($order, $dueAt ?? static::dueAt($order));
        if ($late > 0) {
            $alerts[] = [
                'code'    => 'late',
                'message' => $late . ' min past due',
                'minutes' => $late,
            ];
        }

        return $alerts;
    }

    public static function customerSummary(Order $order): ?array
    {
        if (!$order->customer_uuid) {
            return null;
        }

        $storeId = $order->getMeta('storefront_id');
        $count   = Order::where('customer_uuid', $order->customer_uuid)
            ->whereNull('deleted_at')
            ->when($storeId, fn ($query) => $query->where('meta->storefront_id', $storeId))
            ->count();

        return [
            'orders_count' => $count,
            'since'        => optional(data_get($order, 'customer.created_at'))->toIso8601String(),
            'last_message' => static::lastMessage($order),
        ];
    }

    public static function lastMessage(Order $order): ?array
    {
        try {
            $channel = OrderChat::find($order);
        } catch (\Throwable $e) {
            return null;
        }

        if (!$channel) {
            return null;
        }

        $message = ChatMessage::where('chat_channel_uuid', $channel->uuid)->latest()->first();
        if (!$message) {
            return null;
        }

        return [
            'content' => $message->content,
            'at'      => optional($message->created_at)->toIso8601String(),
            'from'    => data_get($message, 'sender.user.name') ?? data_get($message, 'sender.name'),
        ];
    }

    public static function trackingUrl(Order $order): ?string
    {
        $number = data_get($order, 'trackingNumber.tracking_number') ?? $order->tracking;
        if (!$number || !class_exists(TrackingCode::class)) {
            return null;
        }

        try {
            return TrackingCode::pageUrl($number, $order->company_uuid);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
