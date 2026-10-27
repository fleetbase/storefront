<?php

return [
    // Cart and marketplace cart checks
    'cart_empty'                              => 'The cart is empty.',
    'cart_expired'                            => 'Cart expired',
    'cart_item_store_missing'                 => 'Every marketplace cart item must identify its store.',
    'cart_store_outside_marketplace'          => 'The cart contains a store outside this marketplace.',
    'cart_store_offline'                      => 'A store in this cart is currently offline.',
    'marketplace_single_store_only'           => 'This marketplace only supports one store per cart.',
    'cart_product_unavailable'                => 'A product in this cart is no longer available from its store.',
    'cart_store_location_invalid'             => 'A store location in this cart is no longer valid.',
    'cart_mixed_currencies'                   => 'Marketplace carts cannot combine different currencies.',
    'cart_items_unavailable'                  => 'Some items in your cart are no longer available.',
    'invalid_cart_id'                         => 'Invalid cart ID provided',
    'invalid_cart_product'                    => 'Invalid product provided to cart!',
    'invalid_cart_item'                       => 'Invalid cart item provided to cart!',
    'product_unavailable_in_catalog'          => 'This product is not available in the selected catalog.',
    'store_location_unavailable_for_product'  => 'The selected store location is not available for this product.',
    'booking_slot_taken'                      => 'The time you picked for :product was just booked. Please pick another time.',

    // Promotions
    'promotion_code_required'                 => 'A promotion code is required.',
    'promotion_code_rejected'                 => 'Promotion code ":code" cannot be applied (:reason).',

    // Customer
    'invalid_customer_id'                     => 'Invalid customer ID provided',
    'customer_session_mismatch'               => 'Customer does not match the authenticated session.',

    // Delivery quotes
    'delivery_origin_missing'                 => 'No delivery origin!',
    'delivery_destination_missing'            => 'No delivery destination!',
    'integrated_vendor_not_found'             => 'Integrated vendor not found!',
    'service_rates_unavailable'               => 'No service rates available!',
    'marketplace_store_locations_unavailable' => 'One or more store locations are unavailable for this marketplace.',

    // Checkout session
    'checkout_initialize_failed'              => 'Unable to initialize checkout!',
    'checkout_session_not_found'              => 'Checkout session not found.',
    'checkout_not_found'                      => 'Checkout not found',
    'checkout_token_missing'                  => 'Missing checkout token.',
    'checkout_status_failed'                  => 'Failed to retrieve checkout status',
    'status_parameters_missing'               => 'Missing required parameters: checkout and token',
    'notes_must_be_text'                      => 'Notes must be text.',

    // Payment gateways
    'gateway_not_configured'                  => 'No gateway configured!',
    'gateway_misconfigured'                   => 'Gateway not configured correctly!',
    'payment_not_paid'                        => 'Payment has not been paid!',
    'qpay_payment_incomplete'                 => 'QPay payment has not been completed.',
    'stripe_not_setup'                        => 'Stripe not setup.',
    'stripe_gateway_not_configured'           => 'No stripe gateway configured!',
    'stripe_authentication_failed'            => 'Stripe gateway authentication failed. Verify the configured secret key.',
    'stripe_error'                            => 'Error from Stripe: :message',
    'payment_intent_retrieve_failed'          => 'Failed to retrieve PaymentIntent: :message',
    'payment_intent_update_failed'            => 'Failed to update PaymentIntent: :message',
    'payment_intent_not_updatable'            => 'PaymentIntent cannot be updated at this stage.',
    'payment_intent_other_checkout'           => 'PaymentIntent belongs to another checkout.',
    'ephemeral_key_failed'                    => 'Failed to create ephemeral key: :message',
    'stripe_payment_intent_not_linked'        => 'Stripe PaymentIntent is not linked to this checkout.',
    'stripe_payment_verify_failed'            => 'Unable to verify Stripe payment.',
    'stripe_payment_incomplete'               => 'Stripe payment has not been completed.',
    'stripe_payment_mismatch'                 => 'Stripe payment does not match this checkout.',

    // Order capture
    'capture_storefront_missing'              => 'No storefront in request to capture order!',
    'capture_network_missing'                 => 'No network in request to capture order!',
    'capture_in_progress'                     => 'Order capture is already in progress.',
    'order_store_not_found'                   => 'A store in this order could not be found, so it was not placed. Your payment has not been used.',
];
