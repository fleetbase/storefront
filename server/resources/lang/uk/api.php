<?php

return [
    'customer_not_authenticated' => 'Покупець не автентифікований.',

    'session' => [
        'key_missing' => 'Ой! У цьому запиті не знайдено ключа Storefront',
        'key_invalid' => 'Ой! Наданий ключ Storefront недійсний',
    ],

    'validation' => [
        'cart_not_found'     => 'Сесію кошика не знайдено.',
        'customer_not_found' => 'Покупця не знайдено.',
        'gateway_not_found'  => 'Платіжного шлюзу з таким кодом не існує.',
        'invalid_location'   => 'Недійсне значення :attribute.',
    ],

    'orders' => [
        'no_order_found'                => 'Замовлення не знайдено.',
        'not_found'                     => 'Замовлення не знайдено.',
        'not_authorized_pickup'         => 'Ви не можете завершити це замовлення як самовивіз.',
        'not_authorized_view'           => 'Ви не маєте доступу до цього замовлення.',
        'not_authorized_receipt'        => 'Ви не можете отримати чек для цього замовлення.',
        'receipt_unavailable'           => 'Для цього способу оплати чек недоступний.',
        'company_registration_required' => 'Потрібен реєстраційний номер компанії.',
        'not_paid_with_qpay'            => 'Це замовлення не було оплачено через QPay.',
        'checkout_not_found'            => 'Для цього замовлення не знайдено оформлення.',
        'qpay_not_configured'           => 'QPay не налаштовано.',
        'ebarimt_receipt_failed'        => 'Не вдалося створити чек ebarimt',
    ],

    'notifications' => [
        'not_authorized_view'               => 'Ви не маєте доступу до сповіщень покупця',
        'not_authorized_update'             => 'Ви не можете оновлювати сповіщення покупця',
        'not_authorized_delete'             => 'Ви не можете видаляти сповіщення покупця',
        'not_found'                         => 'Сповіщення не знайдено.',
        'not_authorized_view_preferences'   => 'Ви не маєте доступу до налаштувань сповіщень',
        'not_authorized_update_preferences' => 'Ви не можете оновлювати налаштування сповіщень',
        'preference_must_be_boolean'        => 'Налаштування :key має бути true або false.',
    ],

    'reviews' => [
        'cannot_find_for_store'  => 'Не вдалося знайти відгуки для магазину',
        'cannot_count_for_store' => 'Не вдалося порахувати відгуки для магазину',
        'not_found'              => 'Відгук не знайдено.',
        'subject_required'       => 'Вкажіть, що ви оцінюєте.',
        'invalid_subject'        => 'Недійсний об’єкт відгуку',
        'disabled'               => 'Відгуки вимкнено.',
        'not_authorized_create'  => 'Ви не можете залишати відгуки',
        'not_authorized_delete'  => 'Ви не можете видалити цей відгук',
        'sign_in_required'       => 'Увійдіть, щоб залишити відгук.',
        'unsupported_subject'    => 'Залишати відгуки можна лише про магазини та товари.',
        'no_completed_order'     => 'Ви зможете залишити відгук після завершення замовлення з цим.',
        'already_reviewed'       => 'Ви вже залишили відгук про це для своїх завершених замовлень.',
    ],

    'chat' => [
        'address_locked'      => 'Адресу цього замовлення вже не можна змінити.',
        'place_not_found'     => 'Адресу не знайдено.',
        'message_not_found'   => 'Повідомлення не знайдено.',
        'closed'              => 'Цей чат закрито, бо замовлення завершено.',
        'not_participant'     => 'Ви не є учасником цього чату.',
        'store_unavailable'   => 'У цього замовлення немає чату з магазином.',
        'driver_not_assigned' => 'Ви зможете написати водієві, щойно його призначать на це замовлення.',
    ],

    'stores' => [
        'not_found'                      => 'Не вдалося знайти магазин!',
        'lookup_id_required'             => 'Не вказано ID для пошуку.',
        'store_or_network_not_found'     => 'Не вдалося знайти магазин або мережу за вказаним ID.',
        'networks_cannot_have_locations' => 'Мережі не можуть мати локацій!',
        'location_not_found'             => 'Локацію магазину не знайдено!',
    ],

    'networks' => [
        'stores_cannot_have_stores'    => 'Магазини не можуть містити інші магазини!',
        'locations_require_storefront' => 'Для локацій магазину потрібен контекст магазину!',
        'tags_require_storefront'      => 'Для тегів потрібен контекст магазину!',
    ],

    'products' => [
        'not_found'                  => 'Товар не знайдено.',
        'resource_not_found'         => 'Товар не знайдено.',
        'bookable_service_not_found' => 'Послугу для бронювання не знайдено.',
    ],

    'food_trucks' => [
        'not_found' => 'Фудтрак не знайдено.',
    ],

    'promotions' => [
        'not_found' => 'Акцію не знайдено.',
    ],
];
