<?php

return [
    'customer_not_authenticated' => 'Customer is not authenticated.',

    'session' => [
        'key_missing' => 'Oops! No Storefront key found with this request',
        'key_invalid' => 'Oops! The Storefront key provided was not valid',
    ],

    'validation' => [
        'cart_not_found'     => 'Cart session does not exists.',
        'customer_not_found' => 'No customer found.',
        'gateway_not_found'  => 'No gateway by code provided exists.',
        'invalid_location'   => 'Invalid :attribute.',
    ],

    'orders' => [
        'no_order_found'                => 'No order found.',
        'not_found'                     => 'Order not found.',
        'not_authorized_pickup'         => 'Not authorized to pickup this order for completion.',
        'not_authorized_view'           => 'Not authorized to view this order.',
        'not_authorized_receipt'        => 'Not authorized to get receipt for this order.',
        'receipt_unavailable'           => 'No receipt available for this payment method.',
        'company_registration_required' => 'Company registration number is required.',
        'not_paid_with_qpay'            => 'This order was not paid using QPay.',
        'checkout_not_found'            => 'No checkout found for this order.',
        'qpay_not_configured'           => 'QPay is not configured.',
        'ebarimt_receipt_failed'        => 'Unable to create ebarimt receipt',
    ],

    'notifications' => [
        'not_authorized_view'               => 'Not authorized to view customer notifications',
        'not_authorized_update'             => 'Not authorized to update customer notifications',
        'not_authorized_delete'             => 'Not authorized to delete customer notifications',
        'not_found'                         => 'Notification not found.',
        'not_authorized_view_preferences'   => 'Not authorized to view notification preferences',
        'not_authorized_update_preferences' => 'Not authorized to update notification preferences',
        'preference_must_be_boolean'        => 'The :key preference must be true or false.',
    ],

    'reviews' => [
        'cannot_find_for_store'  => 'Cannot find reviews for store',
        'cannot_count_for_store' => 'Cannot count reviews for store',
        'not_found'              => 'Review resource not found.',
        'subject_required'       => 'A subject is required.',
        'invalid_subject'        => 'Invalid subject for review',
        'disabled'               => 'Reviews are turned off.',
        'not_authorized_create'  => 'Not authorized to create reviews',
        'not_authorized_delete'  => 'Not authorized to delete review',
        'sign_in_required'       => 'Sign in to write a review.',
        'unsupported_subject'    => 'Only stores and products can be reviewed.',
        'no_completed_order'     => 'You can review this after an order with it is completed.',
        'already_reviewed'       => 'You have already reviewed this for your completed orders.',
    ],

    'chat' => [
        'address_locked'      => 'The address of this order can no longer change.',
        'place_not_found'     => 'Place not found.',
        'message_not_found'   => 'Message not found.',
        'closed'              => 'This chat closed when the order finished.',
        'not_participant'     => 'You are not part of this chat.',
        'store_unavailable'   => 'This order has no chat with the store.',
        'driver_not_assigned' => 'You can message your driver once one is assigned to this order.',
    ],

    'stores' => [
        'not_found'                      => 'Unable to find store!',
        'lookup_id_required'             => 'No ID provided for lookup.',
        'store_or_network_not_found'     => 'Unable to find store or network for ID provided.',
        'networks_cannot_have_locations' => 'Networks cannot have locations!',
        'location_not_found'             => 'Store location not found!',
    ],

    'networks' => [
        'stores_cannot_have_stores'    => 'Stores cannot have stores!',
        'locations_require_storefront' => 'Store locations require a storefront context!',
        'tags_require_storefront'      => 'Tags require a storefront context!',
    ],

    'products' => [
        'not_found'                  => 'Product not found.',
        'resource_not_found'         => 'Product resource not found.',
        'bookable_service_not_found' => 'Bookable service not found.',
    ],

    'food_trucks' => [
        'not_found' => 'Food Truck resource not found.',
    ],

    'promotions' => [
        'not_found' => 'Promotion not found.',
    ],
];
