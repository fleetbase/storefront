<?php

use Fleetbase\Storefront\Http\Controllers\AnalyticsController;
use Fleetbase\Storefront\Models\Network;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

function analyticsAttentionDb()
{
    return Model::getConnectionResolver()->connection('mysql');
}

function analyticsAttentionSchema(): void
{
    $schema = analyticsAttentionDb()->getSchemaBuilder();
    foreach (['orders', 'transactions', 'stores', 'networks', 'products', 'food_trucks', 'vehicles', 'invites'] as $table) {
        $schema->dropIfExists($table);
    }
    $schema->create('orders', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('type')->nullable();
        $table->string('status')->nullable();
        $table->string('customer_uuid')->nullable();
        $table->string('transaction_uuid')->nullable();
        $table->string('driver_assigned_uuid')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('transactions', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->unsignedBigInteger('amount')->nullable();
        $table->string('currency')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('stores', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('email')->nullable();
        $table->string('currency')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('networks', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('products', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('store_uuid')->nullable();
        $table->string('status')->nullable();
        $table->boolean('is_available')->default(true);
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('food_trucks', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('store_uuid')->nullable();
        $table->string('vehicle_uuid')->nullable();
        $table->string('status')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('vehicles', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->boolean('online')->default(false);
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('invites', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('reason')->nullable();
        $table->text('recipients')->nullable();
        $table->text('meta')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
}

function analyticsAttentionOrder(string $publicId, string $status, array $meta = [], array $attributes = []): array
{
    return array_merge([
        'uuid'                 => $publicId . '_uuid',
        'public_id'            => $publicId,
        'company_uuid'         => 'company_uuid',
        'type'                 => 'storefront',
        'status'               => $status,
        'driver_assigned_uuid' => null,
        'meta'                 => json_encode(array_merge(['storefront_id' => 'store_a'], $meta)),
        'created_at'           => '2026-10-14 09:00:00',
        'updated_at'           => '2026-10-14 09:00:00',
        'deleted_at'           => null,
    ], $attributes);
}

function analyticsAttentionInvite(string $uuid, array $recipients, array $attributes = []): array
{
    return array_merge([
        'uuid'         => $uuid,
        'company_uuid' => 'company_uuid',
        'subject_uuid' => 'network_market_uuid',
        'subject_type' => Network::class,
        'reason'       => 'join_storefront_network',
        'recipients'   => json_encode($recipients),
        'meta'         => json_encode(['status' => 'pending']),
        'expires_at'   => null,
        'created_at'   => '2026-10-10 09:00:00',
        'updated_at'   => '2026-10-10 09:00:00',
    ], $attributes);
}

function analyticsAttentionSeed(): void
{
    $db = analyticsAttentionDb();
    $db->table('stores')->insert([
        ['uuid' => 'store_a_uuid', 'public_id' => 'store_a', 'company_uuid' => 'company_uuid', 'name' => 'Bloom', 'email' => 'bloom@example.test'],
        ['uuid' => 'store_b_uuid', 'public_id' => 'store_b', 'company_uuid' => 'company_uuid', 'name' => 'Petal', 'email' => null],
    ]);
    $db->table('networks')->insert([
        ['uuid' => 'network_market_uuid', 'public_id' => 'network_market', 'company_uuid' => 'company_uuid', 'name' => 'Market'],
        ['uuid' => 'network_mall_uuid', 'public_id' => 'network_mall', 'company_uuid' => 'company_uuid', 'name' => 'Mall'],
    ]);
    $db->table('orders')->insert([
        analyticsAttentionOrder('order_waiting_newer', 'created', [], ['created_at' => '2026-10-14 08:30:00']),
        analyticsAttentionOrder('order_waiting_oldest', 'pending', [], ['created_at' => '2026-10-14 07:15:00']),
        analyticsAttentionOrder('order_ready_pickup', 'ready', ['is_pickup' => true]),
        analyticsAttentionOrder('order_pickup_ready', 'pickup_ready', ['is_pickup' => true]),
        // Ready, but for delivery: waiting on a driver rather than the customer.
        analyticsAttentionOrder('order_ready_delivery', 'ready', ['is_pickup' => false]),
        analyticsAttentionOrder('order_preparing', 'preparing'),
        analyticsAttentionOrder('order_dispatched', 'dispatched', [], ['driver_assigned_uuid' => 'driver_uuid']),
        analyticsAttentionOrder('order_preparing_pickup', 'preparing', ['is_pickup' => true]),
        analyticsAttentionOrder('order_done', 'completed'),
        analyticsAttentionOrder('order_canceled', 'canceled'),
        analyticsAttentionOrder('order_store_b', 'created', ['storefront_id' => 'store_b'], ['created_at' => '2026-10-13 06:00:00']),
        analyticsAttentionOrder('order_transport', 'created', [], ['type' => 'transport']),
        analyticsAttentionOrder('order_deleted', 'created', [], ['deleted_at' => '2026-10-14 09:30:00']),
        analyticsAttentionOrder('order_foreign', 'created', [], ['company_uuid' => 'other_company']),
    ]);
    $db->table('products')->insert([
        ['uuid' => 'product_sold_out', 'company_uuid' => 'company_uuid', 'store_uuid' => 'store_a_uuid', 'status' => 'published', 'is_available' => false],
        ['uuid' => 'product_in_stock', 'company_uuid' => 'company_uuid', 'store_uuid' => 'store_a_uuid', 'status' => 'published', 'is_available' => true],
        ['uuid' => 'product_draft', 'company_uuid' => 'company_uuid', 'store_uuid' => 'store_a_uuid', 'status' => 'draft', 'is_available' => false],
        ['uuid' => 'product_b_sold_out', 'company_uuid' => 'company_uuid', 'store_uuid' => 'store_b_uuid', 'status' => 'published', 'is_available' => false],
        ['uuid' => 'product_foreign', 'company_uuid' => 'other_company', 'store_uuid' => 'store_a_uuid', 'status' => 'published', 'is_available' => false],
    ]);
    $db->table('vehicles')->insert([
        ['uuid' => 'vehicle_online', 'online' => true],
        ['uuid' => 'vehicle_offline', 'online' => false],
    ]);
    $db->table('food_trucks')->insert([
        ['uuid' => 'truck_online', 'company_uuid' => 'company_uuid', 'store_uuid' => 'store_a_uuid', 'vehicle_uuid' => 'vehicle_online', 'status' => 'active'],
        ['uuid' => 'truck_offline', 'company_uuid' => 'company_uuid', 'store_uuid' => 'store_a_uuid', 'vehicle_uuid' => 'vehicle_offline', 'status' => 'active'],
        // A truck without a vehicle cannot be online.
        ['uuid' => 'truck_no_vehicle', 'company_uuid' => 'company_uuid', 'store_uuid' => 'store_a_uuid', 'vehicle_uuid' => null, 'status' => 'active'],
        ['uuid' => 'truck_inactive', 'company_uuid' => 'company_uuid', 'store_uuid' => 'store_a_uuid', 'vehicle_uuid' => 'vehicle_offline', 'status' => 'inactive'],
        ['uuid' => 'truck_store_b', 'company_uuid' => 'company_uuid', 'store_uuid' => 'store_b_uuid', 'vehicle_uuid' => 'vehicle_offline', 'status' => 'active'],
    ]);
    $db->table('invites')->insert([
        analyticsAttentionInvite('invite_market', ['bloom@example.test']),
        analyticsAttentionInvite('invite_mall', ['owner@example.test', 'bloom@example.test'], ['subject_uuid' => 'network_mall_uuid', 'expires_at' => '2026-11-01 00:00:00']),
        // An invitation to a network that no longer exists still counts, without a name.
        analyticsAttentionInvite('invite_gone_network', ['bloom@example.test'], ['subject_uuid' => 'network_gone_uuid', 'meta' => json_encode([])]),
        // Not pending, expired, for someone else, for another reason or another company.
        analyticsAttentionInvite('invite_accepted', ['bloom@example.test'], ['meta' => json_encode(['status' => 'accepted'])]),
        analyticsAttentionInvite('invite_expired', ['bloom@example.test'], ['expires_at' => '2026-10-01 00:00:00']),
        analyticsAttentionInvite('invite_other_email', ['petal@example.test']),
        analyticsAttentionInvite('invite_other_reason', ['bloom@example.test'], ['reason' => 'join_company']),
        analyticsAttentionInvite('invite_foreign', ['bloom@example.test'], ['company_uuid' => 'other_company']),
    ]);
}

function analyticsAttention(array $input = []): array
{
    session(['company' => 'company_uuid']);

    return (new AnalyticsController())->attention(Request::create('/storefront/analytics/attention', 'GET', $input))->getData(true);
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-14 12:00:00');
    analyticsAttentionSchema();
    analyticsAttentionSeed();
});

afterEach(function () {
    Carbon::setTestNow();
    session(['company' => null]);
});

test('attention for a store lists what its operator has to act on today', function () {
    $data = analyticsAttention(['store' => 'store_a']);

    expect($data['orders_to_confirm'])->toBe(2)
        ->and($data['oldest_order_id'])->toBe('order_waiting_oldest')
        ->and(Carbon::parse($data['oldest_waiting_at'])->toDateTimeString())->toBe('2026-10-14 07:15:00')
        ->and($data['ready_for_pickup'])->toBe(2)
        // Confirmed delivery orders without a driver: ready for delivery and preparing.
        ->and($data['awaiting_driver'])->toBe(2)
        ->and($data['out_of_stock'])->toBe(1)
        ->and($data['trucks_offline'])->toBe(2)
        ->and($data['network_invitations'])->toBe(3)
        ->and($data['invitation_networks'])->toBe(['Market', 'Mall']);
});

test('attention resolves the store by uuid or the storefront parameter', function () {
    expect(analyticsAttention(['storefront' => 'store_a_uuid']))->toBe(analyticsAttention(['store' => 'store_a']));
});

test('attention for a store without an email has no network invitations', function () {
    $data = analyticsAttention(['store' => 'store_b']);

    expect($data)->toMatchArray([
        'orders_to_confirm'   => 1,
        'oldest_order_id'     => 'order_store_b',
        'ready_for_pickup'    => 0,
        'awaiting_driver'     => 0,
        'out_of_stock'        => 1,
        'trucks_offline'      => 1,
        'network_invitations' => 0,
        'invitation_networks' => [],
    ]);
});

test('attention across the company counts every store and leaves invitations out', function () {
    $data = analyticsAttention();

    expect($data['orders_to_confirm'])->toBe(3)
        // The oldest order waiting anywhere in the company.
        ->and($data['oldest_order_id'])->toBe('order_store_b')
        ->and($data['ready_for_pickup'])->toBe(2)
        ->and($data['awaiting_driver'])->toBe(2)
        ->and($data['out_of_stock'])->toBe(2)
        ->and($data['trucks_offline'])->toBe(3)
        ->and($data['network_invitations'])->toBe(0)
        ->and($data['invitation_networks'])->toBe([]);
});

test('attention with nothing waiting is all zeros', function () {
    foreach (['orders', 'products', 'food_trucks', 'invites'] as $table) {
        analyticsAttentionDb()->table($table)->delete();
    }

    expect(analyticsAttention(['store' => 'store_a']))->toBe([
        'orders_to_confirm'   => 0,
        'oldest_waiting_at'   => null,
        'oldest_order_id'     => null,
        'ready_for_pickup'    => 0,
        'awaiting_driver'     => 0,
        'out_of_stock'        => 0,
        'trucks_offline'      => 0,
        'network_invitations' => 0,
        'invitation_networks' => [],
    ]);
});
