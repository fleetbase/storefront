<?php

return [
    // Cart and marketplace cart checks
    'cart_empty'                              => 'Кошик порожній.',
    'cart_expired'                            => 'Термін дії кошика минув',
    'cart_item_store_missing'                 => 'Для кожного товару в кошику маркетплейсу має бути вказано магазин.',
    'cart_store_outside_marketplace'          => 'У кошику є магазин, що не належить до цього маркетплейсу.',
    'cart_store_offline'                      => 'Один із магазинів у кошику зараз зачинений.',
    'marketplace_single_store_only'           => 'На цьому маркетплейсі в одному кошику можуть бути товари лише одного магазину.',
    'cart_product_unavailable'                => 'Один із товарів у кошику більше недоступний у своєму магазині.',
    'cart_store_location_invalid'             => 'Одне з розташувань магазину в кошику більше не дійсне.',
    'cart_mixed_currencies'                   => 'Товари в різних валютах не можна поєднати в одному кошику.',
    'cart_items_unavailable'                  => 'Деякі товари у вашому кошику більше недоступні.',
    'invalid_cart_id'                         => 'Недійсний ID кошика',
    'invalid_cart_product'                    => 'Недійсний товар для кошика!',
    'invalid_cart_item'                       => 'Недійсна позиція кошика!',
    'product_unavailable_in_catalog'          => 'Цей товар недоступний у вибраному каталозі.',
    'store_location_unavailable_for_product'  => 'Вибране розташування магазину недоступне для цього товару.',
    'booking_slot_taken'                      => 'Час, який ви вибрали для :product, щойно забронювали. Виберіть інший час.',

    // Promotions
    'promotion_code_required'                 => 'Введіть промокод.',
    'promotion_code_rejected'                 => 'Промокод «:code» не можна застосувати (:reason).',

    // Customer
    'invalid_customer_id'                     => 'Недійсний ID клієнта',
    'customer_session_mismatch'               => 'Клієнт не відповідає обліковому запису, в який виконано вхід.',

    // Delivery quotes
    'delivery_origin_missing'                 => 'Не вказано адресу відправлення!',
    'delivery_destination_missing'            => 'Не вказано адресу доставки!',
    'integrated_vendor_not_found'             => 'Підключену службу доставки не знайдено!',
    'service_rates_unavailable'               => 'Немає доступних тарифів доставки!',
    'marketplace_store_locations_unavailable' => 'Одне або кілька розташувань магазинів недоступні на цьому маркетплейсі.',

    // Checkout session
    'checkout_initialize_failed'              => 'Не вдалося розпочати оформлення замовлення!',
    'checkout_session_not_found'              => 'Сеанс оформлення замовлення не знайдено.',
    'checkout_not_found'                      => 'Оформлення замовлення не знайдено',
    'checkout_token_missing'                  => 'Відсутній токен оформлення замовлення.',
    'checkout_status_failed'                  => 'Не вдалося отримати статус оформлення замовлення',
    'status_parameters_missing'               => 'Відсутні обовʼязкові параметри: checkout і token',
    'notes_must_be_text'                      => 'Примітки мають бути текстом.',

    // Payment gateways
    'gateway_not_configured'                  => 'Платіжний шлюз не налаштовано!',
    'gateway_misconfigured'                   => 'Платіжний шлюз налаштовано неправильно!',
    'payment_not_paid'                        => 'Оплату не здійснено!',
    'qpay_payment_incomplete'                 => 'Оплату QPay не завершено.',
    'stripe_not_setup'                        => 'Stripe не налаштовано.',
    'stripe_gateway_not_configured'           => 'Платіжний шлюз Stripe не налаштовано!',
    'stripe_authentication_failed'            => 'Не вдалося автентифікуватися у Stripe. Перевірте налаштований секретний ключ.',
    'stripe_error'                            => 'Помилка Stripe: :message',
    'payment_intent_retrieve_failed'          => 'Не вдалося отримати PaymentIntent: :message',
    'payment_intent_update_failed'            => 'Не вдалося оновити PaymentIntent: :message',
    'payment_intent_not_updatable'            => 'На цьому етапі PaymentIntent не можна оновити.',
    'payment_intent_other_checkout'           => 'PaymentIntent належить до іншого оформлення замовлення.',
    'ephemeral_key_failed'                    => 'Не вдалося створити тимчасовий ключ: :message',
    'stripe_payment_intent_not_linked'        => 'Stripe PaymentIntent не повʼязаний із цим оформленням замовлення.',
    'stripe_payment_verify_failed'            => 'Не вдалося перевірити оплату Stripe.',
    'stripe_payment_incomplete'               => 'Оплату Stripe не завершено.',
    'stripe_payment_mismatch'                 => 'Оплата Stripe не відповідає цьому оформленню замовлення.',

    // Order capture
    'capture_storefront_missing'              => 'У запиті на створення замовлення не вказано магазин!',
    'capture_network_missing'                 => 'У запиті на створення замовлення не вказано маркетплейс!',
    'capture_in_progress'                     => 'Замовлення вже створюється.',
    'order_store_not_found'                   => 'Не вдалося знайти один із магазинів у цьому замовленні, тому його не оформлено. Вашу оплату не використано.',
];
