<?php

use Fleetbase\Storefront\Http\Controllers\v1\CheckoutController;

test('the other stores on a network order become pickup waypoints', function () {
    $waypoints = (new ReflectionMethod(CheckoutController::class, 'pickupWaypoints'))->invoke(null, [
        1 => 'place_rochor',
        2 => ['name' => 'Orchard Grocers', 'location' => [1.3048, 103.8318]],
    ]);

    expect($waypoints)->toBe([
        ['id' => 'place_rochor', 'type' => 'pickup'],
        ['place' => ['name' => 'Orchard Grocers', 'location' => [1.3048, 103.8318]], 'type' => 'pickup'],
    ]);
});

test('a single-store order has no pickup waypoints', function () {
    expect((new ReflectionMethod(CheckoutController::class, 'pickupWaypoints'))->invoke(null, []))->toBe([]);
});
