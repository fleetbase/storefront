<?php

return [
    'order_accepted' => [
        'subject' => 'Your order from :store has been accepted.',
        'body'    => 'Your order was accepted.',
    ],
    'order_activity' => [
        'body' => 'Your order from :store has been updated: :activity.',
    ],
    'order_balance_paid' => [
        'subject_order'      => 'Payment received for your order from :store',
        'subject_booking'    => 'Payment received for your booking from :store',
        'received'           => 'We received your payment of :amount.',
        'left_to_pay'        => 'There is :amount left to pay.',
        'fully_paid_order'   => 'Your order is fully paid.',
        'fully_paid_booking' => 'Your booking is fully paid.',
    ],
    'order_canceled' => [
        'subject'      => 'Your order from :store was canceled',
        'body'         => 'Your order from :store has been canceled.',
        'body_reason'  => 'Your order from :store has been canceled: :reason.',
        'store_credit' => 'Your payment was returned as store credit; use code :code on your next order.',
        'refunded'     => 'Your payment has been refunded and should reach you within a few days.',
        'will_refund'  => 'If you were charged, you will be refunded.',
        'no_action'    => 'No further action is necessary.',
    ],
    'order_chat_message' => [
        'title'         => ':name sent a message',
        'default_name'  => 'Your driver',
        'photo'         => 'Sent a photo',
    ],
    'order_completed' => [
        'subject' => 'Your order from :store has been delivered',
        'body'    => 'Your order from :store has been delivered, enjoy!',
    ],
    'order_driver_assigned' => [
        'subject'         => 'Your driver is :driver',
        'body'            => 'A driver has been assigned to your order from :store, they are currently en-route for pickup',
        'subject_booking' => 'Your provider is :driver',
        'body_booking'    => ':driver will be your provider for your booking with :store.',
    ],
    'order_enroute' => [
        'subject' => 'Your order from :store is on the way!',
        'body'    => 'Your order from :store has been picked up, we will update you when your order is nearby',
    ],
    'order_item_unavailable' => [
        'default_item' => 'An item',
        'subject'      => ':item is unavailable',
        'body'         => ':item from your order is unavailable. :store will replace or remove it and adjust your total if it changes.',
        'body_booking' => ':item from your booking is unavailable. :store will replace or remove it and adjust your total if it changes.',
    ],
    'order_nearby' => [
        'subject' => 'Your order is nearby!',
        'body'    => 'Your order from :store is reaching in :time',
    ],
    'order_preparing' => [
        'subject' => 'Your order from :store is being prepared!',
        'body'    => 'Your order is getting started.',
    ],
    'order_ready' => [
        'subject' => 'Your order from :store is ready for pickup!',
        'body'    => 'You can proceed to pickup your order.',
    ],
    'order_updated' => [
        'subject'             => 'Your order from :store was updated',
        'subject_booking'     => 'Your booking with :store was updated',
        'subject_moved'       => 'Your booking with :store was moved',
        'body'                => 'Your order from :store was updated.',
        'booking_time'        => 'Your booking is now :when.',
        'booking_time_named'  => ':service is now :when.',
        'booking_time_range'  => ':start to :end',
        'refunded'            => 'The difference has been refunded.',
        'store_credit'        => 'The difference was added as store credit: use code :code on your next order.',
        'balance_due'         => 'There is a balance to pay.',
        'balance_due_link'    => 'There is a balance to pay: :link',
    ],
];
