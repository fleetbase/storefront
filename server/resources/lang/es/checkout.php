<?php

return [
    // Cart and marketplace cart checks
    'cart_empty'                              => 'El carrito está vacío.',
    'cart_expired'                            => 'El carrito ha caducado',
    'cart_item_store_missing'                 => 'Cada artículo del carrito del marketplace debe indicar su tienda.',
    'cart_store_outside_marketplace'          => 'El carrito contiene una tienda que no pertenece a este marketplace.',
    'cart_store_offline'                      => 'Una tienda de este carrito no está disponible en este momento.',
    'marketplace_single_store_only'           => 'Este marketplace solo admite una tienda por carrito.',
    'cart_product_unavailable'                => 'Un producto de este carrito ya no está disponible en su tienda.',
    'cart_store_location_invalid'             => 'Una ubicación de tienda de este carrito ya no es válida.',
    'cart_mixed_currencies'                   => 'Los carritos del marketplace no pueden combinar monedas distintas.',
    'cart_items_unavailable'                  => 'Algunos artículos de tu carrito ya no están disponibles.',
    'invalid_cart_id'                         => 'El ID de carrito proporcionado no es válido',
    'invalid_cart_product'                    => '¡El producto añadido al carrito no es válido!',
    'invalid_cart_item'                       => '¡El artículo añadido al carrito no es válido!',
    'product_unavailable_in_catalog'          => 'Este producto no está disponible en el catálogo seleccionado.',
    'store_location_unavailable_for_product'  => 'La ubicación de tienda seleccionada no está disponible para este producto.',
    'booking_slot_taken'                      => 'La hora que elegiste para :product se acaba de reservar. Elige otra hora.',

    // Promotions
    'promotion_code_required'                 => 'Hace falta un código promocional.',
    'promotion_code_rejected'                 => 'No se puede aplicar el código promocional «:code» (:reason).',

    // Customer
    'invalid_customer_id'                     => 'El ID de cliente proporcionado no es válido',
    'customer_session_mismatch'               => 'El cliente no coincide con la sesión iniciada.',

    // Delivery quotes
    'delivery_origin_missing'                 => '¡No hay origen de entrega!',
    'delivery_destination_missing'            => '¡No hay destino de entrega!',
    'integrated_vendor_not_found'             => '¡No se ha encontrado el proveedor integrado!',
    'service_rates_unavailable'               => '¡No hay tarifas de servicio disponibles!',
    'marketplace_store_locations_unavailable' => 'Una o varias ubicaciones de tienda no están disponibles para este marketplace.',

    // Checkout session
    'checkout_initialize_failed'              => '¡No se ha podido iniciar el pago!',
    'checkout_session_not_found'              => 'No se ha encontrado la sesión de pago.',
    'checkout_not_found'                      => 'No se ha encontrado la compra',
    'checkout_token_missing'                  => 'Falta el token de pago.',
    'checkout_status_failed'                  => 'No se ha podido obtener el estado del pago',
    'status_parameters_missing'               => 'Faltan parámetros obligatorios: checkout y token',
    'notes_must_be_text'                      => 'Las notas deben ser texto.',

    // Payment gateways
    'gateway_not_configured'                  => '¡No hay ninguna pasarela de pago configurada!',
    'gateway_misconfigured'                   => '¡La pasarela de pago no está bien configurada!',
    'payment_not_paid'                        => '¡El pago no se ha completado!',
    'qpay_payment_incomplete'                 => 'El pago con QPay no se ha completado.',
    'stripe_not_setup'                        => 'Stripe no está configurado.',
    'stripe_gateway_not_configured'           => '¡No hay ninguna pasarela de Stripe configurada!',
    'stripe_authentication_failed'            => 'Ha fallado la autenticación con la pasarela de Stripe. Comprueba la clave secreta configurada.',
    'stripe_error'                            => 'Error de Stripe: :message',
    'payment_intent_retrieve_failed'          => 'No se ha podido obtener el PaymentIntent: :message',
    'payment_intent_update_failed'            => 'No se ha podido actualizar el PaymentIntent: :message',
    'payment_intent_not_updatable'            => 'El PaymentIntent no se puede actualizar en esta fase.',
    'payment_intent_other_checkout'           => 'El PaymentIntent pertenece a otra compra.',
    'ephemeral_key_failed'                    => 'No se ha podido crear la clave efímera: :message',
    'stripe_payment_intent_not_linked'        => 'El PaymentIntent de Stripe no está vinculado a esta compra.',
    'stripe_payment_verify_failed'            => 'No se ha podido verificar el pago con Stripe.',
    'stripe_payment_incomplete'               => 'El pago con Stripe no se ha completado.',
    'stripe_payment_mismatch'                 => 'El pago con Stripe no corresponde a esta compra.',

    // Order capture
    'capture_storefront_missing'              => '¡No hay ninguna tienda en la solicitud para registrar el pedido!',
    'capture_network_missing'                 => '¡No hay ninguna red en la solicitud para registrar el pedido!',
    'capture_in_progress'                     => 'El pedido ya se está registrando.',
    'order_store_not_found'                   => 'No se ha encontrado una de las tiendas de este pedido, así que no se ha realizado. No se ha usado tu pago.',
];
