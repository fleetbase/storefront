<?php

namespace Fleetbase\Storefront\Notifications;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Support\Utils;

/**
 * The customer paid the balance left after an edit (by the payment link): a receipt for it.
 */
class StorefrontOrderBalancePaid extends StorefrontOrderNotification
{
    public int $amount;

    public function __construct(Order $order, int $amount)
    {
        parent::__construct($order);

        $currency      = $order->getMeta('currency');
        $left          = (int) $order->getMeta('balance_due', 0);
        $this->amount  = $amount;
        $kind          = $order->isMeta('is_booking') ? 'booking' : 'order';
        $this->subject = $this->trans('order_balance_paid.subject_' . $kind, ['store' => $this->storefront->name]);
        $this->body    = $this->trans('order_balance_paid.received', ['amount' => Utils::moneyFormat($amount, $currency)]) . ' '
            . ($left > 0 ? $this->trans('order_balance_paid.left_to_pay', ['amount' => Utils::moneyFormat($left, $currency)]) : $this->trans('order_balance_paid.fully_paid_' . $kind));
        $this->status = 'order_balance_paid';
    }
}
