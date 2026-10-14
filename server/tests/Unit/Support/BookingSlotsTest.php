<?php

use Fleetbase\Storefront\Models\Product;
use Fleetbase\Storefront\Support\BookingSlots;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

function bookingDb()
{
    return Model::getConnectionResolver()->connection('mysql');
}

function createBookingSchema(): void
{
    $schema = bookingDb()->getSchemaBuilder();
    foreach (['stores', 'companies', 'store_locations', 'entities', 'orders'] as $table) {
        $schema->dropIfExists($table);
    }

    $schema->create('stores', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('timezone')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('companies', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('timezone')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('store_locations', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('store_uuid')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('entities', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('payload_uuid')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('orders', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('payload_uuid')->nullable();
        $table->string('status')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
}

function bookableService(array $service = []): Product
{
    bookingDb()->table('stores')->insert(['uuid' => 'store_uuid', 'company_uuid' => 'company_uuid', 'timezone' => 'Asia/Singapore']);

    $product = new Product();
    $product->forceFill(['uuid' => 'product_uuid', 'public_id' => 'product_1', 'store_uuid' => 'store_uuid', 'is_bookable' => true, 'meta' => ['service' => $service]]);
    $product->setRelation('hours', collect([(object) ['day_of_week' => 'monday', 'start' => '09:00:00', 'end' => '12:00:00']]));

    return $product;
}

function bookedAt(string $at, string $payload, ?string $status = 'created', int $quantity = 1): void
{
    bookingDb()->table('entities')->insert(['uuid' => uniqid('entity_'), 'payload_uuid' => $payload, 'meta' => json_encode(['product_id' => 'product_1', 'scheduled_at' => $at, 'quantity' => $quantity]), 'created_at' => '2026-10-10 00:00:00']);
    bookingDb()->table('orders')->insert(['uuid' => uniqid('order_'), 'payload_uuid' => $payload, 'status' => $status]);
}

beforeEach(function () {
    createBookingSchema();
    // Sunday 11 Oct 2026, 20:00 in Singapore.
    Carbon::setTestNow(Carbon::parse('2026-10-11 20:00:00', 'Asia/Singapore'));
});

afterEach(function () {
    Carbon::setTestNow();
});

test('the service hours are cut into slots of its length, in the store time zone', function () {
    $slots = BookingSlots::forDay(bookableService(['duration_minutes' => 60, 'capacity' => 2]), '2026-10-12');

    expect(array_column($slots, 'time'))->toBe(['09:00', '10:00', '11:00'])
        ->and($slots[0]['start'])->toBe('2026-10-12T09:00:00+08:00')
        ->and($slots[0]['remaining'])->toBe(2)
        ->and($slots[0]['available'])->toBeTrue();
});

test('bookings take room in their slot; canceled ones give it back', function () {
    bookedAt('2026-10-12T10:00:00+08:00', 'payload_a');
    bookedAt('2026-10-12T10:00:00+08:00', 'payload_b', 'canceled');
    bookedAt('2026-10-12T11:00:00+08:00', 'payload_c', 'created', 2);

    $slots = collect(BookingSlots::forDay(bookableService(['duration_minutes' => 60, 'capacity' => 2]), '2026-10-12'))->keyBy('time');

    expect($slots['09:00']['remaining'])->toBe(2)
        ->and($slots['10:00']['remaining'])->toBe(1)
        ->and($slots['11:00']['remaining'])->toBe(0)
        ->and($slots['11:00']['available'])->toBeFalse();
});

test('slots already started are past, and a day without hours has no slots', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-12 10:30:00', 'Asia/Singapore'));
    $slots = BookingSlots::forDay(bookableService(), '2026-10-12');

    expect(array_column($slots, 'past'))->toBe([true, true, false])
        ->and(BookingSlots::forDay(bookableService(), '2026-10-13'))->toBe([]);
});

test('service settings default to one booking per hour', function () {
    expect(BookingSlots::service(bookableService()))->toMatchArray(['duration_minutes' => 60, 'capacity' => 1])
        ->and(BookingSlots::service(bookableService(['duration_minutes' => 2, 'capacity' => 0])))->toMatchArray(['duration_minutes' => 5, 'capacity' => 1]);
});

test("a store's clock: its timezone, else its company's, as the offset now", function () {
    createBookingSchema();
    bookingDb()->table('companies')->insert(['uuid' => 'company-ub', 'timezone' => 'Asia/Ulaanbaatar']);

    $own      = new Fleetbase\Storefront\Models\Store(['timezone' => 'Asia/Singapore']);
    $inherits = new Fleetbase\Storefront\Models\Store(['company_uuid' => 'company-ub']);
    $nothing  = new Fleetbase\Storefront\Models\Store([]);

    expect(BookingSlots::timezone($own))->toBe('Asia/Singapore')
        ->and(BookingSlots::utcOffset($own))->toBe(480)
        ->and(BookingSlots::timezone($inherits))->toBe('Asia/Ulaanbaatar')
        ->and(BookingSlots::utcOffset($inherits))->toBe(480)
        ->and(BookingSlots::timezone(null, 'company-ub'))->toBe('Asia/Ulaanbaatar')
        ->and(BookingSlots::timezone($nothing))->toBe(config('app.timezone', 'UTC'))
        ->and(BookingSlots::utcOffset(new Fleetbase\Storefront\Models\Store(['timezone' => 'Not/AZone'])))->toBe(0);
});
