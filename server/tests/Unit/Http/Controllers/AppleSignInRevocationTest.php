<?php

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\Storefront\Http\Controllers\v1\CustomerController;
use Fleetbase\Storefront\Http\Resources\Customer;
use Fleetbase\Storefront\Models\AppleSignInToken;
use Fleetbase\Storefront\Support\AppleSignIn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;

/**
 * Sign in with Apple token revocation (App Review 5.1.1(v)): the refresh token kept at
 * sign-in and revoked with Apple when the customer deletes their account. Apple's token
 * and revoke endpoints are faked.
 */
const APPLE_SIGN_IN_CONTACT_UUID = '8c1d2e3f-4a5b-4c6d-9e7f-0a1b2c3d4e5f';
const APPLE_SIGN_IN_BUNDLE_ID    = 'com.cornerstore.app';

class AppleSignInCustomerControllerStub extends CustomerController
{
    protected function verifyAppleIdentity(string $identityToken): bool
    {
        return true;
    }
}

/**
 * AES-256-CBC, standing in for the framework's encrypter (not installed with the package).
 */
class AppleSignInTestEncrypter implements Illuminate\Contracts\Encryption\Encrypter
{
    private string $key = 'apple-sign-in-test-key-32-bytes!';

    public function encrypt($value, $serialize = true)
    {
        $iv = random_bytes(16);

        return base64_encode($iv . openssl_encrypt($serialize ? serialize($value) : $value, 'aes-256-cbc', $this->key, OPENSSL_RAW_DATA, $iv));
    }

    public function decrypt($payload, $unserialize = true)
    {
        $raw   = base64_decode($payload);
        $value = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', $this->key, OPENSSL_RAW_DATA, substr($raw, 0, 16));

        return $unserialize ? unserialize($value) : $value;
    }

    public function getKey()
    {
        return $this->key;
    }

    public function getAllKeys()
    {
        return [$this->key];
    }

    public function getPreviousKeys()
    {
        return [];
    }
}

function appleSignInDb()
{
    return Model::getConnectionResolver()->connection('mysql');
}

