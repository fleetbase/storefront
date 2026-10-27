<?php

namespace Fleetbase\Storefront\Notifications;

use Fleetbase\FleetOps\Models\Order;

class StorefrontOrderEnroute extends StorefrontOrderNotification
{
    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(Order $order)
    {
        parent::__construct($order);

        $this->subject = $this->trans('order_enroute.subject', ['store' => $this->storefront->name]);
        $this->body    = $this->trans('order_enroute.body', ['store' => $this->storefront->name]);
        $this->status  = 'order_enroute';
    }
}
