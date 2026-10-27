<?php

namespace Fleetbase\Storefront\Notifications;

use Fleetbase\FleetOps\Models\Order;

class StorefrontOrderCompleted extends StorefrontOrderNotification
{
    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(Order $order)
    {
        parent::__construct($order);

        $this->subject = $this->trans('order_completed.subject', ['store' => $this->storefront->name]);
        $this->body    = $this->trans('order_completed.body', ['store' => $this->storefront->name]);
        $this->status  = 'order_completed';
    }
}
