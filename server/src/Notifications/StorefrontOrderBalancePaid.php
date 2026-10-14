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
        $this->subject = 'Payment received for your ' . ($order->isMeta('is_booking') ? 'booking' : 'order') . ' from ' . $this->storefront->name;
        $this->body    = 'We received your payment of ' . Utils::moneyFormat($amount, $currency) . '. '
            . ($left > 0 ? 'There is ' . Utils::moneyFormat($left, $currency) . ' left to pay.' : 'Your ' . ($order->isMeta('is_booking') ? 'booking' : 'order') . ' is fully paid.');
        $this->status = 'order_balance_paid';
    }
}
