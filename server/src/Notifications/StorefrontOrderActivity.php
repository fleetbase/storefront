<?php

namespace Fleetbase\Storefront\Notifications;

use Fleetbase\FleetOps\Models\Order;

/**
 * An order moved to a step of its order flow that has no notification of its own, such as
 * "Driver en-route to store", "Driver picked up" or a step from a custom order config.
 * The step's own label and details are what the customer sees.
 */
class StorefrontOrderActivity extends StorefrontOrderNotification
{
    public function __construct(Order $order, string $label, ?string $details = null)
    {
        parent::__construct($order);

        $this->subject = $label;
        $this->body    = $details ?: 'Your order from ' . $this->storefront->name . ' has been updated: ' . $label . '.';
        $this->status  = 'order_activity';
    }
}
