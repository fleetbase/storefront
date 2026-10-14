<?php

use Fleetbase\Storefront\Auth\Schemas\Storefront as StorefrontSchema;
use Fleetbase\Storefront\Http\Controllers\NetworkController;
use Fleetbase\Storefront\Http\Controllers\StoreController;
use Fleetbase\Storefront\Http\Middleware\SetStorefrontSession;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Support\Utils;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;

function createStorefrontKeySchema(): void
{
    $schema = Capsule::schema('mysql');

    foreach (['stores', 'networks'] as $table) {
        $schema->dropIfExists($table);
        $schema->create($table, function ($blueprint) {
            $blueprint->increments('id');
            $blueprint->string('uuid')->nullable();
            $blueprint->string('public_id')->nullable();
            $blueprint->string('company_uuid')->nullable();
            $blueprint->string('key')->nullable();
            $blueprint->string('name')->nullable();
            $blueprint->string('slug')->nullable();
            $blueprint->string('currency')->nullable();
            $blueprint->timestamps();
            $blueprint->softDeletes();
        });
    }

    $connection = Capsule::connection('mysql');
    $connection->table('stores')->insert([
        ['uuid' => 'store_uuid', 'public_id' => 'store_public', 'company_uuid' => 'company_uuid', 'key' => 'store_' . md5('old'), 'name' => 'Corner Store', 'currency' => 'USD'],
        ['uuid' => 'other_store_uuid', 'public_id' => 'store_other', 'company_uuid' => 'other_company_uuid', 'key' => 'store_' . md5('other'), 'name' => 'Other Store', 'currency' => 'USD'],
    ]);
    $connection->table('networks')->insert([
        ['uuid' => 'network_uuid', 'public_id' => 'network_public', 'company_uuid' => 'company_uuid', 'key' => 'network_' . md5('old'), 'name' => 'Night Market', 'currency' => 'MNT'],
        ['uuid' => 'other_network_uuid', 'public_id' => 'network_other', 'company_uuid' => 'other_company_uuid', 'key' => 'network_' . md5('other'), 'name' => 'Other Market', 'currency' => 'MNT'],
    ]);
}

function storefrontKeyRequest(?string $company): Request
{
    $request = Request::create('/', 'POST');
    $request->setLaravelSession(new Illuminate\Session\Store('storefront-keys', new Illuminate\Session\ArraySessionHandler(120)));
    $request->session()->put('company', $company);

    return $request;
}

test('generated storefront keys carry the prefix and 32 random hex characters', function () {
    $storeKeys   = collect(range(1, 50))->map(fn () => Store::generateKey());
    $networkKeys = collect(range(1, 50))->map(fn () => Network::generateKey());

    expect($storeKeys->every(fn ($key) => preg_match('/^store_[0-9a-f]{32}$/', $key) === 1))->toBeTrue()
        ->and($networkKeys->every(fn ($key) => preg_match('/^network_[0-9a-f]{32}$/', $key) === 1))->toBeTrue()
        ->and($storeKeys->unique()->count())->toBe(50)
        ->and($networkKeys->unique()->count())->toBe(50);
});

test('new stores and networks get a generated key when created', function () {
    createStorefrontKeySchema();
    Store::setEventDispatcher(new Dispatcher(app()));
    Store::clearBootedModels();

    try {
        foreach ([new Store(), new Network()] as $model) {
            (new ReflectionMethod($model, 'fireModelEvent'))->invoke($model, 'creating', false);

            expect($model->key)->toMatch('/^' . $model::KEY_PREFIX . '[0-9a-f]{32}$/');
        }
    } finally {
        Store::unsetEventDispatcher();
        Store::clearBootedModels();
    }
});

test('rotating a key saves a new valid key and the old key no longer passes the storefront middleware', function (string $class, string $uuid) {
    createStorefrontKeySchema();
    $middleware = new SetStorefrontSession();
    $model      = $class::where('uuid', $uuid)->first();
    $oldKey     = $model->key;

    expect($middleware->isValidKey($oldKey))->toBeTrue();

    $newKey = $model->rotateKey();

    expect($newKey)->not->toBe($oldKey)
        ->and($newKey)->toMatch('/^' . $class::KEY_PREFIX . '[0-9a-f]{32}$/')
        ->and($class::where('uuid', $uuid)->value('key'))->toBe($newKey)
        ->and($middleware->isValidKey($oldKey))->toBeFalse()
        ->and($middleware->isValidKey($newKey))->toBeTrue();
})->with([
    'store'   => [Store::class, 'store_uuid'],
    'network' => [Network::class, 'network_uuid'],
]);

test('rotating a key fires the model update so the api model cache is invalidated', function () {
    createStorefrontKeySchema();
    Store::setEventDispatcher(new Dispatcher(app()));
    Store::clearBootedModels();
    app()->instance('responsecache', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });

    try {
        $updated = [];
        Store::updated(function ($store) use (&$updated) {
            $updated[] = $store->getChanges();
        });

        $key = Store::where('uuid', 'store_uuid')->first()->rotateKey();

        expect($updated)->toHaveCount(1)
            ->and($updated[0]['key'])->toBe($key);
    } finally {
        Store::unsetEventDispatcher();
        Store::clearBootedModels();
        app()->offsetUnset('responsecache');
    }
});

test('the rotate key endpoints rotate a store or network of the current company and return the new key', function (string $controller, string $class, string $id) {
    createStorefrontKeySchema();
    $oldKey   = $class::where('public_id', $id)->value('key');
    $response = (new $controller())->rotateKey($id, storefrontKeyRequest('company_uuid'));
    $data     = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($data['status'])->toBe('ok')
        ->and($data['key'])->not->toBe($oldKey)
        ->and($data['key'])->toBe($class::where('public_id', $id)->value('key'));
})->with([
    'store by public id'   => [StoreController::class, Store::class, 'store_public'],
    'network by public id' => [NetworkController::class, Network::class, 'network_public'],
]);

test('the rotate key endpoints accept uuids', function () {
    createStorefrontKeySchema();

    expect((new StoreController())->rotateKey('store_uuid', storefrontKeyRequest('company_uuid'))->getStatusCode())->toBe(200)
        ->and((new NetworkController())->rotateKey('network_uuid', storefrontKeyRequest('company_uuid'))->getStatusCode())->toBe(200);
});

test('the rotate key endpoints never touch another company\'s store or network', function (string $controller, string $class, string $id, ?string $company) {
    createStorefrontKeySchema();
    $oldKey   = $class::where('public_id', $id)->value('key');
    $response = (new $controller())->rotateKey($id, storefrontKeyRequest($company));

    expect($response->getStatusCode())->toBe(404)
        ->and($class::where('public_id', $id)->value('key'))->toBe($oldKey);
})->with([
    'store of another company'   => [StoreController::class, Store::class, 'store_other', 'company_uuid'],
    'network of another company' => [NetworkController::class, Network::class, 'network_other', 'company_uuid'],
    'store without a company'    => [StoreController::class, Store::class, 'store_public', null],
    'network without a company'  => [NetworkController::class, Network::class, 'network_public', null],
]);

test('rotating keys is a guarded storefront permission on stores and networks', function () {
    $resources = collect((new StorefrontSchema())->resources)->keyBy('name');

    // The authorization guard maps a controller method to a schema action by slug, so the
    // rotateKey actions require `storefront rotate-key store` / `storefront rotate-key network`.
    expect(Utils::slugify('rotateKey'))->toBe('rotate-key')
        ->and($resources['store']['actions'])->toContain('rotate-key')
        ->and($resources['network']['actions'])->toContain('rotate-key');
});
