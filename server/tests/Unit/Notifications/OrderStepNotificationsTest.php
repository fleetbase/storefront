<?php

require_once __DIR__ . '/../../Support/OrderOpsCoverageHelpers.php';

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Storefront\Notifications\StorefrontOrderActivity;
use Fleetbase\Storefront\Notifications\StorefrontOrderCanceled;
use Fleetbase\Storefront\Notifications\StorefrontOrderDriverAssigned;
use Fleetbase\Storefront\Notifications\StorefrontOrderUpdated;

function orderStepNotificationOrder(array $meta = []): Order
{
    $order = new Order();
    $order->forceFill([
        'uuid'      => 'order_step_uuid',
        'public_id' => 'order_step',
        'meta'      => ['storefront_id' => 'store_bloom', 'currency' => 'SGD', 'gateway' => 'stripe', 'total' => 3000, ...$meta],
    ]);

    return $order;
}

beforeEach(function () {
    orderOpsCoverageSchema();
});

test('an order step without its own notification is told with its label, or its details', function () {
    $plain    = new StorefrontOrderActivity(orderStepNotificationOrder(), 'Driver picked up');
    $detailed = new StorefrontOrderActivity(orderStepNotificationOrder(), 'Driver en-route to store', 'Ravi is on his way to Bloom.');

    expect($plain->status)->toBe('order_activity')
        ->and($plain->subject)->toBe('Driver picked up')
        ->and($plain->body)->toBe('Your order from Bloom has been updated: Driver picked up.')
        ->and($plain->storefront->public_id)->toBe('store_bloom')
        ->and($detailed->subject)->toBe('Driver en-route to store')
        ->and($detailed->body)->toBe('Ravi is on his way to Bloom.');
});

test('a booking that moved without a known time is told it was updated', function () {
    $notification = new StorefrontOrderUpdated(orderStepNotificationOrder(['is_booking' => true]), '', ['schedule']);

    expect($notification->subject)->toBe('Your booking with Bloom was updated')
        ->and($notification->body)->toBe('Your order from Bloom was updated.');
});

test('an updated order says what happened to the difference', function () {
    $refunded = new StorefrontOrderUpdated(orderStepNotificationOrder([
        'payment_adjustments' => [['type' => 'refund', 'amount' => 500, 'gateway' => 'stripe']],
    ]), 'Removed Mango Lassi.', ['items']);
    $credited = new StorefrontOrderUpdated(orderStepNotificationOrder([
        'payment_adjustments' => [
            ['type' => 'refund', 'amount' => 200],
            ['type' => 'store_credit', 'amount' => 300, 'reference' => 'CREDIT-7Q2'],
        ],
    ]), 'Removed Mango Lassi', ['items']);
    $creditWithoutCode = new StorefrontOrderUpdated(orderStepNotificationOrder([
        'payment_adjustments' => [['type' => 'store_credit', 'amount' => 300]],
    ]), 'Removed Mango Lassi', ['items']);
    $balanceLink = new StorefrontOrderUpdated(orderStepNotificationOrder(['balance_due' => 800, 'balance_payment_link' => 'https://pay.test/b/1']), 'Added Chai', ['items']);
    $balance     = new StorefrontOrderUpdated(orderStepNotificationOrder(['balance_due' => 800]), 'Added Chai', ['items']);

    expect($refunded->body)->toBe('Removed Mango Lassi. The difference has been refunded.')
        ->and($credited->body)->toBe('Removed Mango Lassi. The difference was added as store credit: use code CREDIT-7Q2 on your next order.')
        ->and($creditWithoutCode->body)->toBe('Removed Mango Lassi. The difference was added as store credit: use code  on your next order.')
        ->and($balanceLink->body)->toBe('Added Chai. There is a balance to pay: https://pay.test/b/1')
        ->and($balance->body)->toBe('Added Chai. There is a balance to pay.');
});

test('a canceled order says why and what happens to the money', function () {
    $credited = new StorefrontOrderCanceled(orderStepNotificationOrder([
        'cancel_reason'       => 'Out of stock',
        'payment_adjustments' => [['type' => 'store_credit', 'amount' => 3000, 'reference' => 'CREDIT-AB12']],
    ]));
    $refunded = new StorefrontOrderCanceled(orderStepNotificationOrder([
        'payment_adjustments' => [['type' => 'refund', 'amount' => 1000]],
    ]));
    $charged = new StorefrontOrderCanceled(orderStepNotificationOrder(['cancel_reason' => 'Closed early.']));
    $unpaid  = new StorefrontOrderCanceled(orderStepNotificationOrder(['payment_status' => 'unpaid']));

    expect($credited->subject)->toBe('Your order from Bloom was canceled')
        ->and($credited->body)->toBe('Your order from Bloom has been canceled: Out of stock. Your payment was returned as store credit; use code CREDIT-AB12 on your next order.')
        ->and($refunded->body)->toBe('Your order from Bloom has been canceled. Your payment has been refunded and should reach you within a few days.')
        ->and($charged->body)->toBe('Your order from Bloom has been canceled: Closed early. If you were charged, you will be refunded.')
        ->and($unpaid->body)->toBe('Your order from Bloom has been canceled. ');
});

test('a booking customer is told who their provider is', function () {
    // A driver's name is their user's.
    $user = new Fleetbase\Models\User();
    $user->forceFill(['name' => 'Taylor Clean']);
    $driver = new Driver();
    $driver->setRelation('user', $user);
    $booking = orderStepNotificationOrder(['is_booking' => true]);
    $booking->setRelation('driverAssigned', $driver);
    $delivery = orderStepNotificationOrder();
    $delivery->setRelation('driverAssigned', $driver);

    $provider = new StorefrontOrderDriverAssigned($booking);
    $courier  = new StorefrontOrderDriverAssigned($delivery);

    expect($provider->driver)->toBe($driver)
        ->and($provider->status)->toBe('order_driver_assigned')
        ->and($provider->subject)->toBe('Your provider is Taylor Clean')
        ->and($provider->body)->toBe('Taylor Clean will be your provider for your booking with Bloom.')
        ->and($courier->subject)->toBe('Your driver is Taylor Clean')
        ->and($courier->body)->toBe('A driver has been assigned to your order from Bloom, they are currently en-route for pickup');
});
