<?php

return [
    // Cart and marketplace cart checks
    'cart_empty'                              => 'Сагс хоосон байна.',
    'cart_expired'                            => 'Сагсны хугацаа дууссан',
    'cart_item_store_missing'                 => 'Сагсан дахь бүтээгдэхүүн бүр дэлгүүрээ заасан байх ёстой.',
    'cart_store_outside_marketplace'          => 'Сагсанд энэ маркетплейст хамаарахгүй дэлгүүр байна.',
    'cart_store_offline'                      => 'Сагсан дахь нэг дэлгүүр одоогоор офлайн байна.',
    'marketplace_single_store_only'           => 'Энэ маркетплейст нэг сагсанд зөвхөн нэг дэлгүүрийн бүтээгдэхүүн байх боломжтой.',
    'cart_product_unavailable'                => 'Сагсан дахь нэг бүтээгдэхүүн дэлгүүрт нь боломжгүй болсон байна.',
    'cart_store_location_invalid'             => 'Сагсан дахь дэлгүүрийн байршил хүчингүй болсон байна.',
    'cart_mixed_currencies'                   => 'Өөр валюттай бүтээгдэхүүнийг нэг сагсанд нэгтгэх боломжгүй.',
    'cart_items_unavailable'                  => 'Таны сагсан дахь зарим бүтээгдэхүүн боломжгүй болсон байна.',
    'invalid_cart_id'                         => 'Сагсны ID буруу байна',
    'invalid_cart_product'                    => 'Сагсанд нэмэх бүтээгдэхүүн буруу байна!',
    'invalid_cart_item'                       => 'Сагсны бүтээгдэхүүн буруу байна!',
    'product_unavailable_in_catalog'          => 'Энэ бүтээгдэхүүн сонгосон каталогт боломжгүй байна.',
    'store_location_unavailable_for_product'  => 'Сонгосон дэлгүүрийн байршилд энэ бүтээгдэхүүн боломжгүй байна.',
    'booking_slot_taken'                      => ':product-д сонгосон цаг дөнгөж сая захиалагдсан байна. Өөр цаг сонгоно уу.',

    // Promotions
    'promotion_code_required'                 => 'Урамшууллын код оруулна уу.',
    'promotion_code_rejected'                 => '":code" урамшууллын кодыг ашиглах боломжгүй (:reason).',

    // Customer
    'invalid_customer_id'                     => 'Хэрэглэгчийн ID буруу байна',
    'customer_session_mismatch'               => 'Хэрэглэгч нэвтэрсэн бүртгэлтэй таарахгүй байна.',

    // Delivery quotes
    'delivery_origin_missing'                 => 'Хүргэлтийн эхлэх цэг алга!',
    'delivery_destination_missing'            => 'Хүргэлтийн хаяг алга!',
    'integrated_vendor_not_found'             => 'Холбогдсон хүргэлтийн үйлчилгээ олдсонгүй!',
    'service_rates_unavailable'               => 'Хүргэлтийн үнэ тариф алга!',
    'marketplace_store_locations_unavailable' => 'Энэ маркетплейст нэг буюу хэд хэдэн дэлгүүрийн байршил боломжгүй байна.',

    // Checkout session
    'checkout_initialize_failed'              => 'Төлбөрийг эхлүүлж чадсангүй!',
    'checkout_session_not_found'              => 'Төлбөрийн мэдээлэл олдсонгүй.',
    'checkout_not_found'                      => 'Төлбөр олдсонгүй',
    'checkout_token_missing'                  => 'Төлбөрийн токен алга.',
    'checkout_status_failed'                  => 'Төлбөрийн төлөвийг авч чадсангүй',
    'status_parameters_missing'               => 'Шаардлагатай параметр дутуу байна: checkout, token',
    'notes_must_be_text'                      => 'Тэмдэглэл текст байх ёстой.',

    // Payment gateways
    'gateway_not_configured'                  => 'Төлбөрийн хэрэгсэл тохируулаагүй байна!',
    'gateway_misconfigured'                   => 'Төлбөрийн хэрэгслийн тохиргоо буруу байна!',
    'payment_not_paid'                        => 'Төлбөр төлөгдөөгүй байна!',
    'qpay_payment_incomplete'                 => 'QPay төлбөр хараахан төлөгдөөгүй байна.',
    'stripe_not_setup'                        => 'Stripe тохируулаагүй байна.',
    'stripe_gateway_not_configured'           => 'Stripe төлбөрийн хэрэгсэл тохируулаагүй байна!',
    'stripe_authentication_failed'            => 'Stripe-д нэвтэрч чадсангүй. Тохируулсан нууц түлхүүрийг шалгана уу.',
    'stripe_error'                            => 'Stripe-ийн алдаа: :message',
    'payment_intent_retrieve_failed'          => 'PaymentIntent-ийг авч чадсангүй: :message',
    'payment_intent_update_failed'            => 'PaymentIntent-ийг шинэчилж чадсангүй: :message',
    'payment_intent_not_updatable'            => 'Энэ үе шатанд PaymentIntent-ийг шинэчлэх боломжгүй.',
    'payment_intent_other_checkout'           => 'PaymentIntent өөр төлбөрт хамаарч байна.',
    'ephemeral_key_failed'                    => 'Түр түлхүүр үүсгэж чадсангүй: :message',
    'stripe_payment_intent_not_linked'        => 'Stripe PaymentIntent энэ төлбөртэй холбогдоогүй байна.',
    'stripe_payment_verify_failed'            => 'Stripe төлбөрийг баталгаажуулж чадсангүй.',
    'stripe_payment_incomplete'               => 'Stripe төлбөр хараахан төлөгдөөгүй байна.',
    'stripe_payment_mismatch'                 => 'Stripe төлбөр энэ захиалгын төлбөртэй таарахгүй байна.',

    // Order capture
    'capture_storefront_missing'              => 'Захиалга үүсгэх хүсэлтэд дэлгүүр заагаагүй байна!',
    'capture_network_missing'                 => 'Захиалга үүсгэх хүсэлтэд маркетплейс заагаагүй байна!',
    'capture_in_progress'                     => 'Захиалгыг аль хэдийн үүсгэж байна.',
    'order_store_not_found'                   => 'Энэ захиалгын нэг дэлгүүр олдсонгүй тул захиалга үүсээгүй. Таны төлбөр ашиглагдаагүй.',
];
