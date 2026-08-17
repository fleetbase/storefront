<?php

use Fleetbase\Models\User;
use Fleetbase\Storefront\Http\Middleware\SetStorefrontSession;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

test('storefront session middleware rejects missing and malformed credentials', function () {
    $middleware = new SetStorefrontSession();
    $next       = fn () => response()->json(['ok' => true]);

    $missing = $middleware->handle(Request::create('/storefront'), $next);
    $invalid = $middleware->handle(
        Request::create('/storefront', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer invalid_key']),
        $next
    );

    expect($missing->getStatusCode())->toBe(401)
        ->and($missing->getData(true))->toBe(['error' => 'Oops! No Storefront key found with this request'])
        ->and($invalid->getStatusCode())->toBe(401)
        ->and($invalid->getData(true))->toBe(['error' => 'Oops! The Storefront key provided was not valid'])
        ->and($middleware->isValidKey('merchant_key'))->toBeFalse();
});

test('storefront session middleware validates store credentials and exposes complete store context', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('stores');
    $schema->create('stores', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id');
        $table->string('company_uuid');
        $table->string('key');
        $table->string('currency');
        $table->timestamp('deleted_at')->nullable();
    });
    $connection->table('stores')->insert([
        'uuid'         => 'store_uuid',
        'public_id'    => 'store_public',
        'company_uuid' => 'company_uuid',
        'key'          => 'store_secret',
        'currency'     => 'USD',
    ]);

    $middleware = new SetStorefrontSession();

    expect($middleware->isValidKey('store_secret'))->toBeTrue();

    $response = $middleware->handle(
        Request::create('/storefront', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer store_secret']),
        fn () => response()->json(['ok' => true])
    );

    expect($response->getData(true))->toBe(['ok' => true])
        ->and(session('storefront_key'))->toBe('store_secret')
        ->and(session('storefront_store'))->toBe('store_uuid')
        ->and(session('storefront_store_public_id'))->toBe('store_public')
        ->and(session('storefront_currency'))->toBe('USD')
        ->and(session('company'))->toBe('company_uuid')
        ->and(session('api_credential'))->toBe('store_secret');
});

test('storefront session middleware validates network credentials and exposes complete network context', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('networks');
    $schema->create('networks', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id');
        $table->string('company_uuid');
        $table->string('key');
        $table->string('currency');
        $table->timestamp('deleted_at')->nullable();
    });
    $connection->table('networks')->insert([
        'uuid'         => 'network_uuid',
        'public_id'    => 'network_public',
        'company_uuid' => 'company_uuid',
        'key'          => 'network_secret',
        'currency'     => 'MNT',
    ]);

    $middleware = new SetStorefrontSession();

    expect($middleware->isValidKey('network_secret'))->toBeTrue();

    $middleware->setKey('network_secret');

    expect(session('storefront_network'))->toBe('network_uuid')
        ->and(session('storefront_network_public_id'))->toBe('network_public')
        ->and(session('storefront_currency'))->toBe('MNT')
        ->and(session('api_credential'))->toBe('network_secret');
});

test('switching between a network and a store key never leaves both scopes in the session', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();

    foreach (['stores', 'networks'] as $table) {
        $schema->dropIfExists($table);
        $schema->create($table, function ($blueprint) {
            $blueprint->string('uuid')->primary();
            $blueprint->string('public_id');
            $blueprint->string('company_uuid');
            $blueprint->string('key');
            $blueprint->string('currency');
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    $connection->table('stores')->insert([
        'uuid'         => 'store_uuid',
        'public_id'    => 'store_public',
        'company_uuid' => 'company_uuid',
        'key'          => 'store_secret',
        'currency'     => 'USD',
    ]);
    $connection->table('networks')->insert([
        'uuid'         => 'network_uuid',
        'public_id'    => 'network_public',
        'company_uuid' => 'company_uuid',
        'key'          => 'network_secret',
        'currency'     => 'MNT',
    ]);

    $middleware = new SetStorefrontSession();

    // The session is cookie-backed, so a client hitting a network endpoint and then a
    // store endpoint reuses it. Leaving both scopes set made every store-scoped query
    // also apply the stricter network filter.
    $middleware->setKey('network_secret');
    $middleware->setKey('store_secret');

    expect(session('storefront_store'))->toBe('store_uuid')
        ->and(session('storefront_store_public_id'))->toBe('store_public')
        ->and(session('storefront_network'))->toBeNull()
        ->and(session('storefront_network_public_id'))->toBeNull();

    $middleware->setKey('network_secret');

    expect(session('storefront_network'))->toBe('network_uuid')
        ->and(session('storefront_network_public_id'))->toBe('network_public')
        ->and(session('storefront_store'))->toBeNull()
        ->and(session('storefront_store_public_id'))->toBeNull();
});

test('customer setup is a no-op without a customer token', function () {
    $middleware = new SetStorefrontSession();

    expect($middleware->setupCustomerSession(Request::create('/storefront')))->toBeNull();
});

test('access token resolution returns an already loaded tokenable model', function () {
    $tokenable = new class extends Model {
    };
    $tokenable->forceFill(['uuid' => 'user_uuid']);

    $token = new PersonalAccessToken();
    $token->setRelation('tokenable', $tokenable);

    expect((new SetStorefrontSession())->getTokenableFromAccessToken($token))->toBe($tokenable);
});

test('customer setup resolves an unloaded access token owner and stores its contact identity', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    foreach (['personal_access_tokens', 'contacts', 'users'] as $table) {
        $schema->dropIfExists($table);
    }
    $schema->create('users', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('contacts', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('user_uuid')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('personal_access_tokens', function ($table) {
        $table->increments('id');
        $table->string('tokenable_type')->nullable();
        $table->string('tokenable_id')->nullable();
        $table->string('name')->nullable();
        $table->string('token');
        $table->text('abilities')->nullable();
        $table->timestamp('last_used_at')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
    });
    $connection->table('users')->insert([
        'uuid' => 'user_uuid',
    ]);
    $connection->table('contacts')->insert([
        'uuid'      => 'contact_uuid',
        'public_id' => 'contact_abcdefgh',
        'user_uuid' => 'user_uuid',
    ]);
    $connection->table('personal_access_tokens')->insert([
        'tokenable_type' => User::class,
        'tokenable_id'   => 'user_uuid',
        'name'           => 'customer access',
        'token'          => hash('sha256', 'customer-secret'),
        'abilities'      => '["*"]',
        'created_at'     => now(),
        'updated_at'     => now(),
    ]);
    $request = Request::create('/storefront', 'GET', [], [], [], [
        'HTTP_CUSTOMER_TOKEN' => 'customer-secret',
    ]);

    (new SetStorefrontSession())->setupCustomerSession($request);

    expect(session('customer_id'))->toBe('customer_abcdefgh')
        ->and(session('contact_id'))->toBe('contact_abcdefgh')
        ->and(session('customer'))->toBe('contact_uuid');

    $connection->table('personal_access_tokens')->insert([
        'tokenable_type' => User::class,
        'tokenable_id'   => 'missing_user_uuid',
        'name'           => 'orphaned customer access',
        'token'          => hash('sha256', 'orphaned-secret'),
        'abilities'      => '["*"]',
        'created_at'     => now(),
        'updated_at'     => now(),
    ]);
    $orphanedRequest = Request::create('/storefront', 'GET', [], [], [], [
        'HTTP_CUSTOMER_TOKEN' => 'orphaned-secret',
    ]);

    expect((new SetStorefrontSession())->setupCustomerSession($orphanedRequest))->toBeNull()
        ->and(session('customer'))->toBe('contact_uuid');
});
