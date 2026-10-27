<?php

use Fleetbase\Http\Middleware\AuthenticateOAuthToken;
use Fleetbase\Models\ApiCredential;
use Fleetbase\Models\Company;
use Fleetbase\Models\OAuthAccessToken;
use Fleetbase\Models\OAuthClient;
use Fleetbase\Storefront\Http\Controllers\OAuth\StorefrontController;
use Fleetbase\Storefront\Support\AppReview;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Two companies' stores and networks and the API credentials table. The access token is put
 * on the request the way core's AuthenticateOAuthToken middleware does, bound to company_a.
 */
function createOAuthStorefrontSchema(): void
{
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    foreach (['stores', 'networks', 'api_credentials'] as $table) {
        $schema->dropIfExists($table);
    }
    foreach (['stores', 'networks'] as $table) {
        $schema->create($table, function ($blueprint) {
            $blueprint->increments('id');
            foreach ([
                'uuid', 'public_id', 'company_uuid', 'backdrop_uuid', 'logo_uuid', 'key', 'name', 'description',
                'website', 'email', 'phone', 'currency', 'timezone', 'online', 'options', 'slug',
            ] as $column) {
                $blueprint->text($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->softDeletes();
        });
    }
    $schema->create('api_credentials', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'user_uuid', 'name', 'key', 'secret', 'api', 'expires_at', 'last_used_at'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->boolean('test_mode')->default(false);
        $blueprint->timestamps();
        $blueprint->softDeletes();
    });

    $connection->table('stores')->insert([
        ['uuid' => 'store_b_uuid', 'public_id' => 'store_b', 'company_uuid' => 'company_a', 'key' => 'store_b_key', 'name' => 'Bakery', 'currency' => 'USD', 'online' => '1', 'options' => '{"pickup_enabled":true}'],
        ['uuid' => 'store_a_uuid', 'public_id' => 'store_a', 'company_uuid' => 'company_a', 'key' => 'store_a_key', 'name' => 'Apothecary', 'currency' => 'USD', 'online' => '0', 'options' => null],
        ['uuid' => 'store_x_uuid', 'public_id' => 'store_x', 'company_uuid' => 'company_b', 'key' => 'store_x_key', 'name' => 'Elsewhere', 'currency' => 'USD', 'online' => '1', 'options' => null],
    ]);
    $connection->table('networks')->insert([
        ['uuid' => 'network_a_uuid', 'public_id' => 'network_a', 'company_uuid' => 'company_a', 'key' => 'network_a_key', 'name' => 'Market', 'currency' => 'USD', 'online' => '1', 'options' => null],
    ]);

    Model::setEventDispatcher(new Illuminate\Events\Dispatcher(app()));
    config(['activitylog.enabled' => false]);
    app()->instance(Spatie\Activitylog\ActivityLogStatus::class, tap(new Spatie\Activitylog\ActivityLogStatus(new Illuminate\Config\Repository(['activitylog' => ['enabled' => false]])))->disable());
    app()->instance('responsecache', new class {
        public function clear(): void
        {
        }
    });
    // What core's ApiCredentialObserver does on create.
    ApiCredential::creating(fn ($credential) => $credential->uuid ??= (string) Str::uuid());
    ApiCredential::created(fn ($credential) => $credential->forceFill(['key' => 'flb_live_' . Str::random(8)])->saveQuietly());
}

function oauthStorefrontRequest(string $method = 'GET'): Request
{
    $company = (new Company())->forceFill(['uuid' => 'company_a', 'name' => 'Company A']);
    $client  = (new OAuthClient())->forceFill(['id' => 'client_1', 'name' => 'AppHelm']);
    $token   = (new OAuthAccessToken())->forceFill(['user_uuid' => 'user_a', 'company_uuid' => 'company_a', 'client_id' => 'client_1']);
    $token->setRelation('company', $company)->setRelation('client', $client);

    $request = Request::create('/storefront/oauth/v1/storefronts', $method);
    $request->attributes->set(AuthenticateOAuthToken::ATTRIBUTE, $token);

    return $request;
}

afterEach(function () {
    Model::unsetEventDispatcher();
});

test('an app sees only its organization stores and networks with their public keys', function () {
    createOAuthStorefrontSchema();

    $response = (new StorefrontController())->storefronts(oauthStorefrontRequest());
    $body     = $response->getData(true)['storefronts'];

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(array_column($body, 'id'))->toBe(['store_a', 'store_b', 'network_a'])
        ->and($body[1])->toMatchArray([
            'type'               => 'store',
            'key'                => 'store_b_key',
            'name'               => 'Bakery',
            'online'             => true,
            'pickup_enabled'     => true,
            'review_access_type' => AppReview::STORE_TYPE,
        ])
        ->and($body[0])->toMatchArray(['online' => false, 'pickup_enabled' => false])
        ->and($body[2])->toMatchArray(['type' => 'network', 'key' => 'network_a_key', 'review_access_type' => AppReview::NETWORK_TYPE])
        ->and($body[1])->not->toHaveKey('secret');
});

test('an app gets one live API key in its organization and the same key on later calls', function () {
    createOAuthStorefrontSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    // A key of the same name in another company, an expired one and a test key are never reused.
    $connection->table('api_credentials')->insert([
        ['uuid' => 'other_company', 'company_uuid' => 'company_b', 'name' => 'Storefront app (AppHelm)', 'key' => 'flb_live_other', 'test_mode' => false, 'expires_at' => null],
        ['uuid' => 'expired', 'company_uuid' => 'company_a', 'name' => 'Storefront app (AppHelm)', 'key' => 'flb_live_expired', 'test_mode' => false, 'expires_at' => '2000-01-01 00:00:00'],
        ['uuid' => 'test_key', 'company_uuid' => 'company_a', 'name' => 'Storefront app (AppHelm)', 'key' => 'flb_test_key', 'test_mode' => true, 'expires_at' => null],
    ]);

    $first  = (new StorefrontController())->appKey(oauthStorefrontRequest('POST'));
    $second = (new StorefrontController())->appKey(oauthStorefrontRequest('POST'));
    $key    = $first->getData(true)['api_key'];

    expect($first->getStatusCode())->toBe(201)
        ->and($first->headers->get('Cache-Control'))->toContain('no-store')
        ->and($key)->toMatchArray(['name' => 'Storefront app (AppHelm)', 'created' => true])
        ->and($key['key'])->toStartWith('flb_live_')
        ->and($key['key'])->not->toBe('flb_live_other')
        ->and($second->getStatusCode())->toBe(200)
        ->and($second->getData(true)['api_key'])->toMatchArray(['key' => $key['key'], 'created' => false])
        ->and($connection->table('api_credentials')->where('company_uuid', 'company_a')->where('user_uuid', 'user_a')->count())->toBe(1);
});
