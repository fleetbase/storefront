<?php

require_once __DIR__ . '/../../Support/OrderOpsCoverageHelpers.php';
require_once __DIR__ . '/../../Support/OrderActivityDispatchRecorder.php';

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Storefront\Notifications\StorefrontOrderActivity;
use Fleetbase\Storefront\Notifications\StorefrontOrderCanceled;
use Fleetbase\Storefront\Observers\OrderActivityDispatchRecorder;
use Fleetbase\Storefront\Observers\OrderActivityObserver;
use Fleetbase\Storefront\Support\Inventory;

/**
 * The order as the observer sees it after a save that moved it to the given status (the
 * stored row moves too, as the queued work reads it back).
 */
function orderActivityMoved(string $publicId, string $status): Order
{
    $order         = Order::where('public_id', $publicId)->first();
    $order->status = $status;
    $order->syncChanges();
    orderOpsCoverageDb()->table('orders')->where('public_id', $publicId)->update(['status' => $status]);

    return $order;
}

function orderActivityStockSchema(): void
{
    $schema = orderOpsCoverageDb()->getSchemaBuilder();
    foreach (['product_inventories', 'inventory_movements', 'inventory_reservations'] as $table) {
        $schema->dropIfExists($table);
    }
    $schema->create('product_inventories', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('product_uuid');
        $table->string('location_uuid');
        $table->string('location_type')->nullable();
        $table->integer('quantity')->default(0);
        $table->integer('reserved')->default(0);
        $table->integer('low_stock_threshold')->nullable();
        $table->boolean('allow_backorder')->default(false);
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('inventory_movements', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('company_uuid')->nullable();
        $table->string('inventory_uuid');
        $table->string('type');
        $table->integer('quantity');
        $table->integer('quantity_after')->nullable();
        $table->string('reference_uuid')->nullable();
        $table->string('reference_type')->nullable();
        $table->string('actor_uuid')->nullable();
        $table->string('note')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('inventory_reservations', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('inventory_uuid');
        $table->integer('quantity');
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
}

function orderActivityExceptionRecorder(): object
{
    $handler = new class implements Illuminate\Contracts\Debug\ExceptionHandler {
        public array $reported = [];

        public function report(Throwable $e)
        {
            $this->reported[] = $e;
        }

        public function shouldReport(Throwable $e)
        {
            return true;
        }

        public function render($request, Throwable $e)
        {
            throw $e;
        }

        public function renderForConsole($output, Throwable $e)
        {
        }
    };
    app()->instance(Illuminate\Contracts\Debug\ExceptionHandler::class, $handler);

    return $handler;
}

beforeEach(function () {
    orderOpsCoverageSchema();
    $this->notifications                 = orderOpsCoverageBoot();
    OrderActivityDispatchRecorder::$jobs = [];
    Illuminate\Support\Facades\Cache::flush();
    Inventory::flushEnabled();
    orderOpsCoverageOrder('order_delivery', ['status' => 'started', 'tracking_number_uuid' => 'tracking_delivery_uuid']);
    orderOpsCoverageOrder('order_booking', ['status' => 'created'], ['storefront_id' => 'store_bloom', 'is_booking' => true]);
    orderOpsCoverageOrder('order_fleet', ['status' => 'created'], ['source' => 'console']);
    orderOpsCoverageOrder('order_no_customer', ['status' => 'created', 'customer_uuid' => 'missing_contact_uuid']);
    $this->observer = new OrderActivityObserver();
});

afterEach(function () {
    OrderActivityDispatchRecorder::$jobs = [];
    Inventory::flushEnabled();
    app()->offsetUnset(Illuminate\Contracts\Debug\ExceptionHandler::class);
    orderOpsCoverageShutdown();
});

test('only a storefront order whose status changed is followed', function () {
    $unchanged = Order::where('public_id', 'order_delivery')->first();
    $unchanged->syncChanges();

    $this->observer->updated($unchanged);
    $this->observer->updated(orderActivityMoved('order_fleet', 'driver_enroute'));

    expect(OrderActivityDispatchRecorder::$jobs)->toBe([]);
});

test('steps with their own notification, and an empty status, are left alone', function () {
    foreach (['dispatched', 'started', 'completed', 'pickup_ready', 'accepted', ''] as $status) {
        $this->observer->updated(orderActivityMoved('order_delivery', $status));
    }
    // Bookings skip only the request itself.
    $this->observer->updated(orderActivityMoved('order_booking', 'pending'));

    expect(OrderActivityDispatchRecorder::$jobs)->toBe([]);
});

test('a step without a notification of its own is told once, after the change is committed', function () {
    orderOpsCoverageDb()->table('tracking_statuses')->insert([
        ['tracking_number_uuid' => 'tracking_delivery_uuid', 'code' => 'started', 'status' => 'Order started', 'created_at' => '2026-10-14 10:00:00'],
        ['tracking_number_uuid' => 'tracking_delivery_uuid', 'code' => 'driver_enroute', 'status' => 'Driver en-route to store', 'created_at' => '2026-10-14 10:05:00'],
    ]);

    $this->observer->updated(orderActivityMoved('order_delivery', 'driver_enroute'));
    expect(OrderActivityDispatchRecorder::$jobs)->toHaveCount(1);
    OrderActivityDispatchRecorder::runAll();
    // The order is saved again with the same step: it is not told twice.
    OrderActivityObserver::notify('order_delivery_uuid', 'driver_enroute');

    $told = $this->notifications->sentTo(orderOpsCoverageCustomerUuid());

    expect($told)->toHaveCount(1)
        ->and($told[0])->toBeInstanceOf(StorefrontOrderActivity::class)
        ->and($told[0]->subject)->toBe('Driver en-route to store')
        ->and($told[0]->body)->toBe('Your order from Bloom has been updated: Driver en-route to store.');
});

test('a booking is told each step in its own terms, its cancellation included', function () {
    $this->observer->updated(orderActivityMoved('order_booking', 'started'));
    OrderActivityDispatchRecorder::runAll();
    $this->observer->updated(orderActivityMoved('order_booking', 'canceled'));

    // A cancellation also puts stock back.
    expect(OrderActivityDispatchRecorder::$jobs)->toHaveCount(2);
    OrderActivityDispatchRecorder::runAll();

    $told = $this->notifications->sentTo(orderOpsCoverageCustomerUuid());

    expect(array_map(fn ($notification) => $notification::class, $told))->toBe([StorefrontOrderActivity::class, StorefrontOrderActivity::class])
        ->and($told[0]->subject)->toBe('Started')
        ->and($told[1]->subject)->toBe('Canceled');
});

test('a canceled order puts its stock back and tells the customer why and what happens to the money', function () {
    orderActivityStockSchema();
    orderOpsCoverageDb()->table('product_inventories')->insert(['uuid' => 'inventory_uuid', 'company_uuid' => 'company_uuid', 'product_uuid' => 'product_uuid', 'location_uuid' => 'location_uuid', 'quantity' => 3]);
    orderOpsCoverageDb()->table('inventory_movements')->insert([
        'uuid'           => 'sale_uuid',
        'company_uuid'   => 'company_uuid',
        'inventory_uuid' => 'inventory_uuid',
        'type'           => Fleetbase\Storefront\Models\InventoryMovement::TYPE_SALE,
        'quantity'       => -2,
        'reference_uuid' => 'order_delivery_uuid',
    ]);
    orderOpsCoverageDb()->table('orders')->where('public_id', 'order_delivery')->update(['meta' => json_encode(['storefront_id' => 'store_bloom', 'gateway' => 'stripe', 'cancel_reason' => 'Out of stock'])]);

    $this->observer->updated(orderActivityMoved('order_delivery', 'canceled'));
    OrderActivityDispatchRecorder::runAll();

    $told = $this->notifications->sentTo(orderOpsCoverageCustomerUuid());

    expect(orderOpsCoverageDb()->table('product_inventories')->where('uuid', 'inventory_uuid')->value('quantity'))->toBe(5)
        ->and(orderOpsCoverageDb()->table('inventory_movements')->where('type', Fleetbase\Storefront\Models\InventoryMovement::TYPE_CANCELLATION)->value('quantity'))->toBe(2)
        ->and($told)->toHaveCount(1)
        ->and($told[0])->toBeInstanceOf(StorefrontOrderCanceled::class)
        ->and($told[0]->body)->toStartWith('Your order from Bloom has been canceled: Out of stock.');
});

test('stock is not put back for an order that is gone by the time the work runs', function () {
    $this->observer->updated(orderActivityMoved('order_delivery', 'order_canceled'));
    orderOpsCoverageDb()->table('orders')->where('public_id', 'order_delivery')->delete();

    expect(OrderActivityDispatchRecorder::$jobs)->toHaveCount(2);
    OrderActivityDispatchRecorder::runAll();

    expect($this->notifications->sent)->toBe([]);
});

test('nobody is told about an order that is missing, has no customer, or moved on', function () {
    OrderActivityObserver::notify('missing_order_uuid', 'driver_enroute');
    orderActivityMoved('order_no_customer', 'driver_enroute');
    OrderActivityObserver::notify('order_no_customer_uuid', 'driver_enroute');
    // The order moved past the step before the work ran.
    OrderActivityObserver::notify('order_delivery_uuid', 'driver_enroute');

    expect($this->notifications->sent)->toBe([]);
});

test('a failure to tell the customer is reported, not thrown', function () {
    $handler = orderActivityExceptionRecorder();
    orderOpsCoverageDb()->getSchemaBuilder()->drop('contacts');

    OrderActivityObserver::notify('order_delivery_uuid', 'started');

    expect($handler->reported)->toHaveCount(1)
        ->and($handler->reported[0])->toBeInstanceOf(Illuminate\Database\QueryException::class)
        ->and($this->notifications->sent)->toBe([]);
});
