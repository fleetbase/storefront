<?php

use Fleetbase\Storefront\Models\Customer;
use Fleetbase\Storefront\Rules\CartExists;
use Fleetbase\Storefront\Rules\CustomerExists;
use Fleetbase\Storefront\Rules\GatewayExists;
use Fleetbase\Storefront\Rules\IsValidLocation;
use Fleetbase\Storefront\Support\StripeUtils;

test('gateway validation always accepts cash without querying a provider', function () {
    $rule = new GatewayExists();

    expect($rule->passes('gateway', 'cash'))->toBeTrue()
        ->and($rule->message())->toBe('No gateway by code provided exists.');
});

test('location validation accepts coordinate arrays and objects and rejects malformed values', function () {
    $rule = new IsValidLocation();

    expect($rule->passes('origin', [
        'latitude'  => 1.3521,
        'longitude' => 103.8198,
    ]))->toBeTrue()
        ->and($rule->passes('origin', (object) [
            'coordinates' => [
                'latitude'  => 1.3521,
                'longitude' => 103.8198,
            ],
        ]))->toBeTrue()
        ->and($rule->passes('origin', null))->toBeFalse()
        ->and($rule->message())->toBe('Invalid :attribute.');
});

test('validation rules expose stable client-facing failure messages', function () {
    expect((new CartExists())->message())->toBe('Cart session does not exists.')
        ->and((new CustomerExists())->message())->toBe('No customer found.');
});

test('cart validation accepts either the public id or browser identifier and rejects unknown carts', function () {
    $schema = Illuminate\Database\Capsule\Manager::schema('mysql');
    $schema->dropIfExists('carts');
    $schema->create('carts', function (Illuminate\Database\Schema\Blueprint $table) {
        $table->increments('id');
        $table->string('public_id')->nullable();
        $table->string('unique_identifier')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Illuminate\Database\Capsule\Manager::connection('mysql')->table('carts')->insert([
        'public_id'         => 'cart_public',
        'unique_identifier' => 'browser_session',
    ]);

    $rule = new CartExists();

    expect($rule->passes('cart', 'cart_public'))->toBeTrue()
        ->and($rule->passes('cart', 'browser_session'))->toBeTrue()
        ->and($rule->passes('cart', 'cart_missing'))->toBeFalse();
});

test('customer and gateway validation resolve persisted public contracts', function () {
    $schema = Illuminate\Database\Capsule\Manager::schema('mysql');
    $schema->dropIfExists('contacts');
    $schema->dropIfExists('gateways');
    $schema->create('contacts', function (Illuminate\Database\Schema\Blueprint $table) {
        $table->increments('id');
        $table->string('public_id');
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('gateways', function (Illuminate\Database\Schema\Blueprint $table) {
        $table->increments('id');
        $table->string('code');
        $table->timestamps();
        $table->softDeletes();
    });
    Illuminate\Database\Capsule\Manager::connection('mysql')->table('contacts')->insert([
        'public_id' => 'contact_123',
    ]);
    Illuminate\Database\Capsule\Manager::connection('mysql')->table('gateways')->insert([
        'code' => 'stripe',
    ]);

    expect((new CustomerExists())->passes('customer', 'customer_123'))->toBeTrue()
        ->and((new CustomerExists())->passes('customer', 'customer_missing'))->toBeFalse()
        ->and((new GatewayExists())->passes('gateway', 'stripe'))->toBeTrue()
        ->and((new GatewayExists())->passes('gateway', 'missing'))->toBeFalse();
});

test('location validation resolves every supported persisted location identifier', function () {
    $schema = Illuminate\Database\Capsule\Manager::schema('mysql');

    foreach (['places', 'store_locations', 'vehicles', 'food_trucks'] as $tableName) {
        $schema->dropIfExists($tableName);
        $schema->create($tableName, function (Illuminate\Database\Schema\Blueprint $table) {
            $table->increments('id');
            $table->string('public_id');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    $connection = Illuminate\Database\Capsule\Manager::connection('mysql');
    $connection->table('places')->insert(['public_id' => 'place_123']);
    $connection->table('store_locations')->insert(['public_id' => 'store_location_123']);
    $connection->table('vehicles')->insert(['public_id' => 'vehicle_123']);
    $connection->table('food_trucks')->insert(['public_id' => 'food_truck_123']);

    $rule = new IsValidLocation();

    expect($rule->passes('origin', 'place_123'))->toBeTrue()
        ->and($rule->passes('origin', 'store_location_123'))->toBeTrue()
        ->and($rule->passes('origin', 'vehicle_123'))->toBeTrue()
        ->and($rule->passes('origin', 'food_truck_123'))->toBeTrue()
        ->and($rule->passes('origin', 'place_missing'))->toBeFalse()
        ->and($rule->passes('origin', 'unsupported_123'))->toBeFalse();
});

test('stripe payment method validation fails safely when customer metadata is incomplete', function () {
    $customer = new Customer();
    $customer->forceFill(['meta' => []]);

    expect(StripeUtils::isCustomerPaymentMethodValid($customer))->toBeFalse();

    $customer->forceFill(['meta' => ['stripe_id' => 'cus_123']]);

    expect(StripeUtils::isCustomerPaymentMethodValid($customer))->toBeFalse();
});

test('stripe payment method validation verifies ownership and contains provider failures', function () {
    Stripe\Stripe::setApiKey('sk_test_storefront');
    $customer = new Customer();
    $customer->forceFill(['meta' => [
        'stripe_id'                => 'cus_expected',
        'stripe_payment_method_id' => 'pm_saved',
    ]]);
    Stripe\ApiRequestor::setHttpClient(new class implements Stripe\HttpClient\ClientInterface {
        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            return [json_encode([
                'id'       => 'pm_saved',
                'object'   => 'payment_method',
                'customer' => 'cus_expected',
                'type'     => 'card',
            ]), 200, []];
        }
    });

    expect(StripeUtils::isCustomerPaymentMethodValid($customer))->toBeTrue();

    $customer->forceFill(['meta' => [
        'stripe_id'                => 'cus_other',
        'stripe_payment_method_id' => 'pm_saved',
    ]]);

    expect(StripeUtils::isCustomerPaymentMethodValid($customer))->toBeFalse();

    Stripe\ApiRequestor::setHttpClient(new class implements Stripe\HttpClient\ClientInterface {
        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            throw new RuntimeException('Stripe unavailable');
        }
    });

    expect(StripeUtils::isCustomerPaymentMethodValid($customer))->toBeFalse();

    Stripe\ApiRequestor::setHttpClient(new Stripe\HttpClient\CurlClient());
});
