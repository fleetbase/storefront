<?php

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Models\ReviewAccess;
use Fleetbase\Models\User;
use Fleetbase\Storefront\Auth\Schemas\Storefront as StorefrontSchema;
use Fleetbase\Storefront\Http\Controllers\v1\CustomerController;
use Fleetbase\Storefront\Http\Requests\CreateCustomerRequest;
use Fleetbase\Storefront\Http\Requests\VerifyCreateCustomerRequest;
use Fleetbase\Storefront\Http\Resources\Customer;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Support\AppReview;
use Fleetbase\Support\AppReview as CoreAppReview;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store as SessionStore;
use Illuminate\Support\Str;

/**
 * Two stores in two companies and a network, the customer tables, and an SMS provider that
 * records what it is asked to send. Models get their uuids from `creating` listeners, the
 * way the app's HasUuid would.
 */
function createAppReviewSchema(): object
{
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    foreach (['review_accesses', 'personal_access_tokens', 'verification_codes', 'contacts', 'users', 'stores', 'networks', 'companies'] as $table) {
        $schema->dropIfExists($table);
    }
    foreach (['stores', 'networks'] as $table) {
        $schema->create($table, function ($blueprint) {
            $blueprint->increments('id');
            foreach ([
                'uuid', 'public_id', 'company_uuid', 'backdrop_uuid', 'logo_uuid',
                'order_config_uuid', 'key', 'name', 'description', 'translations',
                'website', 'facebook', 'instagram', 'twitter', 'email', 'phone',
                'tags', 'currency', 'timezone', 'pod_method', 'options', 'slug',
            ] as $column) {
                $blueprint->text($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->softDeletes();
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
        $table->timestamp('phone_verified_at')->nullable();
        $table->string('password')->nullable();
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
        $table->string('name')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->string('type')->nullable();
        $table->text('meta')->nullable();
        $table->string('photo_uuid')->nullable();
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
        $table->string('tokenable_type');
        $table->string('tokenable_id');
        $table->string('name');
        $table->string('token', 64)->unique();
        $table->text('abilities')->nullable();
        $table->timestamp('last_used_at')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
    });
    // core-api's review_accesses table.
    $schema->create('review_accesses', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('subject_type');
        $table->string('subject_uuid');
        $table->unique(['subject_type', 'subject_uuid']);
        $table->boolean('enabled')->default(false);
        $table->string('code')->nullable();
        $table->timestamp('code_generated_at')->nullable();
        $table->timestamp('enabled_at')->nullable();
        $table->string('updated_by_uuid')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    $connection->table('companies')->insert([
        ['uuid' => 'company_a', 'options' => '{}'],
        ['uuid' => 'company_b', 'options' => '{}'],
    ]);
    $connection->table('stores')->insert([
        ['uuid' => 'store_a_uuid', 'public_id' => 'store_a', 'company_uuid' => 'company_a', 'key' => 'store_a_key', 'name' => 'Corner Store'],
        ['uuid' => 'store_b_uuid', 'public_id' => 'store_b', 'company_uuid' => 'company_b', 'key' => 'store_b_key', 'name' => 'Night Bakery'],
    ]);
    $connection->table('networks')->insert([
        ['uuid' => 'network_uuid', 'public_id' => 'network_public', 'company_uuid' => 'company_a', 'key' => 'network_key', 'name' => 'Night Market'],
    ]);

    // Boot the models without a dispatcher, so their traits (activity log and the like) register
    // nothing, then listen only for what the tests need.
    Model::unsetEventDispatcher();
    Model::clearBootedModels();
    foreach ([User::class, Contact::class, ReviewAccess::class, Store::class, Network::class] as $class) {
        new $class();
    }
    Model::setEventDispatcher(new Illuminate\Events\Dispatcher(app()));
    User::creating(fn ($user) => $user->uuid ??= (string) Str::uuid());
    Contact::creating(fn ($contact) => $contact->uuid ??= (string) Str::uuid());
    ReviewAccess::creating(fn ($access) => $access->uuid ??= (string) Str::uuid());
    app()->instance('cache', new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore()));

    // What StorefrontServiceProvider does on boot.
    AppReview::register();

    // Anything that would deliver a code is recorded, so a test can prove nothing went out.
    $sms = new class {
        public array $sent = [];

        public function message(string $to, string $message, array $media = [], array $params = []): object
        {
            $this->sent[] = $to;

            return (object) ['sid' => 'sms_test'];
        }
    };
    app()->instance('twilio', $sms);
    app()->instance('responsecache', new class {
        public function clear(): void
        {
        }
    });
    Illuminate\Support\Facades\Facade::clearResolvedInstance('twilio');

    config([
        'storefront.storefront_app.bypass_verification_code' => null,
        'storefront.storefront_app.review_accounts'          => [],
    ]);

    return $sms;
}

/**
 * A storefront app request for the given store key.
 */
function appReviewRequest(string $storefrontKey, array $input = [], string $class = Request::class, ?string $customerToken = null): Request
{
    $request = $class::create('/storefront/v1/customers', 'POST', $input);
    $request->setLaravelSession(new SessionStore('app-review-test', new ArraySessionHandler(120)));
    if ($customerToken) {
        $request->headers->set('Customer-Token', $customerToken);
    }
    app()->instance('request', $request);

    $company = Model::getConnectionResolver()->connection('mysql')->table(str_starts_with($storefrontKey, 'network') ? 'networks' : 'stores')->where('key', $storefrontKey)->value('company_uuid');
    session(['storefront_key' => $storefrontKey, 'company' => $company]);

    return $request;
}

function turnOnAppReview(string $storeUuid): string
{
    [, $code] = CoreAppReview::update(Store::where('uuid', $storeUuid)->first(), true);

    return $code;
}

function appReviewUserCount(): int
{
    return Model::getConnectionResolver()->connection('mysql')->table('users')->where('phone', AppReview::PHONE)->whereNull('deleted_at')->count();
}

afterEach(function () {
    Model::unsetEventDispatcher();
    config([
        'storefront.storefront_app.bypass_verification_code' => null,
        'storefront.storefront_app.review_accounts'          => [],
    ]);
});

test('the review number is a fictional number that the phone check accepts', function () {
    $util   = libphonenumber\PhoneNumberUtil::getInstance();
    $parsed = $util->parse(AppReview::PHONE, null);

    expect(AppReview::PHONE)->toMatch('/^\+1\d{3}55501\d{2}$/')
        ->and($util->isValidNumber($parsed))->toBeTrue()
        ->and($util->getRegionCodeForNumber($parsed))->toBe('US')
        // What the app accepts before it sends a number (src/utils isValidPhoneNumber).
        ->and(preg_match('/^\+\d{8,15}$/', AppReview::PHONE))->toBe(1)
        ->and(AppReview::isReviewPhone('+1 (202) 555-0143'))->toBeTrue()
        ->and(AppReview::isReviewPhone('12025550143'))->toBeTrue()
        ->and(AppReview::isReviewPhone('+12025550144'))->toBeFalse()
        ->and(AppReview::isReviewPhone(null))->toBeFalse();
});

test('with App Review access on, the review number signs in with the store code and is never sent anything', function () {
    $sms  = createAppReviewSchema();
    $code = turnOnAppReview('store_a_uuid');

    $sent = (new CustomerController())->loginWithPhone(appReviewRequest('store_a_key', ['phone' => AppReview::PHONE]));
    expect($sent->getData(true))->toBe(['status' => 'OK', 'method' => 'sms']);

    $customer = (new CustomerController())->verifyCode(appReviewRequest('store_a_key', ['identity' => AppReview::PHONE, 'code' => $code]));

    expect($customer)->toBeInstanceOf(Customer::class)
        ->and($customer->resource->company_uuid)->toBe('company_a')
        ->and($customer->resource->token)->not->toBeEmpty()
        ->and(appReviewUserCount())->toBe(1)
        ->and(User::where('phone', AppReview::PHONE)->first()->phone_verified_at)->not->toBeNull()
        ->and(Model::getConnectionResolver()->connection('mysql')->table('verification_codes')->count())->toBe(0)
        ->and($sms->sent)->toBe([]);

    // Signing in again reuses the account and the store's customer.
    (new CustomerController())->verifyCode(appReviewRequest('store_a_key', ['identity' => AppReview::PHONE, 'code' => $code]));
    expect(appReviewUserCount())->toBe(1)
        ->and(Contact::where('company_uuid', 'company_a')->count())->toBe(1);
});

test('with App Review access off, the review number is refused and not texted', function () {
    $sms = createAppReviewSchema();

    $sent = (new CustomerController())->loginWithPhone(appReviewRequest('store_a_key', ['phone' => AppReview::PHONE]));
    expect($sent->getStatusCode())->toBe(422)
        ->and($sent->getData(true))->toBe(['error' => AppReview::OFF_MESSAGE]);

    $creation = (new CustomerController())->requestCustomerCreationCode(appReviewRequest('store_a_key', ['mode' => 'sms', 'identity' => AppReview::PHONE], VerifyCreateCustomerRequest::class));
    expect($creation->getStatusCode())->toBe(422);

    // A store that turned it on and then off: its code stops working.
    $code = turnOnAppReview('store_a_uuid');
    CoreAppReview::update(Store::where('uuid', 'store_a_uuid')->first(), false);

    $refused = (new CustomerController())->verifyCode(appReviewRequest('store_a_key', ['identity' => AppReview::PHONE, 'code' => $code]));

    expect($refused->getData(true))->toBe(['error' => 'Unable to verify code.'])
        ->and(appReviewUserCount())->toBe(0)
        ->and($sms->sent)->toBe([]);
});

test('a wrong code does not sign the review number in', function () {
    createAppReviewSchema();
    $code  = turnOnAppReview('store_a_uuid');
    $wrong = $code === '000000' ? '000001' : '000000';

    $refused = (new CustomerController())->verifyCode(appReviewRequest('store_a_key', ['identity' => AppReview::PHONE, 'code' => $wrong]));
    expect($refused->getData(true))->toBe(['error' => 'Unable to verify code.'])
        ->and(appReviewUserCount())->toBe(0);

    // Once the account exists (signed in properly), a wrong code is still refused.
    (new CustomerController())->verifyCode(appReviewRequest('store_a_key', ['identity' => AppReview::PHONE, 'code' => $code]));
    $refusedAgain = (new CustomerController())->verifyCode(appReviewRequest('store_a_key', ['identity' => AppReview::PHONE, 'code' => $wrong]));
    expect($refusedAgain->getData(true))->toBe(['error' => 'Invalid verification code!']);
});

test('one store code does not sign the review number in to another store', function () {
    createAppReviewSchema();
    $codeA = turnOnAppReview('store_a_uuid');
    do {
        $codeB = turnOnAppReview('store_b_uuid');
    } while ($codeB === $codeA);

    $crossA = (new CustomerController())->verifyCode(appReviewRequest('store_a_key', ['identity' => AppReview::PHONE, 'code' => $codeB]));
    expect($crossA->getData(true))->toBe(['error' => 'Unable to verify code.']);

    $customerB = (new CustomerController())->verifyCode(appReviewRequest('store_b_key', ['identity' => AppReview::PHONE, 'code' => $codeB]));
    expect($customerB)->toBeInstanceOf(Customer::class)
        ->and($customerB->resource->company_uuid)->toBe('company_b');

    // Store A's code still only works at store A, now that the shared account exists.
    $crossB = (new CustomerController())->verifyCode(appReviewRequest('store_b_key', ['identity' => AppReview::PHONE, 'code' => $codeA]));
    expect($crossB->getData(true))->toBe(['error' => 'Invalid verification code!']);

    $customerA = (new CustomerController())->verifyCode(appReviewRequest('store_a_key', ['identity' => AppReview::PHONE, 'code' => $codeA]));
    expect($customerA)->toBeInstanceOf(Customer::class)
        ->and($customerA->resource->company_uuid)->toBe('company_a')
        ->and(appReviewUserCount())->toBe(1);
});

test('a network has its own App Review access', function () {
    createAppReviewSchema();
    [, $code] = CoreAppReview::update(Network::where('uuid', 'network_uuid')->first(), true);

    $customer = (new CustomerController())->verifyCode(appReviewRequest('network_key', ['identity' => AppReview::PHONE, 'code' => $code]));
    $atStore  = (new CustomerController())->verifyCode(appReviewRequest('store_a_key', ['identity' => AppReview::PHONE, 'code' => $code]));

    expect($customer)->toBeInstanceOf(Customer::class)
        ->and($atStore->getData(true))->toBe(['error' => 'Invalid verification code!']);
});

test('the instance-wide review accounts still work where the store has not turned access on', function () {
    $sms = createAppReviewSchema();
    config([
        'storefront.storefront_app.bypass_verification_code' => '135790',
        'storefront.storefront_app.review_accounts'          => [AppReview::PHONE, '+97699112233'],
    ]);
    Model::getConnectionResolver()->connection('mysql')->table('users')->insert([
        'uuid' => 'self_hosted_reviewer', 'phone' => '+97699112233', 'type' => 'customer', 'created_at' => now(), 'updated_at' => now(),
    ]);

    // The review number itself, through the env fallback: the account already exists here.
    Model::getConnectionResolver()->connection('mysql')->table('users')->insert([
        'uuid' => 'review_number_user', 'phone' => AppReview::PHONE, 'type' => 'customer', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $sent    = (new CustomerController())->loginWithPhone(appReviewRequest('store_a_key', ['phone' => AppReview::PHONE]));
    $viaEnv  = (new CustomerController())->verifyCode(appReviewRequest('store_a_key', ['identity' => AppReview::PHONE, 'code' => '135790']));
    $listed  = (new CustomerController())->loginWithPhone(appReviewRequest('store_a_key', ['phone' => '+97699112233']));
    $viaList = (new CustomerController())->verifyCode(appReviewRequest('store_a_key', ['identity' => '+97699112233', 'code' => '135790']));
    $wrong   = (new CustomerController())->verifyCode(appReviewRequest('store_a_key', ['identity' => '+97699112233', 'code' => '135791']));

    expect($sent->getData(true))->toBe(['status' => 'OK', 'method' => 'sms'])
        ->and($viaEnv)->toBeInstanceOf(Customer::class)
        ->and($listed->getData(true))->toBe(['status' => 'OK', 'method' => 'sms'])
        ->and($viaList)->toBeInstanceOf(Customer::class)
        ->and($wrong->getData(true))->toBe(['error' => 'Invalid verification code!'])
        ->and($sms->sent)->toBe([]);
});

test('signing up as the review number uses the store code and joins the shared account', function () {
    $sms  = createAppReviewSchema();
    $code = turnOnAppReview('store_a_uuid');

    $requested = (new CustomerController())->requestCustomerCreationCode(appReviewRequest('store_a_key', ['mode' => 'sms', 'identity' => AppReview::PHONE], VerifyCreateCustomerRequest::class));
    expect($requested->getData(true))->toBe(['status' => 'ok']);

    $wrong = (new CustomerController())->create(appReviewRequest('store_a_key', ['identity' => AppReview::PHONE, 'name' => 'Reviewer', 'code' => '12'], CreateCustomerRequest::class));
    expect($wrong->getData(true))->toBe(['error' => 'Invalid verification code provided!']);

    $created = (new CustomerController())->create(appReviewRequest('store_a_key', ['identity' => AppReview::PHONE, 'name' => 'Reviewer', 'code' => $code], CreateCustomerRequest::class));

    expect($created)->toBeInstanceOf(Customer::class)
        ->and($created->resource->phone)->toBe(AppReview::PHONE)
        ->and(appReviewUserCount())->toBe(1)
        ->and(Model::getConnectionResolver()->connection('mysql')->table('verification_codes')->count())->toBe(0)
        ->and($sms->sent)->toBe([]);

    // The account exists now; signing up from another store with access on still works.
    $codeB     = turnOnAppReview('store_b_uuid');
    $requested = (new CustomerController())->requestCustomerCreationCode(appReviewRequest('store_b_key', ['mode' => 'sms', 'identity' => AppReview::PHONE], VerifyCreateCustomerRequest::class));
    $createdB  = (new CustomerController())->create(appReviewRequest('store_b_key', ['identity' => AppReview::PHONE, 'name' => 'Reviewer', 'code' => $codeB], CreateCustomerRequest::class));

    expect($requested->getData(true))->toBe(['status' => 'ok'])
        ->and($createdB)->toBeInstanceOf(Customer::class)
        ->and($createdB->resource->company_uuid)->toBe('company_b')
        ->and(appReviewUserCount())->toBe(1);
});

test('closing the review account removes only this store customer and keeps the shared account', function () {
    $sms  = createAppReviewSchema();
    $code = turnOnAppReview('store_a_uuid');

    $customer = (new CustomerController())->verifyCode(appReviewRequest('store_a_key', ['identity' => AppReview::PHONE, 'code' => $code]));
    $token    = $customer->resource->token;

    $started = (new CustomerController())->startAccountClosure(appReviewRequest('store_a_key', [], Request::class, $token));
    $wrong   = (new CustomerController())->confirmAccountClosure(appReviewRequest('store_a_key', ['code' => 'nope'], Request::class, $token));
    $closed  = (new CustomerController())->confirmAccountClosure(appReviewRequest('store_a_key', ['code' => $code], Request::class, $token));

    expect($started->getData(true))->toBe(['status' => 'OK'])
        ->and($wrong->getData(true))->toBe(['error' => 'Invalid verification code provided!'])
        ->and($closed->getData(true))->toBe(['status' => 'OK'])
        ->and(Contact::where('company_uuid', 'company_a')->count())->toBe(0)
        ->and(appReviewUserCount())->toBe(1)
        ->and($sms->sent)->toBe([]);
});

test('a reviewer signed in another way can add the review number with the store code', function () {
    createAppReviewSchema();
    $code       = turnOnAppReview('store_a_uuid');
    $connection = Model::getConnectionResolver()->connection('mysql');
    // The shared review account already holds the number.
    $connection->table('users')->insert(['uuid' => 'review_number_user', 'phone' => AppReview::PHONE, 'type' => 'customer', 'created_at' => now(), 'updated_at' => now()]);
    $connection->table('users')->insert(['uuid' => 'apple_reviewer', 'email' => 'reviewer@privaterelay.example', 'type' => 'customer', 'created_at' => now(), 'updated_at' => now()]);
    $connection->table('contacts')->insert(['uuid' => '0b7c4ad2-55a1-4b0c-8f44-1e2d3c4b5a69', 'company_uuid' => 'company_a', 'user_uuid' => 'apple_reviewer', 'type' => 'customer', 'created_at' => now(), 'updated_at' => now()]);
    $connection->table('personal_access_tokens')->insert([
        'tokenable_type' => User::class, 'tokenable_id' => 'apple_reviewer', 'name' => '0b7c4ad2-55a1-4b0c-8f44-1e2d3c4b5a69',
        'token'          => hash('sha256', 'apple-reviewer-secret'), 'abilities' => '["*"]', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $requested = (new CustomerController())->requestPhoneVerification(appReviewRequest('store_a_key', ['phone' => AppReview::PHONE], Request::class, 'apple-reviewer-secret'));
    $verified  = (new CustomerController())->verifyPhoneNumber(appReviewRequest('store_a_key', ['phone' => AppReview::PHONE, 'code' => $code], Request::class, 'apple-reviewer-secret'));

    expect($requested->getData(true))->toBe(['status' => 'ok', 'method' => 'bypass'])
        ->and($verified)->toBeInstanceOf(Customer::class)
        ->and($connection->table('users')->where('uuid', 'apple_reviewer')->value('phone'))->toBe(AppReview::PHONE);
});

test('orders placed by the review account are marked and never auto-dispatched', function () {
    createAppReviewSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('users')->insert(['uuid' => 'review_number_user', 'phone' => AppReview::PHONE, 'type' => 'customer']);

    $reviewer                   = new Contact(['phone' => AppReview::PHONE]);
    $signedInByApple            = new Contact(['email' => 'reviewer@privaterelay.example']);
    $signedInByApple->user_uuid = 'review_number_user';
    $customer                   = new Contact(['phone' => '+97699112233']);

    expect(AppReview::markOrderMeta(['total' => 100], $reviewer))->toBe(['total' => 100, 'app_review' => true])
        ->and(AppReview::markOrderMeta(['total' => 100], $signedInByApple))->toBe(['total' => 100, 'app_review' => true])
        ->and(AppReview::markOrderMeta(['total' => 100], $customer))->toBe(['total' => 100])
        ->and(AppReview::markOrderMeta(['total' => 100], null))->toBe(['total' => 100]);

    // A self-hosted review account is a reviewer too.
    config([
        'storefront.storefront_app.bypass_verification_code' => '135790',
        'storefront.storefront_app.review_accounts'          => ['+97699112233'],
    ]);
    expect(AppReview::markOrderMeta([], $customer))->toBe(['app_review' => true]);

    $reviewOrder = new Order();
    $reviewOrder->forceFill(['meta' => ['app_review' => true]])->syncOriginal();
    $order = new Order();
    $order->forceFill(['meta' => ['total' => 100]]);

    expect(AppReview::isReviewOrder($reviewOrder))->toBeTrue()
        ->and(AppReview::isReviewOrder($order))->toBeFalse()
        // Returned untouched, before the order config is patched or a driver is looked for.
        ->and(Fleetbase\Storefront\Support\Storefront::autoDispatchOrder($reviewOrder))->toBe($reviewOrder)
        ->and($reviewOrder->isDirty())->toBeFalse();
});

test('stores and networks are registered with core, with the Storefront number and permissions', function () {
    createAppReviewSchema();

    $store   = CoreAppReview::definition(AppReview::STORE_TYPE);
    $network = CoreAppReview::definition(AppReview::NETWORK_TYPE);

    expect($store)->toMatchArray(['model' => Store::class, 'phone' => AppReview::PHONE, 'permission' => 'storefront manage-app-review store', 'view_permission' => 'storefront view store'])
        ->and($network)->toMatchArray(['model' => Network::class, 'phone' => AppReview::PHONE, 'permission' => 'storefront manage-app-review network', 'view_permission' => 'storefront view network'])
        ->and(CoreAppReview::typeOf(Store::where('uuid', 'store_a_uuid')->first()))->toBe(AppReview::STORE_TYPE)
        // The Console finds a store by public id in its own company only.
        ->and(CoreAppReview::resolveSubject(AppReview::STORE_TYPE, 'store_a', 'company_a')?->uuid)->toBe('store_a_uuid')
        ->and(CoreAppReview::resolveSubject(AppReview::STORE_TYPE, 'store_b', 'company_a'))->toBeNull()
        ->and(CoreAppReview::status(Store::where('uuid', 'store_a_uuid')->first()))->toMatchArray(['type' => AppReview::STORE_TYPE, 'phone_display' => '+1 202 555 0143', 'enabled' => false]);
});

test('managing App Review access is its own permission on stores and networks', function () {
    $resources = collect((new StorefrontSchema())->resources)->keyBy('name');

    expect($resources['store']['actions'])->toContain('manage-app-review')
        ->and($resources['network']['actions'])->toContain('manage-app-review')
        ->and(Fleetbase\Support\Utils::slugify('manageAppReview'))->toBe('manage-app-review');
});
