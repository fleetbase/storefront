<?php

require_once __DIR__ . '/../../Support/OrderOpsCoverageHelpers.php';

use Fleetbase\FleetOps\Events\OrderCompleted;
use Fleetbase\FleetOps\Events\OrderDispatched;
use Fleetbase\FleetOps\Events\OrderStarted;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Storefront\Listeners\HandleOrderCompleted;
use Fleetbase\Storefront\Listeners\HandleOrderDispatched;
use Fleetbase\Storefront\Listeners\HandleOrderStarted;

/**
 * An order whose customer is a spy: what it was told, and whether the listener loaded it.
 */
class BookingStepListenerOrder extends Order
{
    public static array $loaded = [];
    public static array $told   = [];

    public function load($relations)
    {
        static::$loaded[] = $relations;
        $this->setRelation('customer', new class {
            public function notify(object $notification): void
            {
                BookingStepListenerOrder::$told[] = $notification;
            }
        });

        return $this;
    }
}

function bookingStepListenerEvent(string $eventClass, string $orderUuid): object
{
    $event                      = (new ReflectionClass($eventClass))->newInstanceWithoutConstructor();
    $event->modelUuid           = $orderUuid;
    $event->modelClassNamespace = BookingStepListenerOrder::class;

    return $event;
}

beforeEach(function () {
    orderOpsCoverageSchema();
    BookingStepListenerOrder::$loaded = [];
    BookingStepListenerOrder::$told   = [];
    $meta                             = ['storefront_id' => 'store_bloom', 'gateway' => 'stripe', 'is_booking' => true, 'is_pickup' => true];
    orderOpsCoverageDb()->table('orders')->insert([
        ['uuid' => 'booking_step_uuid', 'public_id' => 'order_booking_step', 'company_uuid' => 'company_uuid', 'status' => 'completed', 'meta' => json_encode($meta)],
        ['uuid' => 'pickup_step_uuid', 'public_id' => 'order_pickup_step', 'company_uuid' => 'company_uuid', 'status' => 'completed', 'meta' => json_encode([...$meta, 'is_booking' => false])],
    ]);
});

test('bookings are not told about completion, dispatch or start by the delivery listeners', function () {
    (new HandleOrderCompleted())->handle(bookingStepListenerEvent(OrderCompleted::class, 'booking_step_uuid'));
    (new HandleOrderDispatched())->handle(bookingStepListenerEvent(OrderDispatched::class, 'booking_step_uuid'));
    (new HandleOrderStarted())->handle(bookingStepListenerEvent(OrderStarted::class, 'booking_step_uuid'));

    expect(BookingStepListenerOrder::$told)->toBe([])
        ->and(BookingStepListenerOrder::$loaded)->toBe([])
        // A paid booking needs no settling: its meta is left as it was.
        ->and(json_decode(orderOpsCoverageDb()->table('orders')->where('uuid', 'booking_step_uuid')->value('meta'), true))->toBe(['storefront_id' => 'store_bloom', 'gateway' => 'stripe', 'is_booking' => true, 'is_pickup' => true]);
});

test('the same steps of a pickup order are told to its customer', function () {
    (new HandleOrderCompleted())->handle(bookingStepListenerEvent(OrderCompleted::class, 'pickup_step_uuid'));
    (new HandleOrderDispatched())->handle(bookingStepListenerEvent(OrderDispatched::class, 'pickup_step_uuid'));
    (new HandleOrderStarted())->handle(bookingStepListenerEvent(OrderStarted::class, 'pickup_step_uuid'));

    expect(array_map(fn ($notification) => $notification::class, BookingStepListenerOrder::$told))->toBe([
        Fleetbase\Storefront\Notifications\StorefrontOrderCompleted::class,
        Fleetbase\Storefront\Notifications\StorefrontOrderReadyForPickup::class,
        Fleetbase\Storefront\Notifications\StorefrontOrderEnroute::class,
    ]);
});
