<?php

namespace Fleetbase\Storefront\Notifications;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Support\BookingSlots;
use Fleetbase\Storefront\Support\OrderPayments;
use Illuminate\Support\Carbon;

/**
 * The store changed the customer's order after it was placed: what changed, and what it means
 * for their money (a refund, store credit, or a balance to pay). A booking that only moved says
 * when it is now, in the store's time.
 */
class StorefrontOrderUpdated extends StorefrontOrderNotification
{
    /**
     * @param array $changes what the edit changed (`items`, `dropoff`, `schedule`, …)
     */
    public function __construct(Order $order, string $summary = '', array $changes = [])
    {
        parent::__construct($order);

        $isBooking = (bool) $order->isMeta('is_booking');
        $moved     = $isBooking && $changes === ['schedule'] ? static::bookingTime($order, $this->storefront instanceof Store ? $this->storefront : null) : null;

        if ($moved) {
            $this->subject = 'Your booking with ' . $this->storefront->name . ' was moved';
            $this->body    = trim($moved . ' ' . static::moneySentence($order));
        } else {
            $this->subject = 'Your ' . ($isBooking ? 'booking with ' : 'order from ') . $this->storefront->name . ' was updated';
            $this->body    = trim(($summary !== '' ? rtrim($summary, '.') . '.' : 'Your order from ' . $this->storefront->name . ' was updated.') . ' ' . static::moneySentence($order));
        }
        $this->status = 'order_updated';
    }

    /**
     * "Deep Clean is now Mon 19 Oct, 11:00 to 12:00.", in the store's time.
     */
    protected static function bookingTime(Order $order, ?Store $store): ?string
    {
        $start = data_get($order->meta, 'booking.start') ?? data_get($order->meta, 'booking_at');
        if (!$start) {
            return null;
        }

        $timezone = BookingSlots::timezone($store);
        $from     = Carbon::parse($start)->setTimezone($timezone);
        $end      = data_get($order->meta, 'booking.end');
        $when     = $from->format('D j M, H:i') . ($end ? ' to ' . Carbon::parse($end)->setTimezone($timezone)->format('H:i') : '');
        $service  = collect(data_get($order, 'payload.entities', []))->first(fn ($entity) => data_get($entity, 'meta.scheduled_at'));
        $name     = data_get($service, 'name');

        return ($name ? $name . ' is now ' : 'Your booking is now ') . $when . '.';
    }

    protected static function moneySentence(Order $order): string
    {
        $payment = OrderPayments::summary($order);
        $last    = collect($payment['adjustments'])->last();

        return match ($last['type'] ?? null) {
            'refund'       => 'The difference has been refunded.',
            'store_credit' => 'The difference was added as store credit: use code ' . ($last['reference'] ?? '') . ' on your next order.',
            default        => $payment['state'] === OrderPayments::STATE_BALANCE_DUE ? 'There is a balance to pay' . ($payment['payment_link'] ? ': ' . $payment['payment_link'] : '.') : '',
        };
    }
}
