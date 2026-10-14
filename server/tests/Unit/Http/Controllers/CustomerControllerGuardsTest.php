<?php

use Fleetbase\Exceptions\SmsBlockedException;
use Fleetbase\Storefront\Http\Controllers\v1\CustomerController;
use Fleetbase\Storefront\Http\Requests\CreateCustomerRequest;
use Fleetbase\Storefront\Http\Requests\VerifyCreateCustomerRequest;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The customer flows' answers when the SMS guard refuses a send, when a code is guessed
 * too often, and when a lookup or the token store is unavailable.
 */
const CUSTOMER_GUARDS_CONTACT_UUID = '6b0f3a2c-1d4e-4f5a-8b6c-7d8e9f0a1b2c';

class CustomerGuardsProbe extends CustomerController
{
    public static function accountExists(?string $identity, bool $isEmail): bool
    {
        return static::accountExistsForIdentity($identity, $isEmail);
    }

    public static function rejectionFor(?string $phone): ?string
    {
        return static::phoneRejection($phone);
    }
}

function customerGuardsDb()
{
    return Model::getConnectionResolver()->connection('mysql');
}

function customerGuardsSchema(): void
{
    $schema = customerGuardsDb()->getSchemaBuilder();
    foreach (['verification_codes', 'users', 'stores', 'companies', 'contacts', 'personal_access_tokens'] as $table) {
        $schema->dropIfExists($table);
    }
    $schema->create('stores', function ($table) {
        $table->increments('id');
        foreach ([
            'uuid', 'public_id', 'company_uuid', 'backdrop_uuid', 'logo_uuid', 'order_config_uuid', 'key',
            'name', 'description', 'translations', 'website', 'facebook', 'instagram', 'twitter', 'email',
            'phone', 'tags', 'currency', 'timezone', 'pod_method', 'options',
        ] as $column) {
            $table->text($column)->nullable();
        }
        $table->timestamps();
        $table->softDeletes();
    });
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
        $table->string('type')->nullable();
        $table->timestamp('phone_verified_at')->nullable();
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
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
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

    $db = customerGuardsDb();
    $db->table('companies')->insert(['uuid' => 'company_uuid', 'options' => '{}']);
    $db->table('stores')->insert([
        'uuid'         => 'store_uuid',
        'public_id'    => 'store_public',
        'company_uuid' => 'company_uuid',
        'key'          => 'store_key',
        'name'         => 'Corner Store',
    ]);
    session(['company' => 'company_uuid', 'storefront_key' => 'store_key']);
}

/**
 * A signed-in customer: a user, its storefront contact and a customer token.
 */
