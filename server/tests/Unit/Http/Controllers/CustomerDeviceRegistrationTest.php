<?php

use Fleetbase\Models\UserDevice;
use Fleetbase\Storefront\Http\Controllers\v1\CustomerController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store as SessionStore;

function bootDeviceRegistrationCustomer(string $customerUuid, string $userUuid, string $secret): void
{
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();

    if (!$schema->hasTable('contacts')) {
        $schema->create('contacts', function ($table) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('public_id')->nullable();
            $table->string('company_uuid')->nullable();
            $table->string('user_uuid')->nullable();
            $table->string('type')->nullable();
            $table->text('meta')->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
        });
    }

    if (!$schema->hasTable('personal_access_tokens')) {
        $schema->create('personal_access_tokens', function ($table) {
            $table->increments('id');
            $table->string('tokenable_type')->nullable();
            $table->string('tokenable_id')->nullable();
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    $connection->table('contacts')->insert([
        'uuid'      => $customerUuid,
        'public_id' => 'contact_' . substr($customerUuid, 0, 4),
        'user_uuid' => $userUuid,
        'type'      => 'customer',
    ]);
    $connection->table('personal_access_tokens')->insert([
        'name'       => $customerUuid,
        'token'      => hash('sha256', $secret),
        'abilities'  => '["*"]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function deviceRegistrationRequest(string $secret, array $input): Request
{
    $request = Request::create('/customers/register-device', 'POST', $input);
    $request->setLaravelSession(new SessionStore('device-registration-test', new ArraySessionHandler(120)));
    $request->headers->set('Customer-Token', $secret);
    app()->instance('request', $request);

    return $request;
}

beforeEach(function () {
    $schema = Model::getConnectionResolver()->connection('mysql')->getSchemaBuilder();
    foreach (['contacts', 'personal_access_tokens', 'user_devices'] as $table) {
        $schema->dropIfExists($table);
    }
    $schema->create('user_devices', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('platform')->nullable();
        $table->text('token')->nullable();
        $table->string('status')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });

    bootDeviceRegistrationCustomer('11111111-1111-4111-8111-111111111111', 'first_user', 'first-secret');
    bootDeviceRegistrationCustomer('22222222-2222-4222-8222-222222222222', 'second_user', 'second-secret');
});

test('device registration validates the token and platform', function () {
    $controller = new CustomerController();

    $missingToken = $controller->registerDevice(deviceRegistrationRequest('first-secret', ['platform' => 'ios']));
    $badPlatform  = $controller->registerDevice(deviceRegistrationRequest('first-secret', ['token' => 'abc', 'platform' => 'web']));

    expect($missingToken->getData(true))->toBe(['error' => 'A device token is required.'])
        ->and($badPlatform->getData(true))->toBe(['error' => 'Device platform must be either ios or android.'])
        ->and(UserDevice::query()->count())->toBe(0);
});

test('device registration requires the customer to have a user account', function () {
    bootDeviceRegistrationCustomer('33333333-3333-4333-8333-333333333333', '', 'userless-secret');
    Model::getConnectionResolver()->connection('mysql')->table('contacts')->where('uuid', '33333333-3333-4333-8333-333333333333')->update(['user_uuid' => null]);

    $response = (new CustomerController())->registerDevice(deviceRegistrationRequest('userless-secret', ['token' => 'abc', 'platform' => 'ios']));

    expect($response->getData(true))->toBe(['error' => 'Unable to register device for customer without a user account.']);
});

test('device registration normalizes the platform and reassigns a token to the latest customer', function () {
    $controller = new CustomerController();

    $controller->registerDevice(deviceRegistrationRequest('first-secret', ['token' => ' shared-token ', 'os' => 'Android']));

    expect(UserDevice::where('token', 'shared-token')->get(['user_uuid', 'platform', 'status'])->toArray())->toBe([
        ['user_uuid' => 'first_user', 'platform' => 'android', 'status' => 'active'],
    ]);

    // The same install logs in as another customer.
    $response = $controller->registerDevice(deviceRegistrationRequest('second-secret', ['token' => 'shared-token', 'platform' => 'android']));

    expect($response->getData(true)['status'])->toBe('OK')
        ->and(UserDevice::withTrashed()->where('token', 'shared-token')->count())->toBe(1)
        ->and(UserDevice::where('token', 'shared-token')->value('user_uuid'))->toBe('second_user');
});

test('device registration restores pruned tokens and collapses legacy duplicates', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('user_devices')->insert([
        ['uuid' => 'd1', 'user_uuid' => 'first_user', 'platform' => 'ios', 'token' => 'ios-token', 'status' => 'invalid', 'deleted_at' => now(), 'updated_at' => now()->subDay()],
        ['uuid' => 'd2', 'user_uuid' => 'first_user', 'platform' => 'iOS', 'token' => 'ios-token', 'status' => 'active', 'deleted_at' => null, 'updated_at' => now()->subDays(2)],
    ]);

    (new CustomerController())->registerDevice(deviceRegistrationRequest('first-secret', ['token' => 'ios-token', 'platform' => 'ios']));

    expect(UserDevice::withTrashed()->where('token', 'ios-token')->get(['uuid', 'platform', 'status', 'deleted_at'])->toArray())->toBe([
        ['uuid' => 'd1', 'platform' => 'ios', 'status' => 'active', 'deleted_at' => null],
    ]);
});

test('device unregistration only removes the authenticated customer token', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('user_devices')->insert([
        ['uuid' => 'd1', 'user_uuid' => 'first_user', 'platform' => 'ios', 'token' => 'first-token', 'status' => 'active'],
        ['uuid' => 'd2', 'user_uuid' => 'second_user', 'platform' => 'ios', 'token' => 'second-token', 'status' => 'active'],
    ]);
    $controller = new CustomerController();

    $other = $controller->unregisterDevice(deviceRegistrationRequest('first-secret', ['token' => 'second-token']));
    $own   = $controller->unregisterDevice(deviceRegistrationRequest('first-secret', ['token' => 'first-token']));

    expect($other->getData(true))->toBe(['status' => 'OK', 'deleted' => 0])
        ->and($own->getData(true))->toBe(['status' => 'OK', 'deleted' => 1])
        ->and(UserDevice::pluck('token')->all())->toBe(['second-token']);
});

test('device unregistration requires an authenticated customer and a token', function () {
    $controller = new CustomerController();

    $unauthenticated = $controller->unregisterDevice(deviceRegistrationRequest('wrong-secret', ['token' => 'first-token']));
    $missingToken    = $controller->unregisterDevice(deviceRegistrationRequest('first-secret', []));

    expect($unauthenticated->getData(true))->toBe(['error' => 'Not authorized to unregister device for customer'])
        ->and($missingToken->getData(true))->toBe(['error' => 'A device token is required.']);
});

test('device registration records the app and APNs environment when core-api supports it', function () {
    $schema = Model::getConnectionResolver()->connection('mysql')->getSchemaBuilder();
    $schema->table('user_devices', function ($table) {
        $table->string('app_identifier')->nullable();
        $table->string('environment')->nullable();
        $table->timestamp('last_seen_at')->nullable();
    });

    $request = deviceRegistrationRequest('first-secret', ['token' => 'ios-token', 'platform' => 'ios', 'environment' => 'sandbox']);
    $request->session()->put('storefront_network', 'network_uuid');
    session(['storefront_store' => null, 'storefront_network' => 'network_uuid']);

    (new CustomerController())->registerDevice($request);
    (new CustomerController())->registerDevice(deviceRegistrationRequest('first-secret', ['token' => 'android-token', 'platform' => 'android', 'environment' => 'bogus']));

    expect(UserDevice::where('token', 'ios-token')->first(['app_identifier', 'environment'])->toArray())->toBe(['app_identifier' => 'network_uuid', 'environment' => 'sandbox'])
        ->and(UserDevice::where('token', 'ios-token')->value('last_seen_at'))->not->toBeNull()
        ->and(UserDevice::where('token', 'android-token')->value('environment'))->toBeNull();
});
