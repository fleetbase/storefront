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

        $this->subject = $this->trans('order_canceled.subject', ['store' => $this->storefront->name]);
        $this->body    = $this->reasonSentence($order) . ' ' . $this->moneySentence($order);
        $this->status  = 'order_canceled';
    }

    protected function reasonSentence(Order $order): string
    {
        $reason = trim((string) $order->getMeta('cancel_reason'));

        return $reason !== ''
            ? $this->trans('order_canceled.body_reason', ['store' => $this->storefront->name, 'reason' => rtrim($reason, '.')])
            : $this->trans('order_canceled.body', ['store' => $this->storefront->name]);
    }

    /**
     * What happens to the customer's money, from the refunds recorded when it was canceled.
     */
    protected function moneySentence(Order $order): string
    {
        $payment = \Fleetbase\Storefront\Support\OrderPayments::summary($order);
        if ($payment['store_credit_code'] && $payment['amount_credited'] > 0) {
            return $this->trans('order_canceled.store_credit', ['code' => $payment['store_credit_code']]);
        }
        if ($payment['amount_refunded'] > 0) {
            return $this->trans('order_canceled.refunded');
        }

        return $payment['amount_paid'] > 0 ? $this->trans('order_canceled.will_refund') : '';
    }

    /**
     * Get the mail representation of the notification.
     *
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        return parent::toMail($notifiable)->line($this->trans('order_canceled.no_action'));
    }
}
