<?php

return [
    'order_accepted' => [
        'subject' => ':store ha aceptado tu pedido.',
        'body'    => 'Se ha aceptado tu pedido.',
    ],
    'order_activity' => [
        'body' => 'Tu pedido de :store se ha actualizado: :activity.',
    ],
    'order_balance_paid' => [
        'subject_order'      => 'Pago recibido por tu pedido de :store',
        'subject_booking'    => 'Pago recibido por tu reserva en :store',
        'received'           => 'Hemos recibido tu pago de :amount.',
        'left_to_pay'        => 'Quedan :amount por pagar.',
        'fully_paid_order'   => 'Tu pedido está pagado por completo.',
        'fully_paid_booking' => 'Tu reserva está pagada por completo.',
    ],
    'order_canceled' => [
        'subject'      => 'Se ha cancelado tu pedido de :store',
        'body'         => 'Se ha cancelado tu pedido de :store.',
        'body_reason'  => 'Se ha cancelado tu pedido de :store: :reason.',
        'store_credit' => 'Te hemos devuelto el pago como saldo de tienda; usa el código :code en tu próximo pedido.',
        'refunded'     => 'Te hemos reembolsado el pago; deberías recibirlo en unos días.',
        'will_refund'  => 'Si se te ha cobrado, te lo reembolsaremos.',
        'no_action'    => 'No tienes que hacer nada más.',
    ],
    'order_chat_message' => [
        'title'         => ':name te ha enviado un mensaje',
        'default_name'  => 'Tu repartidor',
        'photo'         => 'Ha enviado una foto',
    ],
    'order_completed' => [
        'subject' => 'Se ha entregado tu pedido de :store',
        'body'    => 'Se ha entregado tu pedido de :store. ¡Que lo disfrutes!',
    ],
    'order_driver_assigned' => [
        'subject'         => 'Tu repartidor es :driver',
        'body'            => 'Se ha asignado un repartidor a tu pedido de :store y ya va de camino a recogerlo',
        'subject_booking' => 'Tu profesional es :driver',
        'body_booking'    => ':driver será el profesional de tu reserva en :store.',
    ],
    'order_enroute' => [
        'subject' => '¡Tu pedido de :store está en camino!',
        'body'    => 'Se ha recogido tu pedido de :store; te avisaremos cuando esté cerca',
    ],
    'order_item_unavailable' => [
        'default_item' => 'Un artículo',
        'subject'      => ':item no está disponible',
        'body'         => ':item de tu pedido no está disponible. :store lo sustituirá o lo quitará y ajustará el total si cambia.',
        'body_booking' => ':item de tu reserva no está disponible. :store lo sustituirá o lo quitará y ajustará el total si cambia.',
    ],
    'order_nearby' => [
        'subject' => '¡Tu pedido está cerca!',
        'body'    => 'Tu pedido de :store llegará en :time',
    ],
    'order_preparing' => [
        'subject' => '¡Ya se está preparando tu pedido de :store!',
        'body'    => 'Han empezado con tu pedido.',
    ],
    'order_ready' => [
        'subject' => '¡Tu pedido de :store está listo para recoger!',
        'body'    => 'Ya puedes ir a recoger tu pedido.',
    ],
    'order_updated' => [
        'subject'             => 'Se ha actualizado tu pedido de :store',
        'subject_booking'     => 'Se ha actualizado tu reserva en :store',
        'subject_moved'       => 'Se ha cambiado la hora de tu reserva en :store',
        'body'                => 'Se ha actualizado tu pedido de :store.',
        'booking_time'        => 'Tu reserva queda para el :when.',
        'booking_time_named'  => ':service queda para el :when.',
        'booking_time_range'  => ':start a :end',
        'refunded'            => 'Se te ha reembolsado la diferencia.',
        'store_credit'        => 'La diferencia se ha añadido como saldo de tienda: usa el código :code en tu próximo pedido.',
        'balance_due'         => 'Hay un importe pendiente de pago.',
        'balance_due_link'    => 'Hay un importe pendiente de pago: :link',
    ],
];
