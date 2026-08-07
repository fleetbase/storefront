<?php

use Fleetbase\Models\Company;
use Fleetbase\Storefront\Support\Metrics;
use Illuminate\Support\Carbon;

test('metrics builder preserves company and explicit reporting window', function () {
    $company = new Company();
    $company->forceFill(['uuid' => 'company_uuid']);
    $start = new DateTime('2026-01-01 00:00:00');
    $end   = new DateTime('2026-01-31 23:59:59');

    $metrics = Metrics::forCompany($company, $start, $end);

    $startProperty   = new ReflectionProperty(Metrics::class, 'start');
    $endProperty     = new ReflectionProperty(Metrics::class, 'end');
    $companyProperty = new ReflectionProperty(Metrics::class, 'company');

    expect($metrics)->toBeInstanceOf(Metrics::class)
        ->and($startProperty->getValue($metrics))->toBe($start)
        ->and($endProperty->getValue($metrics))->toBe($end)
        ->and($companyProperty->getValue($metrics))->toBe($company)
        ->and($metrics->get())->toBe([]);
});

test('metrics builder supplies broad defaults and remains fluently configurable', function () {
    Carbon::setTestNow('2026-07-26 12:00:00');

    $company = new Company();
    $company->forceFill(['uuid' => 'company_uuid']);
    $metrics = Metrics::new($company);

    $start = new DateTime('2026-07-01');
    $end   = new DateTime('2026-07-31');

    expect($metrics->start($start))->toBe($metrics)
        ->and($metrics->end($end))->toBe($metrics)
        ->and($metrics->between($start, $end))->toBe($metrics)
        ->and($metrics->with(['unknown_metric', 'also unknown']))->toBe($metrics)
        ->and($metrics->get())->toBe([]);

    Carbon::setTestNow();
});

test('metrics stores nested and batch values deterministically', function () {
    $company = new Company();
    $company->forceFill(['uuid' => 'company_uuid']);
    $metrics = Metrics::forCompany($company);
    $set     = new ReflectionMethod(Metrics::class, 'set');

    expect($set->invoke($metrics, 'orders.completed', 12))->toBe($metrics)
        ->and($set->invoke($metrics, [
            'orders.canceled' => 3,
            'products.total'  => 25,
        ]))->toBe($metrics)
        ->and($metrics->get())->toBe([
            'orders' => [
                'completed' => 12,
                'canceled'  => 3,
            ],
            'products' => [
                'total' => 25,
            ],
        ]);
});

test('metrics count storefront inventory and order states within the reporting window', function () {
    $schema = Illuminate\Database\Capsule\Manager::schema('mysql');
    foreach (['products', 'stores', 'networks', 'orders'] as $table) {
        $schema->dropIfExists($table);
        $schema->create($table, function (Illuminate\Database\Schema\Blueprint $table) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('public_id')->nullable();
            $table->string('company_uuid');
            $table->string('type')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    $connection = Illuminate\Database\Capsule\Manager::connection('mysql');
    foreach (['products', 'stores', 'networks'] as $table) {
        $connection->table($table)->insert([
            ['public_id' => $table . '_included', 'company_uuid' => 'company_uuid'],
            ['public_id' => $table . '_other', 'company_uuid' => 'other_company'],
        ]);
    }
    $connection->table('orders')->insert([
        ['public_id' => 'order_active', 'company_uuid' => 'company_uuid', 'type' => 'storefront', 'status' => 'dispatched', 'created_at' => '2026-07-15 12:00:00'],
        ['public_id' => 'order_complete', 'company_uuid' => 'company_uuid', 'type' => 'storefront', 'status' => 'completed', 'created_at' => '2026-07-16 12:00:00'],
        ['public_id' => 'order_canceled', 'company_uuid' => 'company_uuid', 'type' => 'storefront', 'status' => 'canceled', 'created_at' => '2026-07-17 12:00:00'],
        ['public_id' => 'order_created', 'company_uuid' => 'company_uuid', 'type' => 'storefront', 'status' => 'created', 'created_at' => '2026-07-18 12:00:00'],
        ['public_id' => 'order_old', 'company_uuid' => 'company_uuid', 'type' => 'storefront', 'status' => 'completed', 'created_at' => '2025-07-16 12:00:00'],
        ['public_id' => 'order_other_type', 'company_uuid' => 'company_uuid', 'type' => 'fleet-ops', 'status' => 'completed', 'created_at' => '2026-07-16 12:00:00'],
    ]);

    $company = new Company();
    $company->forceFill(['uuid' => 'company_uuid']);
    $metrics = Metrics::forCompany(
        $company,
        new DateTime('2026-07-01 00:00:00'),
        new DateTime('2026-07-31 23:59:59')
    );
    $onlyIncluded = function ($query) {
        $query->where('public_id', 'like', '%_included');
    };
    $onlyExpectedOrder = function ($query) {
        $query->where('public_id', 'not like', '%_excluded');
    };

    $metrics
        ->totalProducts($onlyIncluded)
        ->totalStores($onlyIncluded)
        ->totalNetworks($onlyIncluded)
        ->ordersInProgress($onlyExpectedOrder)
        ->ordersCompleted($onlyExpectedOrder)
        ->ordersCanceled($onlyExpectedOrder);

    expect($metrics->get())->toBe([
        'total_products'    => 1,
        'total_stores'      => 1,
        'total_networks'    => 1,
        'orders_in_progress'=> 1,
        'orders_completed'  => 1,
        'orders_canceled'   => 1,
    ]);

    expect(Metrics::forCompany(
        $company,
        new DateTime('2026-07-01 00:00:00'),
        new DateTime('2026-07-31 23:59:59')
    )->with()->get())->toMatchArray([
        'total_products'    => 1,
        'total_stores'      => 1,
        'total_networks'    => 1,
        'orders_in_progress'=> 1,
        'orders_completed'  => 1,
        'orders_canceled'   => 1,
    ]);
});
