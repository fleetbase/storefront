<?php

return [
    'customer_not_authenticated' => 'Хэрэглэгч нэвтрээгүй байна.',

    'session' => [
        'key_missing' => 'Уучлаарай! Энэ хүсэлтэд Storefront түлхүүр олдсонгүй',
        'key_invalid' => 'Уучлаарай! Өгсөн Storefront түлхүүр хүчингүй байна',
    ],

    'validation' => [
        'cart_not_found'     => 'Сагсны сесс олдсонгүй.',
        'customer_not_found' => 'Хэрэглэгч олдсонгүй.',
        'gateway_not_found'  => 'Ийм кодтой төлбөрийн хэрэгсэл байхгүй байна.',
        'invalid_location'   => ':attribute буруу байна.',
    ],

    'orders' => [
        'no_order_found'                => 'Захиалга олдсонгүй.',
        'not_found'                     => 'Захиалга олдсонгүй.',
        'not_authorized_pickup'         => 'Та энэ захиалгыг очиж авснаар дуусгах эрхгүй.',
        'not_authorized_view'           => 'Та энэ захиалгыг харах эрхгүй.',
        'not_authorized_receipt'        => 'Та энэ захиалгын баримтыг авах эрхгүй.',
        'receipt_unavailable'           => 'Энэ төлбөрийн хэрэгслээр баримт гаргах боломжгүй.',
        'company_registration_required' => 'Байгууллагын регистрийн дугаар шаардлагатай.',
        'not_paid_with_qpay'            => 'Энэ захиалгыг QPay-ээр төлөөгүй байна.',
        'checkout_not_found'            => 'Энэ захиалгын төлбөрийн мэдээлэл олдсонгүй.',
        'qpay_not_configured'           => 'QPay тохируулагдаагүй байна.',
        'ebarimt_receipt_failed'        => 'Ebarimt баримт үүсгэж чадсангүй',
    ],

    'notifications' => [
        'not_authorized_view'               => 'Та хэрэглэгчийн мэдэгдлийг харах эрхгүй',
        'not_authorized_update'             => 'Та хэрэглэгчийн мэдэгдлийг шинэчлэх эрхгүй',
        'not_authorized_delete'             => 'Та хэрэглэгчийн мэдэгдлийг устгах эрхгүй',
        'not_found'                         => 'Мэдэгдэл олдсонгүй.',
        'not_authorized_view_preferences'   => 'Та мэдэгдлийн тохиргоог харах эрхгүй',
        'not_authorized_update_preferences' => 'Та мэдэгдлийн тохиргоог шинэчлэх эрхгүй',
        'preference_must_be_boolean'        => ':key тохиргоо true эсвэл false байх ёстой.',
    ],

    'reviews' => [
        'cannot_find_for_store'  => 'Дэлгүүрийн үнэлгээг олж чадсангүй',
        'cannot_count_for_store' => 'Дэлгүүрийн үнэлгээг тоолж чадсангүй',
        'not_found'              => 'Үнэлгээ олдсонгүй.',
        'subject_required'       => 'Үнэлэх зүйлийг заана уу.',
        'invalid_subject'        => 'Үнэлэх зүйл буруу байна',
        'disabled'               => 'Үнэлгээ өгөх боломжийг унтраасан байна.',
        'not_authorized_create'  => 'Та үнэлгээ өгөх эрхгүй',
        'not_authorized_delete'  => 'Та энэ үнэлгээг устгах эрхгүй',
        'sign_in_required'       => 'Үнэлгээ өгөхийн тулд нэвтэрнэ үү.',
        'unsupported_subject'    => 'Зөвхөн дэлгүүр болон бүтээгдэхүүнд үнэлгээ өгөх боломжтой.',
        'no_completed_order'     => 'Үүнийг агуулсан захиалга дууссаны дараа үнэлгээ өгөх боломжтой.',
        'already_reviewed'       => 'Та дууссан захиалгууддаа үүнд аль хэдийн үнэлгээ өгсөн байна.',
    ],

    'chat' => [
        'address_locked'      => 'Энэ захиалгын хаягийг өөрчлөх боломжгүй болсон.',
        'place_not_found'     => 'Хаяг олдсонгүй.',
        'message_not_found'   => 'Зурвас олдсонгүй.',
        'closed'              => 'Захиалга дууссан тул энэ чат хаагдсан.',
        'not_participant'     => 'Та энэ чатад оролцогч биш байна.',
        'store_unavailable'   => 'Энэ захиалгад дэлгүүртэй харилцах чат байхгүй.',
        'driver_not_assigned' => 'Энэ захиалгад жолооч томилогдсоны дараа түүнд зурвас бичих боломжтой.',
    ],

    'stores' => [
        'not_found'                      => 'Дэлгүүр олдсонгүй!',
        'lookup_id_required'             => 'Хайх ID өгөөгүй байна.',
        'store_or_network_not_found'     => 'Өгсөн ID-д тохирох дэлгүүр эсвэл сүлжээ олдсонгүй.',
        'networks_cannot_have_locations' => 'Сүлжээ салбар байршилтай байж болохгүй!',
        'location_not_found'             => 'Дэлгүүрийн байршил олдсонгүй!',
    ],

    'networks' => [
        'stores_cannot_have_stores'    => 'Дэлгүүр дотроо дэлгүүртэй байж болохгүй!',
        'locations_require_storefront' => 'Дэлгүүрийн байршлыг харахын тулд дэлгүүр сонгосон байх шаардлагатай!',
        'tags_require_storefront'      => 'Шошгыг харахын тулд дэлгүүр сонгосон байх шаардлагатай!',
    ],

    'products' => [
        'not_found'                  => 'Бүтээгдэхүүн олдсонгүй.',
        'resource_not_found'         => 'Бүтээгдэхүүн олдсонгүй.',
        'bookable_service_not_found' => 'Захиалах боломжтой үйлчилгээ олдсонгүй.',
    ],

    'food_trucks' => [
        'not_found' => 'Явуулын дэлгүүр олдсонгүй.',
    ],

    'promotions' => [
        'not_found' => 'Урамшуулал олдсонгүй.',
    ],
];
