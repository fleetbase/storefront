<?php

use Fleetbase\Storefront\Models\Product;
use Fleetbase\Storefront\Support\BookingSlots;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

function bookingCoverageDb()
{
    return Model::getConnectionResolver()->connection('mysql');
}

function bookingCoverageSchema(): void
{
    $schema = bookingCoverageDb()->getSchemaBuilder();
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

    bookingCoverageDb()->table('stores')->insert(['uuid' => 'store_uuid', 'company_uuid' => 'company_uuid', 'timezone' => 'Asia/Singapore']);
}

/** A one-at-a-time service open 09:00-12:00 on Mondays, in hour slots. */
function bookingCoverageService(): Product
{
    $product = new Product();
    $product->forceFill(['uuid' => 'product_uuid', 'public_id' => 'product_1', 'store_uuid' => 'store_uuid', 'is_bookable' => true, 'meta' => ['service' => ['duration_minutes' => 60, 'capacity' => 1]]]);
    $product->setRelation('hours', collect([(object) ['day_of_week' => 'monday', 'start' => '09:00:00', 'end' => '12:00:00']]));

    return $product;
}

function bookingCoverageBooked(string $scheduledAt, string $payload): void
{
    bookingCoverageDb()->table('entities')->insert(['uuid' => uniqid('entity_'), 'payload_uuid' => $payload, 'meta' => json_encode(['product_id' => 'product_1', 'scheduled_at' => $scheduledAt]), 'created_at' => '2026-10-10 00:00:00']);
    bookingCoverageDb()->table('orders')->insert(['uuid' => uniqid('order_'), 'payload_uuid' => $payload, 'status' => 'created']);
}

beforeEach(function () {
    bookingCoverageSchema();
    // Sunday 11 Oct 2026, 20:00 in Singapore.
    Carbon::setTestNow(Carbon::parse('2026-10-11 20:00:00', 'Asia/Singapore'));
});

afterEach(function () {
    Carbon::setTestNow();
});

test('a time is available only when it starts a slot that still has room', function () {
    bookingCoverageBooked('2026-10-12T10:00:00+08:00', 'payload_a');
    $service = bookingCoverageService();

    expect(BookingSlots::isAvailable($service, Carbon::parse('2026-10-12T09:00:00+08:00')))->toBeTrue()
        // The same moment given in UTC is the same slot.
        ->and(BookingSlots::isAvailable($service, Carbon::parse('2026-10-12T03:00:00Z')))->toBeTrue()
        ->and(BookingSlots::isAvailable($service, Carbon::parse('2026-10-12T10:00:00+08:00')))->toBeFalse()
        ->and(BookingSlots::isAvailable($service, Carbon::parse('2026-10-12T09:30:00+08:00')))->toBeFalse()
        ->and(BookingSlots::isAvailable($service, Carbon::parse('2026-10-13T09:00:00+08:00')))->toBeFalse();
});

test('a booking whose time cannot be read takes no slot', function () {
    bookingCoverageBooked('not a time', 'payload_a');
    bookingCoverageBooked('2026-10-12T11:00:00+08:00', 'payload_b');

    $slots = collect(BookingSlots::forDay(bookingCoverageService(), '2026-10-12'))->keyBy('time');

    expect($slots->map(fn ($slot) => $slot['remaining'])->all())->toBe(['09:00' => 1, '10:00' => 1, '11:00' => 0]);
});
