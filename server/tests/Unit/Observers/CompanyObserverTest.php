<?php

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Models\Company;
use Fleetbase\Storefront\Observers\CompanyObserver;
use Fleetbase\Storefront\Observers\OrderObserver;
use Illuminate\Database\Eloquent\Model;

test('company creation provisions the default storefront order configuration', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('order_configs');
    $schema->create('order_configs', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid');
        $table->string('author_uuid')->nullable();
        $table->string('category_uuid')->nullable();
        $table->string('icon_uuid')->nullable();
        $table->string('name');
        $table->string('namespace');
        $table->text('description')->nullable();
        $table->string('key');
        $table->string('status');
        $table->string('version');
        $table->boolean('core_service')->default(false);
        $table->text('tags')->nullable();
        $table->text('flow')->nullable();
        $table->text('entities')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $company = new Company();
    $company->forceFill(['uuid' => 'company_uuid']);

    (new CompanyObserver())->created($company);

    $config = $connection->table('order_configs')->first();

    expect($config)->not->toBeNull()
        ->and($config->company_uuid)->toBe('company_uuid')
        ->and($config->key)->toBe('storefront')
        ->and($config->namespace)->toBe('system:order-config:storefront')
        ->and($config->core_service)->toBe(1);
});

test('order creation applies the company storefront configuration when none is selected', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('order_configs');
    $schema->create('order_configs', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('company_uuid');
        $table->string('name');
        $table->string('namespace');
        $table->string('key');
        $table->string('status')->nullable();
        $table->string('version')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $connection->table('order_configs')->insert([
        'uuid'         => 'order_config_uuid',
        'company_uuid' => 'observer_company_uuid',
        'name'         => 'Storefront',
        'namespace'    => 'system:order-config:storefront',
        'key'          => 'storefront',
    ]);
    session(['company' => 'observer_company_uuid']);
    $order = new Order();

    (new OrderObserver())->creating($order);

    expect($order->order_config_uuid)->toBe('order_config_uuid');
});
