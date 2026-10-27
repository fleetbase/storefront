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
        $moved     = $isBooking && $changes === ['schedule'] ? $this->bookingTime($order, $this->storefront instanceof Store ? $this->storefront : null) : null;
        $store     = ['store' => $this->storefront->name];

        if ($moved) {
            $this->subject = $this->trans('order_updated.subject_moved', $store);
            $this->body    = trim($moved . ' ' . $this->moneySentence($order));
        } else {
            $this->subject = $this->trans($isBooking ? 'order_updated.subject_booking' : 'order_updated.subject', $store);
            $this->body    = trim(($summary !== '' && $this->inEnglish() ? rtrim($summary, '.') . '.' : $this->trans('order_updated.body', $store)) . ' ' . $this->moneySentence($order));
        }
        $this->status = 'order_updated';
    }

    /**
     * The edit summary is written in English for the console and the driver as well, so a
     * customer in another language gets the general line instead of a mixed-language message.
     */
    protected function inEnglish(): bool
    {
        return ($this->locale ?? app()->getLocale()) === 'en';
    }

    /**
     * "Deep Clean is now Mon 19 Oct, 11:00 to 12:00.", in the store's time.
     */
    protected function bookingTime(Order $order, ?Store $store): ?string
    {
        $start = data_get($order->meta, 'booking.start') ?? data_get($order->meta, 'booking_at');
        if (!$start) {
            return null;
        }

        $timezone = BookingSlots::timezone($store);
        $locale   = $this->locale ?? app()->getLocale();
        $from     = Carbon::parse($start)->setTimezone($timezone)->locale($locale)->translatedFormat('D j M, H:i');
        $end      = data_get($order->meta, 'booking.end');
        $when     = $end ? $this->trans('order_updated.booking_time_range', ['start' => $from, 'end' => Carbon::parse($end)->setTimezone($timezone)->format('H:i')]) : $from;
        $service  = collect(data_get($order, 'payload.entities', []))->first(fn ($entity) => data_get($entity, 'meta.scheduled_at'));
        $name     = data_get($service, 'name');

        return $name
            ? $this->trans('order_updated.booking_time_named', ['service' => $name, 'when' => $when])
            : $this->trans('order_updated.booking_time', ['when' => $when]);
    }

    protected function moneySentence(Order $order): string
    {
        $payment = OrderPayments::summary($order);
        $last    = collect($payment['adjustments'])->last();

        return match ($last['type'] ?? null) {
            'refund'       => $this->trans('order_updated.refunded'),
            'store_credit' => $this->trans('order_updated.store_credit', ['code' => $last['reference'] ?? '']),
            default        => $payment['state'] === OrderPayments::STATE_BALANCE_DUE
                ? ($payment['payment_link'] ? $this->trans('order_updated.balance_due_link', ['link' => $payment['payment_link']]) : $this->trans('order_updated.balance_due'))
                : '',
        };
    }
}
