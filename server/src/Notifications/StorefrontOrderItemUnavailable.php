<?php

namespace Fleetbase\Storefront\Notifications;

use Fleetbase\FleetOps\Models\Order;

/**
 * The store marked an item of the customer's order unavailable: the customer hears straight away
 * that it will be replaced or removed, and that the total follows.
 */
class StorefrontOrderItemUnavailable extends StorefrontOrderNotification
{
    public string $item;

    public function __construct(Order $order, string $item)
    {
        parent::__construct($order);

        $this->item    = trim($item) !== '' ? trim($item) : $this->trans('order_item_unavailable.default_item');
        $this->subject = $this->trans('order_item_unavailable.subject', ['item' => $this->item]);
        $this->body    = $this->trans('order_item_unavailable.' . ($order->isMeta('is_booking') ? 'body_booking' : 'body'), ['item' => $this->item, 'store' => $this->storefront->name]);
        $this->status  = 'order_item_unavailable';
    }
}
