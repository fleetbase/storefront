<?php

use Fleetbase\Storefront\Http\Controllers\v1\CustomerController;
use Fleetbase\Storefront\Http\Requests\VerifyCreateCustomerRequest;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The storefront's own part of the SMS abuse defences: refusing creation codes for
 * identities that already have an account, not re-sending inside the resend window,
 * surfacing the core guard's phone validation, and locking out code guessing.
 *
 * The core guard itself stays off here (see scripts/pest-bootstrap.php); the one test
 * that needs it turns it on for its duration and only when the installed core has it.
 */
function sms_guard_customer_schema(): void
{
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    foreach (['verification_codes', 'users', 'stores', 'companies'] as $table) {
        $schema->dropIfExists($table);
    }
    $schema->create('stores', function ($table) {
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
    $connection->table('companies')->insert(['uuid' => 'company_uuid', 'options' => '{}']);
    $connection->table('stores')->insert([
        'uuid'         => 'store_uuid',
        'public_id'    => 'store_public',
        'company_uuid' => 'company_uuid',
        'key'          => 'store_key',
        'name'         => 'Corner Store',
    ]);

    session([
        'company'        => 'company_uuid',
        'storefront_key' => 'store_key',
    ]);
}

function sms_guard_customer_fakes(): void
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
    app()->instance('twilio', new class {
        public array $messages = [];

        public function message(string $to, string $message, array $media = [], array $params = []): object
        {
            $this->messages[] = $to;

            return (object) ['sid' => 'sms_test'];
        }
    });
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
}

function sms_guard_customer_forget_fakes(): void
{
    app()->forgetInstance('twilio');
    app()->forgetInstance('mail.manager');
    app()->forgetInstance(RateLimiter::class);
    app()->offsetUnset(Illuminate\Contracts\Notifications\Dispatcher::class);
    Illuminate\Support\Facades\Facade::clearResolvedInstance('twilio');
    Illuminate\Support\Facades\Facade::clearResolvedInstance('mail.manager');
}

function sms_guard_creation_request(string $mode, string $identity): VerifyCreateCustomerRequest
{
    return VerifyCreateCustomerRequest::create('/storefront/v1/customers/request-creation-code', 'POST', [
        'mode'     => $mode,
        'identity' => $identity,
    ]);
}

afterEach(function () {
    sms_guard_customer_forget_fakes();
    session(['company' => null, 'storefront_key' => null]);
});

test('creation codes are refused for an identity that already has an account, with nothing sent', function () {
    sms_guard_customer_schema();
    sms_guard_customer_fakes();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('users')->insert([
        'uuid'       => 'user_existing',
        'name'       => 'Ada Buyer',
        'phone'      => '+97699112233',
        'email'      => 'ada@example.test',
        'type'       => 'customer',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $connection->table('users')->insert([
        'uuid'       => 'user_deleted',
        'phone'      => '+97699000009',
        'type'       => 'customer',
        'deleted_at' => now(),
    ]);

    $controller = new CustomerController();
    $phone      = $controller->requestCustomerCreationCode(sms_guard_creation_request('sms', '97699112233'));
    $email      = $controller->requestCustomerCreationCode(sms_guard_creation_request('email', 'ada@example.test'));
    $deleted    = $controller->requestCustomerCreationCode(sms_guard_creation_request('sms', '+97699000009'));

    expect($phone->getStatusCode())->toBe(422)
        ->and($phone->getData(true))->toBe(['error' => 'An account already exists for this phone number. Sign in instead.'])
        ->and($email->getStatusCode())->toBe(422)
        ->and($email->getData(true))->toBe(['error' => 'An account already exists for this email address. Sign in instead.'])
        ->and($deleted->getData(true))->toBe(['status' => 'ok'])
        ->and(app('twilio')->messages)->toBe(['+97699000009'])
        ->and($connection->table('verification_codes')->count())->toBe(1);
});

test('a creation code is not sent again inside the resend window', function () {
    sms_guard_customer_schema();
    sms_guard_customer_fakes();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('verification_codes')->insert([
        'uuid'       => 'code_recent',
        'code'       => '123456',
        'for'        => 'storefront_create_customer',
        'status'     => 'active',
        'meta'       => json_encode(['identity' => '+97699000001']),
        'expires_at' => now()->addHour(),
        'created_at' => now()->subSeconds(10),
        'updated_at' => now()->subSeconds(10),
    ]);
    $connection->table('verification_codes')->insert([
        'uuid'       => 'code_stale',
        'code'       => '654321',
        'for'        => 'storefront_create_customer',
        'status'     => 'active',
        'meta'       => json_encode(['identity' => '+97699000002']),
        'expires_at' => now()->addHour(),
        'created_at' => now()->subSeconds(120),
        'updated_at' => now()->subSeconds(120),
    ]);

    $controller = new CustomerController();
    $recent     = $controller->requestCustomerCreationCode(sms_guard_creation_request('sms', '97699000001'));
    $stale      = $controller->requestCustomerCreationCode(sms_guard_creation_request('sms', '97699000002'));

    config(['storefront.verification.resend_seconds' => 0]);
    $disabled = $controller->requestCustomerCreationCode(sms_guard_creation_request('sms', '97699000001'));
    config(['storefront.verification.resend_seconds' => CustomerController::CREATION_CODE_RESEND_SECONDS]);

    expect($recent->getData(true))->toBe(['status' => 'ok'])
        ->and($stale->getData(true))->toBe(['status' => 'ok'])
        ->and($disabled->getData(true))->toBe(['status' => 'ok'])
        ->and(app('twilio')->messages)->toBe(['+97699000002', '+97699000001'])
        ->and($connection->table('verification_codes')->count())->toBe(4);
});

test('a creation code for a number that cannot be texted is refused before anything is written', function () {
    sms_guard_customer_schema();
    sms_guard_customer_fakes();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $controller = new CustomerController();

    if (!class_exists(Fleetbase\Support\Sms\PhoneValidator::class)) {
        // An older core has no validator: the request goes through as before.
        $response = $controller->requestCustomerCreationCode(sms_guard_creation_request('sms', '+4471'));

        expect($response->getData(true))->toBe(['status' => 'ok']);

        return;
    }

    config(['sms.guard.enabled' => true]);
    Fleetbase\Support\Sms\SmsPolicy::flush();

    try {
        $invalid  = $controller->requestCustomerCreationCode(sms_guard_creation_request('sms', '+4471'));
        $landline = $controller->requestCustomerCreationCode(sms_guard_creation_request('sms', '+442079460000'));
    } finally {
        config(['sms.guard.enabled' => false]);
        Fleetbase\Support\Sms\SmsPolicy::flush();
    }

    expect($invalid->getStatusCode())->toBe(422)
        ->and($invalid->getData(true))->toBe(['error' => 'The phone number provided is not valid.'])
        ->and($landline->getStatusCode())->toBe(422)
        ->and($landline->getData(true))->toBe(['error' => 'The phone number provided cannot receive text messages.'])
        ->and(app('twilio')->messages)->toBe([])
        ->and($connection->table('verification_codes')->count())->toBe(0);
});

test('wrong codes lock an identity out after the attempt limit, even for a code that would be right', function () {
    sms_guard_customer_schema();
    sms_guard_customer_fakes();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('users')->insert([
        'uuid'       => 'user_uuid',
        'name'       => 'Ada Buyer',
        'phone'      => '+97699112233',
        'type'       => 'customer',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $connection->table('verification_codes')->insert([
        'uuid'         => 'code_login',
        'subject_uuid' => 'user_uuid',
        'code'         => '123456',
        'for'          => 'storefront_login',
        'status'       => 'active',
        'expires_at'   => now()->addHour(),
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
    app()->instance(RateLimiter::class, new RateLimiter(app('cache')));

    $controller = new CustomerController();
    $attempt    = fn (string $code) => $controller->verifyCode(Request::create('/storefront/v1/customers/verify-code', 'POST', [
        'identity' => '97699112233',
        'code'     => $code,
    ]));

    $statuses = [];
    for ($i = 0; $i < CustomerController::CODE_ATTEMPT_LIMIT; $i++) {
        $statuses[] = $attempt('000000')->getStatusCode();
    }
    $lockedOut = $attempt('000000');
    $tooLate   = $attempt('123456');

    expect(array_unique($statuses))->toBe([400])
        ->and($lockedOut->getStatusCode())->toBe(429)
        ->and($lockedOut->getData(true))->toBe(['error' => 'Too many attempts. Try again later.'])
        ->and($tooLate->getStatusCode())->toBe(429);
});

test('the attempt lockout is inert where no rate limiter is bound', function () {
    sms_guard_customer_schema();
    sms_guard_customer_fakes();
    $controller = new CustomerController();
    $attempt    = fn () => $controller->verifyCode(Request::create('/storefront/v1/customers/verify-code', 'POST', [
        'identity' => '97699112233',
        'code'     => '000000',
    ]));

    for ($i = 0; $i <= CustomerController::CODE_ATTEMPT_LIMIT; $i++) {
        $response = $attempt();
    }

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true))->toBe(['error' => 'Unable to verify code.']);
});