function appleSignInSchema(): void
{
    $schema = appleSignInDb()->getSchemaBuilder();
    foreach (['apple_sign_in_tokens', 'notification_channels', 'networks', 'stores', 'companies', 'users', 'contacts', 'verification_codes', 'personal_access_tokens'] as $table) {
        $schema->dropIfExists($table);
    }
    $schema->create('apple_sign_in_tokens', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('customer_uuid');
        $table->string('user_uuid')->nullable();
        $table->string('apple_user_id')->nullable();
        $table->string('client_id');
        $table->text('refresh_token');
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('notification_channels', function ($table) {
        $table->increments('id');
        foreach (['uuid', 'company_uuid', 'owner_uuid', 'owner_type', 'name', 'scheme', 'app_key', 'config', 'options'] as $column) {
            $table->text($column)->nullable();
        }
        $table->timestamps();
        $table->softDeletes();
    });
    foreach (['stores', 'networks'] as $name) {
        $schema->create($name, function ($table) {
            $table->increments('id');
            foreach (['uuid', 'public_id', 'company_uuid', 'key', 'name', 'options', 'translations'] as $column) {
                $table->text($column)->nullable();
            }
            $table->timestamps();
            $table->softDeletes();
        });
    }
    $schema->create('companies', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->text('options')->nullable();
        $table->softDeletes();
    });
    $schema->create('users', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->string('apple_user_id')->nullable();
        $table->string('type')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('contacts', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('type')->nullable();
        $table->string('name')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('verification_codes', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('code')->nullable();
        $table->string('for')->nullable();
        $table->string('status')->nullable();
        $table->text('meta')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('personal_access_tokens', function ($table) {
        $table->increments('id');
        $table->string('tokenable_type')->nullable();
        $table->string('tokenable_id')->nullable();
        $table->string('name')->nullable();
        $table->string('token')->nullable();
        $table->text('abilities')->nullable();
        $table->timestamp('last_used_at')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
    });

    $db = appleSignInDb();
    $db->table('companies')->insert(['uuid' => 'company_uuid', 'options' => '{}']);
    $db->table('stores')->insert(['uuid' => 'store_uuid', 'public_id' => 'store_public', 'company_uuid' => 'company_uuid', 'key' => 'store_key', 'name' => 'Corner Store']);
    $db->table('networks')->insert(['uuid' => 'network_uuid', 'public_id' => 'network_public', 'company_uuid' => 'company_uuid', 'key' => 'network_key', 'name' => 'Corner Network']);
    // Another company's store, whose keys must never be used for this company's apps.
    $db->table('stores')->insert(['uuid' => 'other_store_uuid', 'public_id' => 'other_store', 'company_uuid' => 'other_company_uuid', 'key' => 'other_key', 'name' => 'Elsewhere']);
    session(['company' => 'company_uuid', 'storefront_key' => 'store_key']);
}

/**
 * Fresh fakes for each test: Apple's endpoints, a log that records, an encrypter for the
 * stored token and an empty cache so client secrets are minted with this test's keys.
 */
function appleSignInFakes(): object
{
    $log = new class extends Psr\Log\AbstractLogger {
        public array $records = [];

        public function log($level, $message, array $context = []): void
        {
            $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
        }

        public function channel(?string $name = null): self
        {
            return $this;
        }

        public function messages(): array
        {
            return array_values(array_filter(array_column($this->records, 'message'), fn ($message) => str_starts_with($message, '[Storefront] Sign in with Apple')));
        }
    };

    app()->instance('log', $log);
    Facade::clearResolvedInstance('log');
    Http::swap(new Illuminate\Http\Client\Factory());
    Model::encryptUsing(new AppleSignInTestEncrypter());
    app('cache')->flush();

    return $log;
}

function appleSignInPrivateKey(): string
{
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    openssl_pkey_export($key, $pem);

    return $pem;
}

function appleSignInChannel(string $scheme, string $keyId, array $config = [], string $owner = 'store_uuid', string $createdAt = '2026-10-01 00:00:00'): void
{
    appleSignInDb()->table('notification_channels')->insert([
        'uuid'         => 'channel_' . $keyId,
        'company_uuid' => 'company_uuid',
        'owner_uuid'   => $owner,
        'owner_type'   => 'storefront:store',
        'name'         => $scheme . ' ' . $keyId,
        'scheme'       => $scheme,
        'config'       => json_encode(array_merge([
            'key_id'              => $keyId,
            'team_id'             => 'TEAM123456',
            'app_bundle_id'       => APPLE_SIGN_IN_BUNDLE_ID,
            'private_key_content' => appleSignInPrivateKey(),
        ], $config)),
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

function appleSignInBase64Url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function appleSignInIdentityToken(string $audience = APPLE_SIGN_IN_BUNDLE_ID, string $subject = '001234.apple.subject'): string
{
    return implode('.', [
        appleSignInBase64Url(json_encode(['alg' => 'RS256', 'kid' => 'apple-key'])),
        appleSignInBase64Url(json_encode(['iss' => 'https://appleid.apple.com', 'aud' => $audience, 'sub' => $subject, 'iat' => time(), 'exp' => time() + 600])),
        appleSignInBase64Url('signature'),
    ]);
}

/**
 * The header and claims of the client secret a request to Apple carried.
 */
function appleSignInClientSecret(ClientRequest $request): array
{
    [$header, $claims] = array_map(fn ($part) => json_decode(base64_decode(strtr($part, '-_', '+/')), true), array_slice(explode('.', $request['client_secret']), 0, 2));

    return ['kid' => $header['kid'] ?? null, 'alg' => $header['alg'] ?? null] + $claims;
}

function appleSignInSignedInCustomer(): Contact
{
    $db = appleSignInDb();
    $db->table('users')->insert(['uuid' => 'user_uuid', 'company_uuid' => 'company_uuid', 'name' => 'Ada Buyer', 'email' => 'ada@privaterelay.appleid.com', 'phone' => '+97699000001', 'apple_user_id' => '001234.apple.subject', 'type' => 'customer', 'created_at' => now(), 'updated_at' => now()]);
    $db->table('contacts')->insert(['uuid' => APPLE_SIGN_IN_CONTACT_UUID, 'public_id' => 'contact_ada', 'company_uuid' => 'company_uuid', 'user_uuid' => 'user_uuid', 'type' => 'customer', 'name' => 'Ada Buyer', 'email' => 'ada@privaterelay.appleid.com', 'created_at' => now(), 'updated_at' => now()]);
    $db->table('personal_access_tokens')->insert(['name' => APPLE_SIGN_IN_CONTACT_UUID, 'token' => hash('sha256', 'apple-sign-in-secret'), 'abilities' => '["*"]', 'created_at' => now(), 'updated_at' => now()]);

    return Contact::where('uuid', APPLE_SIGN_IN_CONTACT_UUID)->first();
}

function appleSignInKeptToken(string $customerUuid, string $refreshToken, string $clientId = APPLE_SIGN_IN_BUNDLE_ID, string $companyUuid = 'company_uuid'): void
{
    $token = new AppleSignInToken();
    $token->forceFill(['uuid' => 'token_' . $refreshToken, 'company_uuid' => $companyUuid, 'customer_uuid' => $customerUuid, 'user_uuid' => 'user_uuid', 'client_id' => $clientId, 'refresh_token' => $refreshToken]);
    $token->save();
}

/**
 * Confirm the signed-in customer's account closure with the review bypass code.
 */
function appleSignInCloseAccount()
{
    config([
        'storefront.storefront_app.bypass_verification_code' => '246810',
        'storefront.storefront_app.review_accounts'          => ['+97699000001'],
    ]);
    $request = Request::create('/storefront/v1/customers/confirm-account-closure', 'POST', ['code' => '246810']);
    $request->headers->set('Customer-Token', 'apple-sign-in-secret');
    $request->setLaravelSession(new Illuminate\Session\Store('apple-sign-in', new Illuminate\Session\ArraySessionHandler(120)));
    app()->instance('request', $request);

    return (new CustomerController())->confirmAccountClosure($request);
}

test('Apple sign-in keeps the refresh token Apple issues for the authorization code, encrypted', function () {
    appleSignInSchema();
    $log = appleSignInFakes();
    appleSignInChannel(AppleSignIn::SCHEME, 'SIWAKEY001');
    $db = appleSignInDb();
    $db->table('users')->insert(['uuid' => 'user_uuid', 'company_uuid' => 'company_uuid', 'name' => 'Ada Buyer', 'email' => 'ada@privaterelay.appleid.com', 'type' => 'customer']);
    $db->table('contacts')->insert(['uuid' => APPLE_SIGN_IN_CONTACT_UUID, 'public_id' => 'contact_ada', 'company_uuid' => 'company_uuid', 'user_uuid' => 'user_uuid', 'name' => 'Ada Buyer', 'type' => 'customer']);
    Http::fake([AppleSignIn::TOKEN_URL => Http::response(['access_token' => 'access', 'token_type' => 'Bearer', 'refresh_token' => 'refresh-123', 'id_token' => 'id'])]);

    $response = (new AppleSignInCustomerControllerStub())->loginWithApple(Request::create('/storefront/v1/customers/login-with-apple', 'POST', [
        'identityToken'     => appleSignInIdentityToken(),
        'authorizationCode' => 'one-time-code',
        'email'             => 'ada@privaterelay.appleid.com',
        'appleUserId'       => '001234.apple.subject',
    ]));

    $kept   = AppleSignInToken::first();
    $stored = $db->table('apple_sign_in_tokens')->value('refresh_token');
    $sent   = Http::recorded()->map(fn ($pair) => $pair[0]);

    expect($response)->toBeInstanceOf(Customer::class)
        ->and($kept->customer_uuid)->toBe(APPLE_SIGN_IN_CONTACT_UUID)
        ->and($kept->user_uuid)->toBe('user_uuid')
        ->and($kept->company_uuid)->toBe('company_uuid')
        ->and($kept->client_id)->toBe(APPLE_SIGN_IN_BUNDLE_ID)
        ->and($kept->apple_user_id)->toBe('001234.apple.subject')
        ->and($kept->refresh_token)->toBe('refresh-123')
        ->and($stored)->not->toContain('refresh-123')
        ->and($kept->toArray())->not->toHaveKey('refresh_token')
        ->and($sent)->toHaveCount(1)
        ->and($sent[0]->url())->toBe(AppleSignIn::TOKEN_URL)
        ->and($sent[0]->isForm())->toBeTrue()
        ->and($sent[0]['client_id'])->toBe(APPLE_SIGN_IN_BUNDLE_ID)
        ->and($sent[0]['code'])->toBe('one-time-code')
        ->and($sent[0]['grant_type'])->toBe('authorization_code')
        ->and(appleSignInClientSecret($sent[0]))->toMatchArray([
            'kid' => 'SIWAKEY001',
            'alg' => 'ES256',
            'iss' => 'TEAM123456',
            'sub' => APPLE_SIGN_IN_BUNDLE_ID,
            'aud' => 'https://appleid.apple.com',
        ])
        ->and(implode(' ', $log->messages()))->not->toContain('not kept');

    // A later sign-in replaces the kept token rather than adding another.
    appleSignInFakes();
    Http::fake([AppleSignIn::TOKEN_URL => Http::response(['refresh_token' => 'refresh-456'])]);
    app(AppleSignIn::class)->remember(Contact::where('uuid', APPLE_SIGN_IN_CONTACT_UUID)->first(), appleSignInIdentityToken(), 'second-code');

    expect(AppleSignInToken::count())->toBe(1)
        ->and(AppleSignInToken::first()->refresh_token)->toBe('refresh-456');
});

test('the push key signs for the app when it has Sign in with Apple, and a key Apple rejects falls through to the next', function () {
    appleSignInSchema();
    appleSignInFakes();
    // Tried first: a Sign in with Apple key, which Apple turns away as from the wrong team.
    appleSignInChannel(AppleSignIn::SCHEME, 'WRONGTEAM1', ['team_id' => 'OTHERTEAM1'], 'store_uuid', '2026-10-03 00:00:00');
    // The network's push key, which also has Sign in with Apple enabled.
    appleSignInChannel('apn', 'PUSHKEY001', [], 'network_uuid', '2026-10-01 00:00:00');
    // Never used: a push key for another app, an FCM channel and another company's key.
    appleSignInChannel('apn', 'OTHERAPP01', ['app_bundle_id' => 'com.other.app']);
    appleSignInChannel('fcm', 'FCMKEY0001');
    appleSignInChannel(AppleSignIn::SCHEME, 'ELSEWHERE1', [], 'other_store_uuid');
    // Incomplete: no private key.
    appleSignInChannel(AppleSignIn::SCHEME, 'NOPRIVKEY1', ['private_key_content' => '']);

    Http::fake(function (ClientRequest $request) {
        return appleSignInClientSecret($request)['kid'] === 'PUSHKEY001'
            ? Http::response(['refresh_token' => 'refresh-from-push-key'])
            : Http::response(['error' => 'invalid_client'], 400);
    });

    $customer = new Contact();
    $customer->forceFill(['uuid' => APPLE_SIGN_IN_CONTACT_UUID, 'company_uuid' => 'company_uuid', 'user_uuid' => 'user_uuid']);
    $kept = app(AppleSignIn::class)->remember($customer, appleSignInIdentityToken(), 'one-time-code');

    expect($kept)->toBeTrue()
        ->and(Http::recorded()->map(fn ($pair) => appleSignInClientSecret($pair[0])['kid'])->all())->toBe(['WRONGTEAM1', 'PUSHKEY001'])
        ->and(AppleSignInToken::first()->refresh_token)->toBe('refresh-from-push-key');
});

test('sign-in does not call Apple without a key, and keeps nothing when Apple refuses the code', function () {
    appleSignInSchema();
    $log      = appleSignInFakes();
    Http::fake([AppleSignIn::TOKEN_URL => Http::response(['error' => 'invalid_grant', 'error_description' => 'The code has expired or has been revoked.'], 400)]);
    $customer = new Contact();
    $customer->forceFill(['uuid' => APPLE_SIGN_IN_CONTACT_UUID, 'company_uuid' => 'company_uuid', 'user_uuid' => 'user_uuid']);
    $signIn   = app(AppleSignIn::class);

    $withoutKey = $signIn->remember($customer, appleSignInIdentityToken(), 'one-time-code');
    $calls      = Http::recorded()->count();

    appleSignInChannel(AppleSignIn::SCHEME, 'SIWAKEY001');
    $refused        = $signIn->remember($customer, appleSignInIdentityToken(), 'used-code');
    $noAudience     = $signIn->remember($customer, 'not-a-jwt', 'one-time-code');
    $otherAppsToken = $signIn->remember($customer, appleSignInIdentityToken('com.other.app'), 'one-time-code');

    expect($withoutKey)->toBeFalse()
        ->and($calls)->toBe(0)
        ->and($refused)->toBeFalse()
        ->and($noAudience)->toBeFalse()
        ->and($otherAppsToken)->toBeFalse()
        ->and(Http::recorded()->count())->toBe(1)
        ->and(AppleSignInToken::count())->toBe(0)
        ->and($log->messages())->toBe([
            '[Storefront] Sign in with Apple token not kept: no Sign in with Apple key is configured for the app.',
            '[Storefront] Sign in with Apple token not kept: Apple refused the authorization code.',
            '[Storefront] Sign in with Apple token not kept: the identity token names no app.',
            '[Storefront] Sign in with Apple token not kept: no Sign in with Apple key is configured for the app.',
        ])
        ->and($log->records[1]['context'])->toMatchArray(['status' => 400, 'error' => 'invalid_grant', 'client_id' => APPLE_SIGN_IN_BUNDLE_ID]);
});

test('Apple sign-in still signs the customer in when keeping the token fails', function () {
    appleSignInSchema();
    $log = appleSignInFakes();
    appleSignInChannel(AppleSignIn::SCHEME, 'SIWAKEY001');
    $db = appleSignInDb();
    $db->table('users')->insert(['uuid' => 'user_uuid', 'company_uuid' => 'company_uuid', 'name' => 'Ada Buyer', 'email' => 'ada@privaterelay.appleid.com', 'type' => 'customer']);
    $db->table('contacts')->insert(['uuid' => APPLE_SIGN_IN_CONTACT_UUID, 'public_id' => 'contact_ada', 'company_uuid' => 'company_uuid', 'user_uuid' => 'user_uuid', 'name' => 'Ada Buyer', 'type' => 'customer']);
    Http::fake(fn () => throw new Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out'));

    $response = (new AppleSignInCustomerControllerStub())->loginWithApple(Request::create('/storefront/v1/customers/login-with-apple', 'POST', [
        'identityToken'     => appleSignInIdentityToken(),
        'authorizationCode' => 'one-time-code',
        'email'             => 'ada@privaterelay.appleid.com',
        'appleUserId'       => '001234.apple.subject',
    ]));

    expect($response)->toBeInstanceOf(Customer::class)
        ->and(AppleSignInToken::count())->toBe(0)
        ->and($log->messages())->toBe(['[Storefront] Sign in with Apple token not kept.'])
        ->and($log->records[0]['level'])->toBe('error');
});

test('account closure revokes the customer\'s Apple tokens with Apple before the account is deleted', function () {
    appleSignInSchema();
    $log = appleSignInFakes();
    appleSignInChannel(AppleSignIn::SCHEME, 'SIWAKEY001');
    appleSignInSignedInCustomer();
    appleSignInKeptToken(APPLE_SIGN_IN_CONTACT_UUID, 'refresh-123');
    // The same user's customer in the network app; its user goes too, so its token is revoked.
    appleSignInKeptToken('network_contact_uuid', 'refresh-network');
    // Somebody else's token stays.
    appleSignInKeptToken('someone_else', 'refresh-other');
    appleSignInDb()->table('apple_sign_in_tokens')->where('customer_uuid', 'someone_else')->update(['user_uuid' => 'another_user']);
    Http::fake([AppleSignIn::REVOKE_URL => Http::response('', 200)]);

    $response = appleSignInCloseAccount();
    $sent     = Http::recorded()->map(fn ($pair) => $pair[0]);

    expect($response->getData(true))->toBe(['status' => 'OK'])
        ->and($sent)->toHaveCount(2)
        ->and($sent->map(fn ($request) => $request->url())->unique()->all())->toBe([AppleSignIn::REVOKE_URL])
        ->and($sent->map(fn ($request) => $request['token'])->sort()->values()->all())->toBe(['refresh-123', 'refresh-network'])
        ->and($sent[0]['token_type_hint'])->toBe('refresh_token')
        ->and($sent[0]['client_id'])->toBe(APPLE_SIGN_IN_BUNDLE_ID)
        ->and(appleSignInClientSecret($sent[0])['kid'])->toBe('SIWAKEY001')
        ->and(AppleSignInToken::withTrashed()->pluck('customer_uuid')->all())->toBe(['someone_else'])
        ->and(appleSignInDb()->table('contacts')->where('uuid', APPLE_SIGN_IN_CONTACT_UUID)->value('deleted_at'))->not->toBeNull()
        ->and(appleSignInDb()->table('users')->where('uuid', 'user_uuid')->value('deleted_at'))->not->toBeNull()
        ->and(array_unique($log->messages()))->toBe(['[Storefront] Sign in with Apple token revoked.']);
});

test('account closure still deletes the account when Apple refuses, fails or no key is configured', function () {
    appleSignInSchema();
    $log = appleSignInFakes();
    appleSignInChannel(AppleSignIn::SCHEME, 'SIWAKEY001');
    appleSignInChannel(AppleSignIn::SCHEME, 'TIMEOUTKEY', ['app_bundle_id' => 'com.cornerstore.timeout']);
    appleSignInSignedInCustomer();
    appleSignInKeptToken(APPLE_SIGN_IN_CONTACT_UUID, 'refresh-refused');
    appleSignInKeptToken(APPLE_SIGN_IN_CONTACT_UUID, 'refresh-timeout', 'com.cornerstore.timeout');
    appleSignInKeptToken(APPLE_SIGN_IN_CONTACT_UUID, 'refresh-no-key', 'com.cornerstore.unconfigured');
    Http::fake(function (ClientRequest $request) {
        if ($request['client_id'] === 'com.cornerstore.timeout') {
            throw new Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out');
        }

        return Http::response(['error' => 'invalid_request'], 400);
    });

    $response = appleSignInCloseAccount();

    // The request that timed out never got an answer to record.
    expect($response->getData(true))->toBe(['status' => 'OK'])
        ->and(Http::recorded()->map(fn ($pair) => $pair[0]['token'])->all())->toBe(['refresh-refused'])
        ->and(AppleSignInToken::withTrashed()->count())->toBe(0)
        ->and(appleSignInDb()->table('contacts')->where('uuid', APPLE_SIGN_IN_CONTACT_UUID)->value('deleted_at'))->not->toBeNull()
        ->and(appleSignInDb()->table('users')->where('uuid', 'user_uuid')->value('deleted_at'))->not->toBeNull()
        ->and($log->messages())->toEqualCanonicalizing([
            '[Storefront] Sign in with Apple token not revoked: Apple refused it.',
            '[Storefront] Sign in with Apple token not revoked.',
            '[Storefront] Sign in with Apple token not revoked: no Sign in with Apple key is configured for the app.',
        ])
        ->and(collect($log->records)->firstWhere('message', '[Storefront] Sign in with Apple token not revoked: Apple refused it.')['context'])->toMatchArray(['status' => 400, 'error' => 'invalid_request'])
        ->and(json_encode($log->records))->not->toContain('refresh-')
        ->and(collect($log->records)->firstWhere('message', '[Storefront] Sign in with Apple token not revoked.')['level'])->toBe('error');
});

test('account closure goes ahead when the token store cannot be read', function () {
    appleSignInSchema();
    $log = appleSignInFakes();
    appleSignInSignedInCustomer();
    appleSignInDb()->getSchemaBuilder()->drop('apple_sign_in_tokens');
    Http::fake();

    $response = appleSignInCloseAccount();

    expect($response->getData(true))->toBe(['status' => 'OK'])
        ->and(Http::recorded())->toHaveCount(0)
        ->and($log->messages())->toBe(['[Storefront] Sign in with Apple tokens could not be looked up for revocation.'])
        ->and(appleSignInDb()->table('contacts')->where('uuid', APPLE_SIGN_IN_CONTACT_UUID)->value('deleted_at'))->not->toBeNull();
});
