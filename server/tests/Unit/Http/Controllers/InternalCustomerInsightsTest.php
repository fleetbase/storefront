<?php

use Fleetbase\Storefront\Http\Controllers\CustomerController as InternalCustomerController;
use Fleetbase\Storefront\Models\CustomerSegment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

const CUSTOMER_INSIGHTS_UUID = '5d1c8a4e-7b2f-4c33-9a10-6f0e2b9d4c11';

function customerInsightsDb()
{
    return Model::getConnectionResolver()->connection('mysql');
}

function customerInsightsSchema(): void
{
    $schema = customerInsightsDb()->getSchemaBuilder();
    foreach (['contacts', 'orders', 'customer_segments', 'stores', 'networks', 'transactions', 'user_devices'] as $table) {
        $schema->dropIfExists($table);
    }
    $schema->create('contacts', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('type')->nullable();
        $table->string('name')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('orders', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('customer_uuid')->nullable();
        $table->string('type')->nullable();
        $table->string('status')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('customer_segments', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->string('owner_type')->nullable();
        $table->string('name')->nullable();
        $table->text('rules')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    foreach (['stores', 'networks'] as $name) {
        $schema->create($name, function ($table) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('public_id')->nullable();
            $table->string('company_uuid')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
        });
    }
}

function customerInsightsOrder(string $publicId, array $meta, array $attributes = []): array
{
    return array_merge([
        'uuid'          => $publicId . '_uuid',
        'public_id'     => $publicId,
        'company_uuid'  => 'company_uuid',
        'customer_uuid' => CUSTOMER_INSIGHTS_UUID,
        'type'          => 'storefront',
        'status'        => 'completed',
        'meta'          => json_encode($meta),
        'created_at'    => '2026-09-01 10:00:00',
        'updated_at'    => '2026-09-01 10:00:00',
        'deleted_at'    => null,
    ], $attributes);
}

function customerInsightsSegment(string $publicId, string $ownerUuid, string $ownerType, array $rules, string $company = 'company_uuid'): void
{
    $segment = new CustomerSegment();
    $segment->forceFill([
        'uuid'         => $publicId . '_uuid',
        'public_id'    => $publicId,
        'company_uuid' => $company,
        'owner_uuid'   => $ownerUuid,
        'owner_type'   => $ownerType,
        'name'         => ucfirst(str_replace('_', ' ', $publicId)),
        'rules'        => $rules,
    ]);
    $segment->save();
}

function customerInsightsSeed(): void
{
    $db = customerInsightsDb();
    $db->table('stores')->insert([
        ['uuid' => 'store_a_uuid', 'public_id' => 'store_a', 'company_uuid' => 'company_uuid', 'name' => 'Bloom'],
        ['uuid' => 'store_b_uuid', 'public_id' => 'store_b', 'company_uuid' => 'company_uuid', 'name' => 'Petal'],
    ]);
    $db->table('networks')->insert(['uuid' => 'network_uuid', 'public_id' => 'network_m', 'company_uuid' => 'company_uuid', 'name' => 'Market']);
    $db->table('contacts')->insert([
        ['uuid' => CUSTOMER_INSIGHTS_UUID, 'public_id' => 'contact_ada', 'company_uuid' => 'company_uuid', 'type' => 'customer', 'name' => 'Ada', 'created_at' => '2026-01-15 08:00:00', 'updated_at' => '2026-01-15 08:00:00'],
        ['uuid' => 'contact_bo_uuid', 'public_id' => 'contact_bo', 'company_uuid' => 'company_uuid', 'type' => 'customer', 'name' => 'Bo', 'created_at' => '2026-02-01 08:00:00', 'updated_at' => '2026-02-01 08:00:00'],
        ['uuid' => 'contact_foreign_uuid', 'public_id' => 'contact_foreign', 'company_uuid' => 'other_company', 'type' => 'customer', 'name' => 'Foreign', 'created_at' => '2026-02-01 08:00:00', 'updated_at' => '2026-02-01 08:00:00'],
    ]);
    $db->table('orders')->insert([
        customerInsightsOrder('order_first', ['storefront_id' => 'store_a', 'storefront' => 'Bloom', 'total' => 30.5, 'currency' => 'USD', 'is_pickup' => true], ['created_at' => '2026-09-01 10:00:00']),
        customerInsightsOrder('order_second', ['storefront_id' => 'store_a', 'storefront' => 'Bloom', 'total' => '20', 'currency' => 'USD', 'is_pickup' => false], ['status' => 'created', 'created_at' => '2026-09-10 10:00:00']),
        customerInsightsOrder('order_market', ['storefront_id' => 'store_b', 'storefront' => 'Petal', 'storefront_network_id' => 'network_m', 'total' => 49.5, 'currency' => 'USD', 'is_pickup' => true], ['created_at' => '2026-10-01 10:00:00']),
        customerInsightsOrder('order_canceled', ['storefront_id' => 'store_a', 'storefront' => 'Bloom', 'total' => 100, 'currency' => 'EUR'], ['status' => 'canceled', 'created_at' => '2026-10-05 10:00:00']),
        customerInsightsOrder('order_no_total', ['storefront_id' => 'store_a', 'storefront' => 'Bloom'], ['status' => 'order_canceled', 'created_at' => '2026-08-01 10:00:00']),
        // Not storefront orders, removed orders, other customers and other companies never count.
        customerInsightsOrder('order_fleetops', ['total' => 999], ['type' => 'transport']),
        customerInsightsOrder('order_deleted', ['storefront_id' => 'store_a', 'total' => 999], ['deleted_at' => '2026-09-02 10:00:00']),
        customerInsightsOrder('order_bo', ['storefront_id' => 'store_a', 'total' => 999], ['customer_uuid' => 'contact_bo_uuid']),
        customerInsightsOrder('order_foreign', ['storefront_id' => 'store_a', 'total' => 999], ['company_uuid' => 'other_company']),
    ]);

    customerInsightsSegment('segment_regulars', 'store_a_uuid', 'storefront:store', ['customers' => [CUSTOMER_INSIGHTS_UUID]]);
    customerInsightsSegment('segment_strangers', 'store_a_uuid', 'storefront:store', ['customers' => ['contact_bo_uuid']]);
    customerInsightsSegment('segment_market_buyers', 'network_uuid', 'storefront:network', ['min_orders' => 1]);
    customerInsightsSegment('segment_orphaned', 'store_gone_uuid', 'storefront:store', []);
    customerInsightsSegment('segment_foreign', 'store_a_uuid', 'storefront:store', [], 'other_company');
}

function customerInsights(string $id, array $input = []): Illuminate\Http\JsonResponse
{
    $request = Request::create('/int/v1/storefront/customers/' . $id . '/insights', 'GET', $input);
    app()->instance('request', $request);

    return (new InternalCustomerController())->insights($id, $request);
}

beforeEach(function () {
    customerInsightsSchema();
    customerInsightsSeed();
    session(['company' => 'company_uuid']);
});

afterEach(function () {
    session(['company' => null]);
});

test('customer insights summarize lifetime storefront orders, spend, fulfilment and segments', function () {
    $response = customerInsights(CUSTOMER_INSIGHTS_UUID);
    $data     = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($data['orders'])->toBe(3)
        ->and($data['canceled'])->toBe(2)
        ->and($data['spend'])->toEqual(100)
        ->and($data['average'])->toEqual(33.33)
        // The newest order is the last one, even when it was canceled.
        ->and($data['last_order_public_id'])->toBe('order_canceled')
        ->and($data['currency'])->toBe('EUR')
        ->and(Carbon::parse($data['last_order_at'])->toDateTimeString())->toBe('2026-10-05 10:00:00')
        ->and(Carbon::parse($data['first_order_at'])->toDateTimeString())->toBe('2026-08-01 10:00:00')
        ->and(Carbon::parse($data['since'])->toDateTimeString())->toBe('2026-01-15 08:00:00')
        // Two of three live orders were pickups.
        ->and($data['preferred_fulfillment'])->toBe('pickup')
        // Stores in the order of their most recent live order.
        ->and($data['stores'])->toBe([
            ['public_id' => 'store_b', 'name' => 'Petal', 'orders' => 1],
            ['public_id' => 'store_a', 'name' => 'Bloom', 'orders' => 2],
        ])
        ->and(collect($data['segments'])->pluck('public_id')->sort()->values()->all())->toBe(['segment_market_buyers', 'segment_regulars'])
        ->and(collect($data['segments'])->firstWhere('public_id', 'segment_regulars'))->toBe([
            'id'        => 'segment_regulars_uuid',
            'public_id' => 'segment_regulars',
            'name'      => 'Segment regulars',
        ]);
});

test('customer insights narrow to one storefront', function () {
    $data = customerInsights('contact_ada', ['storefront' => 'store_a'])->getData(true);

    expect($data['orders'])->toBe(2)
        ->and($data['canceled'])->toBe(2)
        ->and($data['spend'])->toEqual(50.5)
        ->and($data['average'])->toEqual(25.25)
        // One pickup out of two orders is not a majority.
        ->and($data['preferred_fulfillment'])->toBe('delivery')
        ->and($data['stores'])->toBe([['public_id' => 'store_a', 'name' => 'Bloom', 'orders' => 2]])
        // Only the store's own segments, and only those the customer matches.
        ->and(collect($data['segments'])->pluck('public_id')->all())->toBe(['segment_regulars']);
});

test('customer insights narrow to one network, which wins over a storefront', function () {
    $data = customerInsights('customer_ada', ['network' => 'network_m', 'storefront' => 'store_a'])->getData(true);

    expect($data['orders'])->toBe(1)
        ->and($data['canceled'])->toBe(0)
        ->and($data['spend'])->toEqual(49.5)
        ->and($data['last_order_public_id'])->toBe('order_market')
        ->and($data['first_order_at'])->toBe($data['last_order_at'])
        ->and($data['currency'])->toBe('USD')
        ->and($data['preferred_fulfillment'])->toBe('pickup')
        ->and($data['stores'])->toBe([['public_id' => 'store_b', 'name' => 'Petal', 'orders' => 1]])
        ->and(collect($data['segments'])->pluck('public_id')->all())->toBe(['segment_market_buyers']);
});

test('customer insights for a customer without orders are empty, and unknown customers are not found', function () {
    $quiet = customerInsights('contact_bo', ['network' => 'network_m'])->getData(true);
    $other = customerInsights('contact_foreign');
    $none  = customerInsights('contact_nobody');

    expect($quiet)->toMatchArray([
        'orders'                => 0,
        'segments'              => [],
        'canceled'              => 0,
        'spend'                 => 0,
        'average'               => 0,
        'currency'              => null,
        'first_order_at'        => null,
        'last_order_at'         => null,
        'last_order_public_id'  => null,
        'preferred_fulfillment' => null,
        'stores'                => [],
    ])
        // Another company's customer is invisible.
        ->and($other->getStatusCode())->toBe(404)
        ->and($other->getData(true))->toBe(['error' => 'Customer not found.'])
        ->and($none->getStatusCode())->toBe(404);
});
