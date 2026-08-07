<?php

use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\Storefront\Http\Controllers\v1\CheckoutController;
use Fleetbase\Storefront\Models\Cart;

function invokeCheckoutCalculation(string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod(CheckoutController::class, $method))->invoke(null, ...$arguments);
}

function checkoutCart(int $subtotal = 10000): Cart
{
    $cart = new Cart();
    $cart->forceFill([
        'items' => [
            [
                'subtotal' => $subtotal,
                'quantity' => 1,
            ],
        ],
    ]);

    return $cart;
}

test('checkout amount includes percentage and fixed tips plus delivery quote', function () {
    $quote = new ServiceQuote();
    $quote->forceFill(['amount' => 2500]);

    $amount = invokeCheckoutCalculation(
        'calculateCheckoutAmount',
        checkoutCart(),
        $quote,
        [
            'tip'          => '10%',
            'delivery_tip' => 500,
            'is_pickup'    => false,
        ]
    );

    expect($amount)->toBe(14000);
});

test('pickup checkout excludes delivery tips and does not require a service quote', function () {
    $amount = invokeCheckoutCalculation(
        'calculateCheckoutAmount',
        checkoutCart(),
        null,
        (object) [
            'tip'          => 750,
            'delivery_tip' => 500,
            'is_pickup'    => true,
        ]
    );

    expect($amount)->toBe(10750);
});

test('checkout amount supports delivery without optional gratuities', function () {
    $quote = new ServiceQuote();
    $quote->forceFill(['amount' => '2,500']);

    $amount = invokeCheckoutCalculation(
        'calculateCheckoutAmount',
        checkoutCart(),
        $quote,
        []
    );

    expect($amount)->toBe(12500);
});

test('checkout tip calculation handles percentage currency and empty values', function ($tip, $subtotal, $expected) {
    expect(invokeCheckoutCalculation('calculateTipAmount', $tip, $subtotal))->toBe($expected);
})->with([
    'percentage'       => ['12.5%', 20000, 2500.0],
    'fixed integer'    => [700, 20000, 700],
    'formatted amount' => ['1,250', 20000, 1250],
    'false value'      => [false, 20000, 0],
    'null value'       => [null, 20000, 0],
]);
