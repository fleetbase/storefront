<?php

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\Storefront\Http\Controllers\v1\CustomerController;
use Fleetbase\Storefront\Models\Checkout;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Support\StorefrontSocket;
use Fleetbase\Support\SocketCluster\SocketChannelRegistry;
use Fleetbase\Support\SocketCluster\SocketPrincipal;
use Fleetbase\Support\SocketCluster\SocketToken;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store as SessionStore;

function storefrontSocketEnable(bool $enabled = true): void
{
    config(['broadcasting.connections.socketcluster.auth_key' => $enabled ? 'storefront-socket-test-key-0123456789abcdef' : null]);
}

function storefrontSocketSchema(): void
{
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();

    foreach (['companies', 'contacts', 'personal_access_tokens', 'stores', 'networks', 'checkouts'] as $table) {
        $schema->dropIfExists($table);
    }

    $schema->create('companies', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('contacts', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('type')->nullable();
        $table->string('name')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
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
    foreach (['stores', 'networks'] as $storefrontTable) {
        $schema->create($storefrontTable, function ($table) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('public_id')->nullable();
            $table->string('company_uuid')->nullable();
            $table->string('backdrop_uuid')->nullable();
            $table->string('logo_uuid')->nullable();
            $table->string('order_config_uuid')->nullable();
            $table->string('key')->nullable();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->text('translations')->nullable();
            $table->string('website')->nullable();
            $table->string('facebook')->nullable();
            $table->string('instagram')->nullable();
            $table->string('twitter')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('tags')->nullable();
            $table->string('currency')->nullable();
            $table->string('timezone')->nullable();
            $table->string('pod_method')->nullable();
            $table->text('options')->nullable();
            $table->text('alertable')->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
        });
    }
    $schema->create('checkouts', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('store_uuid')->nullable();
        $table->string('network_uuid')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->string('owner_type')->nullable();
        $table->text('options')->nullable();
        $table->string('token')->nullable();
        $table->string('order_uuid')->nullable();
        $table->boolean('captured')->default(false);
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });

    $connection->table('companies')->insert([
        ['uuid' => 'company-a', 'public_id' => 'company_aaaaaaa'],
        ['uuid' => 'company-b', 'public_id' => 'company_bbbbbbb'],
    ]);
    $connection->table('stores')->insert([
        ['uuid' => 'store-a', 'public_id' => 'store_aaaaaaa', 'company_uuid' => 'company-a', 'key' => 'store_key_a'],
        ['uuid' => 'store-a2', 'public_id' => 'store_aaaaaa2', 'company_uuid' => 'company-a', 'key' => 'store_key_a2'],
        ['uuid' => 'store-b', 'public_id' => 'store_bbbbbbb', 'company_uuid' => 'company-b', 'key' => 'store_key_b'],
        ['uuid' => 'store-orphan', 'public_id' => 'store_orphan1', 'company_uuid' => null, 'key' => 'store_key_orphan'],
    ]);
    $connection->table('networks')->insert([
        ['uuid' => 'network-a', 'public_id' => 'network_aaaaaaa', 'company_uuid' => 'company-a', 'key' => 'network_key_a'],
    ]);
    $connection->table('contacts')->insert([
        ['uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'public_id' => 'contact_aaaaaaa', 'company_uuid' => 'company-a', 'type' => 'customer'],
        ['uuid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'public_id' => 'contact_bbbbbbb', 'company_uuid' => 'company-b', 'type' => 'customer'],
    ]);
    $connection->table('checkouts')->insert([
        ['uuid' => 'checkout-a', 'public_id' => 'chkt_aaaaaaa', 'company_uuid' => 'company-a', 'store_uuid' => 'store-a', 'network_uuid' => null, 'owner_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'token' => 'checkout_a'],
        ['uuid' => 'checkout-guest', 'public_id' => 'chkt_guest01', 'company_uuid' => 'company-a', 'store_uuid' => null, 'network_uuid' => 'network-a', 'owner_uuid' => null, 'token' => 'checkout_g'],
        ['uuid' => 'checkout-b', 'public_id' => 'chkt_bbbbbbb', 'company_uuid' => 'company-b', 'store_uuid' => 'store-b', 'network_uuid' => null, 'owner_uuid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'token' => 'checkout_b'],
        ['uuid' => 'checkout-orphan', 'public_id' => 'chkt_orphan1', 'company_uuid' => null, 'store_uuid' => null, 'network_uuid' => null, 'owner_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'token' => 'checkout_o'],
    ]);

    foreach (['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' => 'customer-secret-a', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb' => 'customer-secret-b'] as $contact => $secret) {
        $connection->table('personal_access_tokens')->insert([
            'name'       => $contact,
            'token'      => hash('sha256', $secret),
            'abilities'  => '["*"]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

function storefrontSocketTokenRequest(?string $storefrontKey, ?string $customerToken): Request
{
    $request = Request::create('/storefront/v1/customers/socket-token', 'POST');
    $request->setLaravelSession(new SessionStore('storefront-socket-test', new ArraySessionHandler(120)));
    if ($customerToken) {
        $request->headers->set('Customer-Token', $customerToken);
    }
    app()->instance('request', $request);
    session(['storefront_key' => $storefrontKey]);

    return $request;
}

function storefrontSocketPrincipal(string $kind, array $claims = []): SocketPrincipal
{
    return SocketPrincipal::fromClaims(array_merge(['kind' => $kind, 'sub' => $kind . '-subject', 'cid' => 'company-a'], $claims));
}

beforeEach(function () {
    storefrontSocketSchema();
    storefrontSocketEnable(false);
});

afterEach(function () {
    storefrontSocketEnable(false);
    session(['storefront_key' => null]);
});

test('customer socket token is not found while socket auth is disabled', function () {
    $response = (new CustomerController())->socketToken(storefrontSocketTokenRequest('store_key_a', 'customer-secret-a'));

    expect($response->getStatusCode())->toBe(404)
        ->and($response->getData(true))->toBe(['error' => 'Not found.']);
});

test('customer socket token requires an authenticated customer of the storefront company', function () {
    storefrontSocketEnable();
    $controller = new CustomerController();

    $noCustomer      = $controller->socketToken(storefrontSocketTokenRequest('store_key_a', null));
    $unknownCustomer = $controller->socketToken(storefrontSocketTokenRequest('store_key_a', 'not-a-real-token'));
    $noStorefront    = $controller->socketToken(storefrontSocketTokenRequest(null, 'customer-secret-a'));
    $otherCompany    = $controller->socketToken(storefrontSocketTokenRequest('store_key_b', 'customer-secret-a'));

    foreach ([$noCustomer, $unknownCustomer, $noStorefront, $otherCompany] as $response) {
        expect($response->getStatusCode())->toBe(401)
            ->and($response->getData(true))->toBe(['error' => 'Not authorized to create a socket token for customer.']);
    }
});

test('customer socket token mints a customer principal scoped to the store', function () {
    storefrontSocketEnable();

    $response = (new CustomerController())->socketToken(storefrontSocketTokenRequest('store_key_a', 'customer-secret-a'));
    $body     = $response->getData(true);
    $claims   = SocketToken::verify($body['token']);

    expect($response->getStatusCode())->toBe(200)
        ->and(array_keys($body))->toBe(['token', 'expires_in', 'expires_at'])
        ->and($body['expires_in'])->toBe(900)
        ->and($claims)->toBeInstanceOf(SocketPrincipal::class)
        ->and($claims->kind)->toBe('customer')
        ->and($claims->sub)->toBe('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')
        ->and($claims->ids)->toBe(['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'contact_aaaaaaa'])
        ->and($claims->cid)->toBe('company-a')
        ->and($claims->cpid)->toBe('company_aaaaaaa')
        ->and($claims->sid)->toBe('store-a')
        ->and($claims->env)->toBe('live')
        ->and($claims->adm)->toBeFalse()
        ->and($claims->scp)->toBeNull();
});

test('customer socket token minted with a network key is scoped to the network', function () {
    storefrontSocketEnable();

    $response = (new CustomerController())->socketToken(storefrontSocketTokenRequest('network_key_a', 'customer-secret-a'));
    $claims   = SocketToken::verify($response->getData(true)['token']);

    expect($claims->kind)->toBe('customer')
        ->and($claims->sid)->toBe('network-a')
        ->and($claims->cid)->toBe('company-a');
});

test('checkout principal is limited by scope to its own checkout channel', function () {
    $storeCheckout   = Checkout::where('uuid', 'checkout-a')->firstOrFail();
    $networkCheckout = Checkout::where('uuid', 'checkout-guest')->firstOrFail();
    $orphanCheckout  = Checkout::where('uuid', 'checkout-orphan')->firstOrFail();

    $store   = StorefrontSocket::checkoutPrincipal($storeCheckout);
    $network = StorefrontSocket::checkoutPrincipal($networkCheckout);
    $orphan  = StorefrontSocket::checkoutPrincipal($orphanCheckout);

    expect($store->kind)->toBe('checkout')
        ->and($store->sub)->toBe('checkout-a')
        ->and($store->ids)->toBe(['checkout-a', 'chkt_aaaaaaa'])
        ->and($store->cid)->toBe('company-a')
        ->and($store->cpid)->toBe('company_aaaaaaa')
        ->and($store->scp)->toBe(['checkout.chkt_aaaaaaa'])
        ->and($store->sid)->toBe('store-a')
        ->and($store->env)->toBe('live')
        ->and($network->sid)->toBe('network-a')
        ->and($network->scp)->toBe(['checkout.chkt_guest01'])
        ->and($orphan->cid)->toBeNull()
        ->and($orphan->cpid)->toBeNull()
        ->and(StorefrontSocket::checkoutChannel($storeCheckout))->toBe('checkout.chkt_aaaaaaa');
});

test('checkout socket token is only minted while socket auth is enabled', function () {
    $checkout = Checkout::where('uuid', 'checkout-guest')->firstOrFail();

    $disabled = StorefrontSocket::checkoutToken($checkout);
    storefrontSocketEnable();
    $enabled = StorefrontSocket::checkoutToken($checkout);
    $claims  = SocketToken::verify($enabled['token']);

    expect($disabled)->toBeNull()
        ->and(array_keys($enabled))->toBe(['token', 'expires_in', 'expires_at'])
        ->and($claims->kind)->toBe('checkout')
        ->and($claims->sub)->toBe('checkout-guest')
        ->and($claims->scp)->toBe(['checkout.chkt_guest01'])
        ->and($claims->cid)->toBe('company-a');
});

test('storefront channels resolve stores and networks by uuid public id or key within the company', function () {
    $user = storefrontSocketPrincipal('user');
    $api  = storefrontSocketPrincipal('api');

    expect(StorefrontSocket::authorizeStorefront($user, 'store-a', 'storefront.store-a'))->toBeTrue()
        ->and(StorefrontSocket::authorizeStorefront($user, 'store_aaaaaaa', 'storefront.store_aaaaaaa'))->toBeTrue()
        ->and(StorefrontSocket::authorizeStorefront($user, 'store_key_a', 'storefront.store_key_a'))->toBeTrue()
        ->and(StorefrontSocket::authorizeStorefront($api, 'network_aaaaaaa', 'storefront.network_aaaaaaa'))->toBeTrue()
        ->and(StorefrontSocket::authorizeStorefront($user, 'store_bbbbbbb', 'storefront.store_bbbbbbb'))->toBeFalse()
        ->and(StorefrontSocket::authorizeStorefront($user, 'store_orphan1', 'storefront.store_orphan1'))->toBeFalse()
        ->and(StorefrontSocket::authorizeStorefront($user, 'store_missing', 'storefront.store_missing'))->toBeFalse();
});

test('storefront channels let a customer subscribe only to the storefront their token names', function () {
    $customer      = storefrontSocketPrincipal('customer', ['sub' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'sid' => 'store-a']);
    $networkMember = storefrontSocketPrincipal('customer', ['sub' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'sid' => 'network-a']);
    $unscoped      = storefrontSocketPrincipal('customer', ['sub' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']);
    $foreign       = storefrontSocketPrincipal('customer', ['sub' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'cid' => 'company-b', 'sid' => 'store-a']);
    $driver        = storefrontSocketPrincipal('driver', ['sid' => 'store-a']);

    expect(StorefrontSocket::authorizeStorefront($customer, 'store_aaaaaaa', 'storefront.store_aaaaaaa'))->toBeTrue()
        ->and(StorefrontSocket::authorizeStorefront($customer, 'store_aaaaaa2', 'storefront.store_aaaaaa2'))->toBeFalse()
        ->and(StorefrontSocket::authorizeStorefront($networkMember, 'network-a', 'storefront.network-a'))->toBeTrue()
        ->and(StorefrontSocket::authorizeStorefront($networkMember, 'store-a', 'storefront.store-a'))->toBeFalse()
        ->and(StorefrontSocket::authorizeStorefront($unscoped, 'store-a', 'storefront.store-a'))->toBeFalse()
        ->and(StorefrontSocket::authorizeStorefront($foreign, 'store-a', 'storefront.store-a'))->toBeFalse()
        ->and(StorefrontSocket::authorizeStorefront($driver, 'store-a', 'storefront.store-a'))->toBeFalse();
});

test('checkout channels allow the company and the owning customer only', function () {
    $user     = storefrontSocketPrincipal('user');
    $api      = storefrontSocketPrincipal('api');
    $owner    = storefrontSocketPrincipal('customer', ['sub' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'sid' => 'store-a']);
    $stranger = storefrontSocketPrincipal('customer', ['sub' => 'contact-other', 'sid' => 'store-a']);
    $foreign  = storefrontSocketPrincipal('customer', ['sub' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'cid' => 'company-b', 'sid' => 'store-b']);
    $checkout = storefrontSocketPrincipal('checkout', ['sub' => 'checkout-a', 'scp' => ['checkout.chkt_aaaaaaa']]);
    $driver   = storefrontSocketPrincipal('driver');

    expect(StorefrontSocket::authorizeCheckout($user, 'chkt_aaaaaaa', 'checkout.chkt_aaaaaaa'))->toBeTrue()
        ->and(StorefrontSocket::authorizeCheckout($api, 'checkout-a', 'checkout.checkout-a'))->toBeTrue()
        ->and(StorefrontSocket::authorizeCheckout($user, 'chkt_bbbbbbb', 'checkout.chkt_bbbbbbb'))->toBeFalse()
        ->and(StorefrontSocket::authorizeCheckout($user, 'chkt_orphan1', 'checkout.chkt_orphan1'))->toBeFalse()
        ->and(StorefrontSocket::authorizeCheckout($user, 'chkt_missing', 'checkout.chkt_missing'))->toBeFalse()
        ->and(StorefrontSocket::authorizeCheckout($owner, 'chkt_aaaaaaa', 'checkout.chkt_aaaaaaa'))->toBeTrue()
        ->and(StorefrontSocket::authorizeCheckout($owner, 'chkt_guest01', 'checkout.chkt_guest01'))->toBeFalse()
        ->and(StorefrontSocket::authorizeCheckout($stranger, 'chkt_aaaaaaa', 'checkout.chkt_aaaaaaa'))->toBeFalse()
        ->and(StorefrontSocket::authorizeCheckout($foreign, 'chkt_aaaaaaa', 'checkout.chkt_aaaaaaa'))->toBeFalse()
        ->and(StorefrontSocket::authorizeCheckout($checkout, 'chkt_aaaaaaa', 'checkout.chkt_aaaaaaa'))->toBeFalse()
        ->and(StorefrontSocket::authorizeCheckout($driver, 'chkt_aaaaaaa', 'checkout.chkt_aaaaaaa'))->toBeFalse();
});

test('storefront registers its storefront and checkout channel resolvers', function () {
    $registry = new SocketChannelRegistry();

    StorefrontSocket::registerChannels($registry);

    $storefront = $registry->resolve('storefront');
    $checkout   = $registry->resolve('checkout');
    $customer   = storefrontSocketPrincipal('customer', ['sub' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'sid' => 'store-a']);

    expect($storefront)->toBeInstanceOf(Closure::class)
        ->and($checkout)->toBeInstanceOf(Closure::class)
        ->and($storefront($customer, 'store-a', 'storefront.store-a'))->toBeTrue()
        ->and($checkout($customer, 'chkt_aaaaaaa', 'checkout.chkt_aaaaaaa'))->toBeTrue()
        ->and($checkout($customer, 'chkt_bbbbbbb', 'checkout.chkt_bbbbbbb'))->toBeFalse();
});

test('customer principal is built from the customer and the storefront of the key', function () {
    $customer = Contact::where('uuid', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')->firstOrFail();
    $store    = Store::where('uuid', 'store-a')->firstOrFail();
    $network  = Network::where('uuid', 'network-a')->firstOrFail();

    $forStore   = StorefrontSocket::customerPrincipal($customer, $store);
    $forNetwork = StorefrontSocket::customerPrincipal($customer, $network);

    expect($forStore->toClaims())->toBe([
        'kind' => 'customer',
        'sub'  => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        'cid'  => 'company-a',
        'cpid' => 'company_aaaaaaa',
        'env'  => 'live',
        'ids'  => ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'contact_aaaaaaa'],
        'adm'  => false,
        'sid'  => 'store-a',
    ])->and($forNetwork->sid)->toBe('network-a');
});
