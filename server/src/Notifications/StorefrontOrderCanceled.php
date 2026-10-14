<?php

namespace Fleetbase\Storefront\Notifications;

use Fleetbase\FleetOps\Models\Order;

class StorefrontOrderCanceled extends StorefrontOrderNotification
{
    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(Order $order)
    {
        parent::__construct($order);

        $this->subject = 'Your order from ' . $this->storefront->name . ' was canceled';
        $this->body    = 'Your order from ' . $this->storefront->name . ' has been canceled' . static::reasonSentence($order) . ' ' . static::moneySentence($order);
        $this->status  = 'order_canceled';
    }

    protected static function reasonSentence(Order $order): string
    {
        $reason = trim((string) $order->getMeta('cancel_reason'));

        return $reason !== '' ? ': ' . rtrim($reason, '.') . '.' : '.';
    }

    /**
     * What happens to the customer's money, from the refunds recorded when it was canceled.
     */
    protected static function moneySentence(Order $order): string
    {
        $payment = \Fleetbase\Storefront\Support\OrderPayments::summary($order);
        if ($payment['store_credit_code'] && $payment['amount_credited'] > 0) {
            return 'Your payment was returned as store credit; use code ' . $payment['store_credit_code'] . ' on your next order.';
        }
        if ($payment['amount_refunded'] > 0) {
            return 'Your payment has been refunded and should reach you within a few days.';
        }

        return $payment['amount_paid'] > 0 ? 'If you were charged, you will be refunded.' : '';
    }

    /**
     * Get the mail representation of the notification.
     *
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        return parent::toMail($notifiable)->line('No further action is necessary.');
    }
}
