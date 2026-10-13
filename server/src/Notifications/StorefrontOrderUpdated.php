<?php

namespace Fleetbase\Storefront\Notifications;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Storefront\Support\OrderPayments;

/**
 * The store changed the customer's order after it was placed: what changed, and what it means
 * for their money (a refund, store credit, or a balance to pay).
 */
class StorefrontOrderUpdated extends StorefrontOrderNotification
{
    public function __construct(Order $order, string $summary = '')
    {
        parent::__construct($order);

        $this->subject = 'Your order from ' . $this->storefront->name . ' was updated';
        $this->body    = trim(($summary !== '' ? rtrim($summary, '.') . '.' : 'Your order from ' . $this->storefront->name . ' was updated.') . ' ' . static::moneySentence($order));
        $this->status  = 'order_updated';
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
