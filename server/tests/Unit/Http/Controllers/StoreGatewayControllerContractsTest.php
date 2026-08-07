<?php

use Fleetbase\Storefront\Http\Controllers\v1\StoreController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

function createStoreGatewayControllerSchema(): void
{
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('stores');
    $schema->dropIfExists('gateways');

    $schema->create('stores', function (Illuminate\Database\Schema\Blueprint $table) {
        $table->increments('id');
        foreach ([
            'uuid', 'public_id', 'company_uuid', 'backdrop_uuid', 'logo_uuid',
            'order_config_uuid', 'key', 'name', 'description', 'translations',
            'website', 'facebook', 'instagram', 'twitter', 'email', 'phone',
            'tags', 'currency', 'timezone', 'pod_method', 'options',
        ] as $column) {
            $table->text($column)->nullable();
        }
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('gateways', function (Illuminate\Database\Schema\Blueprint $table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('code')->nullable();
        $table->string('type')->nullable();
        $table->boolean('sandbox')->default(false);
        $table->text('return_url')->nullable();
        $table->text('callback_url')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
}

test('store gateway listing scopes owner sandbox mode and configured cash availability', function () {
    createStoreGatewayControllerSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('stores')->insert([
        'uuid'       => 'store_uuid',
        'public_id'  => 'store_public',
        'key'        => 'store_key',
        'name'       => 'Corner Store',
        'options'    => json_encode(['cod_enabled' => true]),
    ]);
    $connection->table('gateways')->insert([
        [
            'uuid'       => 'live_uuid',
            'public_id'  => 'gateway_live',
            'owner_uuid' => 'store_uuid',
            'name'       => 'Live Stripe',
            'code'       => 'stripe',
            'type'       => 'stripe',
            'sandbox'    => false,
        ],
        [
            'uuid'       => 'sandbox_uuid',
            'public_id'  => 'gateway_sandbox',
            'owner_uuid' => 'store_uuid',
            'name'       => 'Sandbox Stripe',
            'code'       => 'stripe-test',
            'type'       => 'stripe',
            'sandbox'    => true,
        ],
        [
            'uuid'       => 'other_uuid',
            'public_id'  => 'gateway_other',
            'owner_uuid' => 'other_store',
            'name'       => 'Other Store',
            'code'       => 'other',
            'type'       => 'other',
            'sandbox'    => true,
        ],
    ]);
    session([
        'storefront_key'   => 'store_key',
        'storefront_store' => 'store_uuid',
    ]);

    $controller = new StoreController();
    $all        = $controller->gateways(Request::create('/gateways'));
    $sandbox    = $controller->gateways(Request::create('/gateways', 'GET', ['sandbox' => true]));

    expect($all->collection->pluck('public_id')->all())->toBe([
        'gateway_live',
        'gateway_sandbox',
        'gateway_cash',
    ])->and($sandbox->collection->pluck('public_id')->all())->toBe([
        'gateway_sandbox',
        'gateway_cash',
    ])->and($sandbox->collection->last()->sandbox)->toBeTrue();
});

test('store gateway lookup accepts public identifiers and provider codes with sandbox filtering', function () {
    createStoreGatewayControllerSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('gateways')->insert([
        'uuid'       => 'gateway_uuid',
        'public_id'  => 'gateway_public',
        'owner_uuid' => 'store_uuid',
        'name'       => 'Sandbox QPay',
        'code'       => 'qpay',
        'type'       => 'qpay',
        'sandbox'    => true,
    ]);
    session(['storefront_store' => 'store_uuid']);
    $controller = new StoreController();

    $byId   = $controller->gateway('gateway_public', Request::create('/gateway'));
    $byCode = $controller->gateway('qpay', Request::create('/gateway', 'GET', ['sandbox' => true]));
    $hidden = $controller->gateway('qpay', Request::create('/gateway', 'GET', ['sandbox' => false]));

    expect($byId->resource?->public_id)->toBe('gateway_public')
        ->and($byCode->resource?->public_id)->toBe('gateway_public')
        ->and($hidden->resource?->public_id)->toBe('gateway_public');
});

test('store search returns only published available products for the active store', function () {
    $productSchema = Model::getConnectionResolver()->connection('mysql')->getSchemaBuilder();
    $productSchema->dropIfExists('products');
    $productSchema->create('products', function (Illuminate\Database\Schema\Blueprint $table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id');
        $table->string('store_uuid');
        $table->string('category_uuid')->nullable();
        $table->string('name');
        $table->text('description')->nullable();
        $table->text('tags')->nullable();
        $table->boolean('is_available')->default(true);
        $table->string('status')->default('published');
        $table->timestamps();
        $table->softDeletes();
    });
    $categorySchema = $productSchema;
    $categorySchema->dropIfExists('categories');
    $categorySchema->create('categories', function (Illuminate\Database\Schema\Blueprint $table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('company_uuid')->nullable();
        $table->string('for')->nullable();
        $table->string('name')->nullable();
        $table->text('description')->nullable();
        $table->text('tags')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Model::getConnectionResolver()->connection('mysql')->table('products')->insert([
        [
            'uuid'         => 'matching_uuid',
            'public_id'    => 'product_matching',
            'store_uuid'   => 'store_uuid',
            'name'         => 'Cold Brew',
            'description'  => 'Fresh coffee',
            'is_available' => true,
            'status'       => 'published',
        ],
        [
            'uuid'         => 'unavailable_uuid',
            'public_id'    => 'product_unavailable',
            'store_uuid'   => 'store_uuid',
            'name'         => 'Cold Brew unavailable',
            'description'  => null,
            'is_available' => false,
            'status'       => 'published',
        ],
        [
            'uuid'         => 'draft_uuid',
            'public_id'    => 'product_draft',
            'store_uuid'   => 'store_uuid',
            'name'         => 'Cold Brew draft',
            'description'  => null,
            'is_available' => true,
            'status'       => 'draft',
        ],
        [
            'uuid'         => 'other_uuid',
            'public_id'    => 'product_other',
            'store_uuid'   => 'other_store',
            'name'         => 'Cold Brew elsewhere',
            'description'  => null,
            'is_available' => true,
            'status'       => 'published',
        ],
    ]);
    session([
        'storefront_key'   => 'store_key',
        'storefront_store' => 'store_uuid',
        'company'          => 'company_uuid',
    ]);

    $results = (new StoreController())->search(Request::create('/search', 'GET', [
        'query' => 'cold brew',
        'limit' => 2,
    ]));

    expect($results->collection->pluck('public_id')->all())->toBe(['product_matching']);
});
