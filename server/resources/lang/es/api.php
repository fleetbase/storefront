<?php

return [
    'customer_not_authenticated' => 'El cliente no ha iniciado sesión.',

    'session' => [
        'key_missing' => '¡Vaya! No se ha encontrado ninguna clave de Storefront en esta solicitud',
        'key_invalid' => '¡Vaya! La clave de Storefront proporcionada no es válida',
    ],

    'validation' => [
        'cart_not_found'     => 'La sesión del carrito no existe.',
        'customer_not_found' => 'No se ha encontrado ningún cliente.',
        'gateway_not_found'  => 'No existe ninguna pasarela de pago con el código proporcionado.',
        'invalid_location'   => 'El valor de :attribute no es válido.',
    ],

    'orders' => [
        'no_order_found'                => 'No se ha encontrado ningún pedido.',
        'not_found'                     => 'Pedido no encontrado.',
        'not_authorized_pickup'         => 'No tienes autorización para recoger este pedido y completarlo.',
        'not_authorized_view'           => 'No tienes autorización para ver este pedido.',
        'not_authorized_receipt'        => 'No tienes autorización para obtener el recibo de este pedido.',
        'receipt_unavailable'           => 'No hay recibo disponible para este método de pago.',
        'company_registration_required' => 'El número de registro de la empresa es obligatorio.',
        'not_paid_with_qpay'            => 'Este pedido no se pagó con QPay.',
        'checkout_not_found'            => 'No se ha encontrado ninguna compra para este pedido.',
        'qpay_not_configured'           => 'QPay no está configurado.',
        'ebarimt_receipt_failed'        => 'No se ha podido crear el recibo de ebarimt',
    ],

    'notifications' => [
        'not_authorized_view'               => 'No tienes autorización para ver las notificaciones del cliente',
        'not_authorized_update'             => 'No tienes autorización para actualizar las notificaciones del cliente',
        'not_authorized_delete'             => 'No tienes autorización para eliminar las notificaciones del cliente',
        'not_found'                         => 'Notificación no encontrada.',
        'not_authorized_view_preferences'   => 'No tienes autorización para ver las preferencias de notificaciones',
        'not_authorized_update_preferences' => 'No tienes autorización para actualizar las preferencias de notificaciones',
        'preference_must_be_boolean'        => 'La preferencia :key debe ser verdadera o falsa.',
    ],

    'reviews' => [
        'cannot_find_for_store'  => 'No se han podido encontrar las reseñas de la tienda',
        'cannot_count_for_store' => 'No se han podido contar las reseñas de la tienda',
        'not_found'              => 'Reseña no encontrada.',
        'subject_required'       => 'Hay que indicar qué se reseña.',
        'invalid_subject'        => 'Elemento de la reseña no válido',
        'disabled'               => 'Las reseñas están desactivadas.',
        'not_authorized_create'  => 'No tienes autorización para crear reseñas',
        'not_authorized_delete'  => 'No tienes autorización para eliminar la reseña',
        'sign_in_required'       => 'Inicia sesión para escribir una reseña.',
        'unsupported_subject'    => 'Solo se pueden reseñar tiendas y productos.',
        'no_completed_order'     => 'Podrás escribir una reseña cuando se complete un pedido que lo incluya.',
        'already_reviewed'       => 'Ya has escrito una reseña de esto para tus pedidos completados.',
    ],

    'chat' => [
        'address_locked'      => 'La dirección de este pedido ya no se puede cambiar.',
        'place_not_found'     => 'Dirección no encontrada.',
        'message_not_found'   => 'Mensaje no encontrado.',
        'closed'              => 'Este chat se cerró al terminar el pedido.',
        'not_participant'     => 'No formas parte de este chat.',
        'store_unavailable'   => 'Este pedido no tiene chat con la tienda.',
        'driver_not_assigned' => 'Podrás escribir a tu repartidor cuando se asigne uno a este pedido.',
    ],

    'stores' => [
        'not_found'                      => '¡No se ha podido encontrar la tienda!',
        'lookup_id_required'             => 'No se ha proporcionado ningún ID para la búsqueda.',
        'store_or_network_not_found'     => 'No se ha podido encontrar ninguna tienda ni red con el ID proporcionado.',
        'networks_cannot_have_locations' => '¡Las redes no pueden tener ubicaciones!',
        'location_not_found'             => '¡No se ha encontrado la ubicación de la tienda!',
    ],

    'networks' => [
        'stores_cannot_have_stores'    => '¡Las tiendas no pueden tener tiendas!',
        'locations_require_storefront' => '¡Las ubicaciones de tienda requieren el contexto de una tienda!',
        'tags_require_storefront'      => '¡Las etiquetas requieren el contexto de una tienda!',
    ],

    'products' => [
        'not_found'                  => 'Producto no encontrado.',
        'resource_not_found'         => 'Producto no encontrado.',
        'bookable_service_not_found' => 'Servicio reservable no encontrado.',
    ],

    'food_trucks' => [
        'not_found' => 'Food truck no encontrado.',
    ],

    'promotions' => [
        'not_found' => 'Promoción no encontrada.',
    ],
];
