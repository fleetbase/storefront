<?php

namespace Fleetbase\Storefront\Notifications;

use Fleetbase\FleetOps\Models\Order;

class StorefrontOrderAccepted extends StorefrontOrderNotification
{
    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(Order $order)
    {
        parent::__construct($order);

        $this->subject = $this->trans('order_accepted.subject', ['store' => $this->storefront->name]);
        $this->body    = $this->trans('order_accepted.body');
        $this->status  = 'order_accepted';
    }
}
