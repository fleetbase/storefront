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

        $this->item    = trim($item) !== '' ? trim($item) : 'An item';
        $this->subject = $this->item . ' is unavailable';
        $this->body    = $this->item . ' from your ' . ($order->isMeta('is_booking') ? 'booking' : 'order') . ' is unavailable. ' . $this->storefront->name . ' will replace or remove it and adjust your total if it changes.';
        $this->status  = 'order_item_unavailable';
    }
}