function customerGuardsSignedInCustomer(array $user = []): void
{
    $db = customerGuardsDb();
    $db->table('users')->insert(array_merge([
        'uuid'       => 'user_uuid',
        'name'       => 'Ada Buyer',
        'phone'      => '+97699000001',
        'type'       => 'customer',
        'created_at' => now(),
        'updated_at' => now(),
    ], $user));
    $db->table('contacts')->insert([
        'uuid'         => CUSTOMER_GUARDS_CONTACT_UUID,
        'public_id'    => 'contact_ada',
        'company_uuid' => 'company_uuid',
        'user_uuid'    => 'user_uuid',
        'type'         => 'customer',
        'name'         => 'Ada Buyer',
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
    $db->table('personal_access_tokens')->insert([
        'name'       => CUSTOMER_GUARDS_CONTACT_UUID,
        'token'      => hash('sha256', 'customer-guards-secret'),
        'abilities'  => '["*"]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function customerGuardsRequest(array $input = [], bool $signedIn = false): Request
{
    $request = Request::create('/storefront/v1/customers', 'POST', $input);
    if ($signedIn) {
        $request->headers->set('Customer-Token', 'customer-guards-secret');
    }
    $request->setLaravelSession(new Illuminate\Session\Store('customer-guards', new Illuminate\Session\ArraySessionHandler(120)));
    app()->instance('request', $request);

    return $request;
}

/**
 * Bind delivery fakes; the SMS provider either answers or throws what it is given.
 */
function customerGuardsDelivery(?Throwable $smsFailure = null): object
{
    app()->instance(
        Illuminate\Contracts\Notifications\Dispatcher::class,
        new class implements Illuminate\Contracts\Notifications\Dispatcher {
            public function send($notifiables, $notification)
            {
            }

            public function sendNow($notifiables, $notification)
            {
            }
        }
    );
    $twilio = new class($smsFailure) {
        public array $messages = [];

        public function __construct(private ?Throwable $failure)
        {
        }

        public function message(string $to, string $message, array $media = [], array $params = []): object
        {
            $this->messages[] = $to;
            if ($this->failure) {
                throw $this->failure;
            }

            return (object) ['sid' => 'sms_test'];
        }
    };
    app()->instance('twilio', $twilio);
    Illuminate\Support\Facades\Facade::clearResolvedInstance('twilio');
    app()->instance('mail.manager', new class {
        public function to($recipient): self
        {
            return $this;
        }

        public function send($mailable): void
        {
        }
    });
    Illuminate\Support\Facades\Facade::clearResolvedInstance('mail.manager');

    return $twilio;
}

function customerGuardsLimiter(): RateLimiter
{
    $limiter = new RateLimiter(new Repository(new ArrayStore()));
    app()->instance(RateLimiter::class, $limiter);

    return $limiter;
}

afterEach(function () {
    foreach (['twilio', 'mail.manager', 'redirect', RateLimiter::class, Illuminate\Contracts\Notifications\Dispatcher::class] as $abstract) {
        app()->offsetUnset($abstract);
    }
    Illuminate\Support\Facades\Facade::clearResolvedInstance('twilio');
    Illuminate\Support\Facades\Facade::clearResolvedInstance('mail.manager');
    config([
        'storefront.storefront_app.bypass_verification_code' => null,
        'storefront.storefront_app.review_accounts'          => [],
    ]);
    session(['company' => null, 'storefront_key' => null]);
});

test('a creation code the SMS guard refuses is answered with the guard status and Retry-After', function () {
    customerGuardsSchema();
    $twilio = customerGuardsDelivery(SmsBlockedException::limited('cooldown', 42));

    $response = (new CustomerController())->requestCustomerCreationCode(VerifyCreateCustomerRequest::create('/storefront/v1/customers/request-creation-code', 'POST', [
        'mode'     => 'sms',
        'identity' => '97699112233',
    ]));

    expect($response->getStatusCode())->toBe(429)
        ->and($response->getData(true))->toBe(['error' => 'Unable to send a verification code right now. Try again in a minute.'])
        ->and($response->headers->get('Retry-After'))->toBe('42')
        ->and($twilio->messages)->toBe(['+97699112233']);
});

test('a creation code is still sent when the existing account lookup fails', function () {
    customerGuardsSchema();
    customerGuardsDelivery();
    // A bare install without the users table must not block signups.
    customerGuardsDb()->getSchemaBuilder()->drop('users');

    $response = (new CustomerController())->requestCustomerCreationCode(VerifyCreateCustomerRequest::create('/storefront/v1/customers/request-creation-code', 'POST', [
        'mode'     => 'email',
        'identity' => 'new@example.test',
    ]));
    $code = customerGuardsDb()->table('verification_codes')->first();

    expect($response->getData(true))->toBe(['status' => 'ok'])
        ->and($code->for)->toBe('storefront_create_customer')
        ->and(json_decode($code->meta, true)['identity'])->toBe('new@example.test');
});

test('account and phone checks treat a blank identity as nothing to check', function () {
    customerGuardsSchema();
    customerGuardsDb()->table('users')->insert(['uuid' => 'user_uuid', 'phone' => '+97699112233', 'email' => 'ada@example.test', 'type' => 'customer']);

    expect(CustomerGuardsProbe::accountExists(null, false))->toBeFalse()
        ->and(CustomerGuardsProbe::accountExists('', true))->toBeFalse()
        ->and(CustomerGuardsProbe::accountExists('+97699112233', false))->toBeTrue()
        ->and(CustomerGuardsProbe::accountExists('ada@example.test', true))->toBeTrue()
        ->and(CustomerGuardsProbe::rejectionFor(null))->toBeNull()
        ->and(CustomerGuardsProbe::rejectionFor(''))->toBeNull();
});

test('phone login answers an SMS guard refusal with its status when there is no email to fall back to', function () {
    customerGuardsSchema();
    customerGuardsDb()->table('users')->insert(['uuid' => 'user_uuid', 'name' => 'Ada Buyer', 'phone' => '+97699112233', 'type' => 'customer', 'created_at' => now(), 'updated_at' => now()]);
    customerGuardsDelivery(SmsBlockedException::paused('company', 300));
    customerGuardsRequest(['phone' => '97699112233']);

    $response = (new CustomerController())->loginWithPhone();

    expect($response->getStatusCode())->toBe(423)
        ->and($response->getData(true))->toBe(['error' => 'Text messages are temporarily unavailable. Try again later.'])
        ->and($response->headers->get('Retry-After'))->toBe('300');
});

test('phone verification refuses a number that cannot be texted before anything is written', function () {
    customerGuardsSchema();
    customerGuardsSignedInCustomer();
    $twilio = customerGuardsDelivery();

    config(['sms.guard.enabled' => true]);
    Fleetbase\Support\Sms\SmsPolicy::flush();
    try {
        $response = (new CustomerController())->requestPhoneVerification(customerGuardsRequest(['phone' => '+4471'], true));
    } finally {
        config(['sms.guard.enabled' => false]);
        Fleetbase\Support\Sms\SmsPolicy::flush();
    }

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true))->toBe(['error' => 'The phone number provided is not valid.'])
        ->and($twilio->messages)->toBe([])
        ->and(customerGuardsDb()->table('verification_codes')->count())->toBe(0);
});

test('phone verification answers an SMS guard refusal with its status and Retry-After', function () {
    customerGuardsSchema();
    customerGuardsSignedInCustomer();
    customerGuardsDelivery(SmsBlockedException::limited('hourly_budget', 3600));

    $response = (new CustomerController())->requestPhoneVerification(customerGuardsRequest(['phone' => '97699112233'], true));

    expect($response->getStatusCode())->toBe(429)
        ->and($response->getData(true))->toBe(['error' => 'Unable to send a verification code right now. Try again in a minute.'])
        ->and($response->headers->get('Retry-After'))->toBe('3600');
});

test('customer creation locks an identity out after too many wrong codes', function () {
    customerGuardsSchema();
    customerGuardsLimiter();
    $controller = new CustomerController();
    $attempt    = fn () => $controller->create(CreateCustomerRequest::create('/storefront/v1/customers', 'POST', [
        'identity' => 'buyer@example.test',
        'name'     => 'Buyer',
        'code'     => '000000',
    ]));

    $errors = [];
    for ($i = 0; $i < CustomerController::CODE_ATTEMPT_LIMIT; $i++) {
        $errors[] = $attempt()->getData(true)['error'];
    }
    $lockedOut = $attempt();

    expect(array_unique($errors))->toBe(['Invalid verification code provided!'])
        ->and($lockedOut->getStatusCode())->toBe(429)
        ->and($lockedOut->getData(true))->toBe(['error' => 'Too many attempts. Try again later.'])
        ->and(customerGuardsDb()->table('contacts')->count())->toBe(0);
});

test('account closure locks a customer out after too many wrong codes', function () {
    customerGuardsSchema();
    customerGuardsSignedInCustomer();
    customerGuardsLimiter();
    $controller = new CustomerController();
    $attempt    = fn () => $controller->confirmAccountClosure(customerGuardsRequest(['code' => '000000'], true));

    $errors = [];
    for ($i = 0; $i < CustomerController::CODE_ATTEMPT_LIMIT; $i++) {
        $errors[] = $attempt()->getData(true)['error'];
    }
    $lockedOut = $attempt();

    expect(array_unique($errors))->toBe(['Invalid verification code provided!'])
        ->and($lockedOut->getStatusCode())->toBe(429)
        ->and($lockedOut->getData(true))->toBe(['error' => 'Too many attempts. Try again later.'])
        ->and(customerGuardsDb()->table('contacts')->whereNull('deleted_at')->count())->toBe(1)
        ->and(customerGuardsDb()->table('users')->whereNull('deleted_at')->count())->toBe(1);
});

test('phone number verification locks a customer out after too many wrong codes', function () {
    customerGuardsSchema();
    customerGuardsSignedInCustomer();
    customerGuardsLimiter();
    $controller = new CustomerController();
    $attempt    = fn () => $controller->verifyPhoneNumber(customerGuardsRequest(['code' => '000000'], true));

    $errors = [];
    for ($i = 0; $i < CustomerController::CODE_ATTEMPT_LIMIT; $i++) {
        $errors[] = $attempt()->getData(true)['error'];
    }
    $lockedOut = $attempt();

    expect(array_unique($errors))->toBe(['Invalid verification code!'])
        ->and($lockedOut->getStatusCode())->toBe(429)
        ->and($lockedOut->getData(true))->toBe(['error' => 'Too many attempts. Try again later.'])
        ->and(customerGuardsDb()->table('users')->value('phone'))->toBe('+97699000001');
});

test('the attempt lockout stays out of the way when the rate limiter cannot be built', function () {
    customerGuardsSchema();
    app()->bind(RateLimiter::class, function () {
        throw new RuntimeException('Cache store unavailable');
    });
    $controller = new CustomerController();

    $statuses = [];
    for ($i = 0; $i <= CustomerController::CODE_ATTEMPT_LIMIT; $i++) {
        $response   = $controller->verifyCode(Request::create('/storefront/v1/customers/verify-code', 'POST', ['identity' => '97699112233', 'code' => '000000']));
        $statuses[] = $response->getStatusCode();
    }

    expect(array_unique($statuses))->toBe([400])
        ->and($response->getData(true))->toBe(['error' => 'Unable to verify code.']);
});

test('verify code passes a validated create-customer request on to customer creation', function () {
    customerGuardsSchema();
    // A code exists, so validation passes, but it was filed for someone else.
    customerGuardsDb()->table('verification_codes')->insert([
        'uuid'       => 'someone_elses_code',
        'code'       => '111111',
        'for'        => 'storefront_create_customer',
        'status'     => 'active',
        'meta'       => json_encode(['identity' => 'someone@example.test']),
        'expires_at' => now()->addHour(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $originalValidator = app('validator');
    $validator         = new Illuminate\Validation\Factory(new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader(), 'en'), app());
    $validator->setPresenceVerifier(new Illuminate\Validation\DatabasePresenceVerifier(Model::getConnectionResolver()));
    app()->instance('validator', $validator);
    app()->instance('redirect', new stdClass());

    try {
        $response = (new CustomerController())->verifyCode(Request::create('/storefront/v1/customers/verify-code', 'POST', [
            'identity' => 'buyer@example.test',
            'code'     => '111111',
            'for'      => 'storefront_create_customer',
            'name'     => 'Verified Buyer',
            'email'    => 'buyer@example.test',
        ]));
    } finally {
        app()->instance('validator', $originalValidator);
    }

    // The request got past validation and was refused by create() itself.
    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true))->toBe(['error' => 'Invalid verification code provided!'])
        ->and(customerGuardsDb()->table('contacts')->count())->toBe(0)
        ->and(customerGuardsDb()->table('users')->count())->toBe(0);
});

test('verify code reports a token store failure instead of failing the request', function () {
    customerGuardsSchema();
    config([
        'storefront.storefront_app.bypass_verification_code' => '000000',
        'storefront.storefront_app.review_accounts'          => ['+97699112233'],
    ]);
    customerGuardsDb()->table('users')->insert(['uuid' => 'user_uuid', 'name' => 'Ada Buyer', 'phone' => '+97699112233', 'email' => 'ada@example.test', 'type' => 'customer', 'created_at' => now(), 'updated_at' => now()]);
    // The customer's storefront contact already exists; the token store is unavailable.
    customerGuardsDb()->table('contacts')->insert(['uuid' => CUSTOMER_GUARDS_CONTACT_UUID, 'company_uuid' => 'company_uuid', 'user_uuid' => 'user_uuid', 'type' => 'customer', 'name' => 'Ada Buyer']);
    customerGuardsDb()->getSchemaBuilder()->drop('personal_access_tokens');

    $response = (new CustomerController())->verifyCode(Request::create('/storefront/v1/customers/verify-code', 'POST', [
        'identity' => '97699112233',
        'code'     => '000000',
    ]));

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true)['error'])->toContain('personal_access_tokens')
        // The existing contact was reused, not duplicated.
        ->and(customerGuardsDb()->table('contacts')->pluck('uuid')->all())->toBe([CUSTOMER_GUARDS_CONTACT_UUID]);
});
