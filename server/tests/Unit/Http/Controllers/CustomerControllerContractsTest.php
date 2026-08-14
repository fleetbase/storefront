<?php

use Fleetbase\FleetOps\Http\Requests\UpdateContactRequest;
use Fleetbase\Storefront\Http\Controllers\v1\CustomerController;
use Fleetbase\Storefront\Http\Requests\CreateCustomerRequest;
use Fleetbase\Storefront\Http\Requests\VerifyCreateCustomerRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store as SessionStore;

class SocialCustomerControllerStub extends CustomerController
{
    public bool $appleValid      = true;
    public ?array $googlePayload = null;

    protected function verifyAppleIdentity(string $identityToken): bool
    {
        return $this->appleValid;
    }

    protected function verifyGoogleIdentity(string $idToken, string $clientId): ?array
    {
        return $this->googlePayload;
    }
}

class CustomerIdentityProbe extends CustomerController
{
    public function apple(string $token): bool
    {
        return $this->verifyAppleIdentity($token);
    }

    public function google(string $token, string $clientId): ?array
    {
        return $this->verifyGoogleIdentity($token, $clientId);
    }

    public static function reviewAccountBypass(?string $identity, mixed $code): bool
    {
        return parent::isReviewAccountBypass($identity, $code);
    }
}

class PhoneConflictCustomerControllerStub extends CustomerController
{
    public ?Fleetbase\Models\User $existingPhoneUser = null;

    protected function findExistingUserByPhone(string $phone, string $excludedUserUuid): ?Fleetbase\Models\User
    {
        return $this->existingPhoneUser;
    }
}

function bindUnauthenticatedCustomerRequest(array $input = []): Request
{
    $request = Request::create('/customer', 'POST', $input);
    $request->setLaravelSession(new SessionStore(
        'customer-controller-test',
        new ArraySessionHandler(120)
    ));
    app()->instance('request', $request);

    return $request;
}

function createCustomerControllerUsersSchema(): void
{
    $schema = Model::getConnectionResolver()->connection('mysql')->getSchemaBuilder();
    $schema->dropIfExists('users');
    $schema->create('users', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->string('password')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
}

function createCustomerControllerContactsSchema(): void
{
    $schema = Model::getConnectionResolver()->connection('mysql')->getSchemaBuilder();
    $schema->dropIfExists('contacts');
    $schema->create('contacts', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('type')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
}

function createCustomerVerificationDeliverySchema(): void
{
    $schema = Model::getConnectionResolver()->connection('mysql')->getSchemaBuilder();
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
    Model::getConnectionResolver()->connection('mysql')->table('companies')->insert([
        'uuid'    => 'company_uuid',
        'options' => '{}',
    ]);
}

function bindCustomerNotificationDispatcher(): void
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
        public function message(string $to, string $message, array $media = [], array $params = []): object
        {
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

test('customer token protected endpoints reject unauthenticated callers', function () {
    bindUnauthenticatedCustomerRequest();
    $controller = new CustomerController();

    $device       = $controller->registerDevice(Request::create('/customer/device', 'POST'));
    $orders       = $controller->orders(Request::create('/customer/orders'));
    $places       = $controller->places(Request::create('/customer/places'));
    $ephemeralKey = $controller->getStripeEphemeralKey(Request::create('/customer/stripe/key'));
    $setupIntent  = $controller->getStripeSetupIntent(Request::create('/customer/stripe/setup'));
    $phoneRequest = $controller->requestPhoneVerification(Request::create('/customer/phone', 'POST', [
        'phone' => '97699112233',
    ]));
    $phoneVerify = $controller->verifyPhoneNumber(Request::create('/customer/phone/verify', 'POST'));

    expect($device->getData(true))->toBe(['error' => 'Not authorized to register device for cutomer'])
        ->and($orders->getData(true))->toBe(['error' => 'Not authorized to view customers orders'])
        ->and($places->getData(true))->toBe(['error' => 'Not authorized to view customers places'])
        ->and($ephemeralKey->getData(true))->toBe(['error' => 'Not authorized to view customers places'])
        ->and($setupIntent->getData(true))->toBe(['error' => 'Not authorized to view customers places'])
        ->and($phoneRequest->getData(true))->toBe(['error' => 'Not authorized to request phone verification.'])
        ->and($phoneVerify->getData(true))->toBe(['error' => 'Not authorized to verify phone number.']);
});

test('authenticated customer endpoints register devices and scope orders and places', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    foreach (['gateways', 'personal_access_tokens', 'user_devices', 'orders', 'places', 'contacts', 'verification_codes', 'users', 'stores', 'companies'] as $table) {
        $schema->dropIfExists($table);
    }
    $schema->create('companies', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->text('options')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
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
        $table->softDeletes();
    });
    $schema->create('users', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('phone')->nullable();
        $table->timestamp('phone_verified_at')->nullable();
        $table->string('email')->nullable();
        $table->string('type')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
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
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('personal_access_tokens', function ($table) {
        $table->increments('id');
        $table->string('tokenable_type')->nullable();
        $table->integer('tokenable_id')->nullable();
        $table->string('name');
        $table->string('token');
        $table->text('abilities')->nullable();
        $table->timestamp('last_used_at')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
    });
    $schema->create('contacts', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('phone')->nullable();
        $table->string('type')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('user_devices', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('platform')->nullable();
        $table->string('token')->nullable();
        $table->string('status')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('orders', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('customer_uuid')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('places', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('gateways', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('code')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->text('config')->nullable();
        $table->boolean('sandbox')->default(false);
        $table->timestamp('deleted_at')->nullable();
    });
    $customerUuid = '11111111-1111-4111-8111-111111111111';
    $connection->table('contacts')->insert([
        'uuid'      => $customerUuid,
        'public_id' => 'contact_customer',
        'user_uuid' => 'user_uuid',
        'type'      => 'customer',
        'meta'      => json_encode(['stripe_id' => 'cus_customer']),
    ]);
    $connection->table('stores')->insert([
        'uuid'         => 'store_uuid',
        'public_id'    => 'store_public',
        'company_uuid' => 'company_uuid',
        'key'          => 'store_key',
        'name'         => 'Corner Store',
    ]);
    $connection->table('companies')->insert([
        'uuid'    => 'company_uuid',
        'options' => '{}',
    ]);
    $connection->table('personal_access_tokens')->insert([
        'name'       => $customerUuid,
        'token'      => hash('sha256', 'customer-secret'),
        'abilities'  => '["*"]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $connection->table('orders')->insert([
        [
            'uuid'          => 'order_visible',
            'public_id'     => 'order_visible',
            'customer_uuid' => $customerUuid,
            'meta'          => json_encode(['is_master_order' => false]),
            'created_at'    => now(),
            'updated_at'    => now(),
        ],
        [
            'uuid'          => 'order_other',
            'public_id'     => 'order_other',
            'customer_uuid' => 'other_customer',
            'meta'          => json_encode([]),
            'created_at'    => now(),
            'updated_at'    => now(),
        ],
    ]);
    $connection->table('places')->insert([
        [
            'uuid'       => 'place_customer',
            'public_id'  => 'place_customer',
            'owner_uuid' => $customerUuid,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'uuid'       => 'place_other',
            'public_id'  => 'place_other',
            'owner_uuid' => 'other_customer',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);
    $connection->table('gateways')->insert([
        'uuid'       => 'stripe_gateway_uuid',
        'code'       => 'stripe',
        'owner_uuid' => 'network_uuid',
        'config'     => json_encode(['secret_key' => 'sk_test_storefront']),
    ]);
    session([
        'storefront_network' => 'network_uuid',
        'storefront_key'     => 'store_key',
        'company'            => 'company_uuid',
    ]);
    $boundRequest = bindUnauthenticatedCustomerRequest();
    $boundRequest->headers->set('Customer-Token', 'customer-secret');
    app()->instance('request', $boundRequest);
    $controller = new CustomerController();
    Stripe\ApiRequestor::setHttpClient(new class implements Stripe\HttpClient\ClientInterface {
        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            if (str_ends_with($absUrl, '/customers')) {
                return [json_encode([
                    'id'     => 'cus_created_customer',
                    'object' => 'customer',
                ]), 200, []];
            }

            if (str_contains($absUrl, '/ephemeral_keys')) {
                return [json_encode([
                    'id'     => 'ephkey_customer',
                    'object' => 'ephemeral_key',
                    'secret' => 'eph_customer_secret',
                ]), 200, []];
            }

            return [json_encode([
                'id'            => 'seti_customer',
                'object'        => 'setup_intent',
                'client_secret' => 'seti_customer_secret',
                'customer'      => 'cus_customer',
                'status'        => 'requires_payment_method',
            ]), 200, []];
        }
    });

    $device = $controller->registerDevice(Request::create('/customer/device', 'POST', [
        'token'    => 'device-token',
        'platform' => 'ios',
    ]));
    $orders         = $controller->orders(Request::create('/customer/orders'));
    $places         = $controller->places(Request::create('/customer/places'));
    $ephemeralKey   = $controller->getStripeEphemeralKey(Request::create('/customer/stripe/key'));
    $setupIntent    = $controller->getStripeSetupIntent(Request::create('/customer/stripe/setup'));
    $connection->table('contacts')->where('uuid', $customerUuid)->update(['meta' => '{}']);
    $createdEphemeralKey = $controller->getStripeEphemeralKey(Request::create('/customer/stripe/key'));
    $connection->table('contacts')->where('uuid', $customerUuid)->update(['meta' => '{}']);
    $createdSetupIntent = $controller->getStripeSetupIntent(Request::create('/customer/stripe/setup'));
    Stripe\ApiRequestor::setHttpClient(new class implements Stripe\HttpClient\ClientInterface {
        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            throw new RuntimeException('Stripe customer endpoint unavailable');
        }
    });
    $failedEphemeralKey = $controller->getStripeEphemeralKey(Request::create('/customer/stripe/key'));
    $failedSetupIntent  = $controller->getStripeSetupIntent(Request::create('/customer/stripe/setup'));
    $connection->table('gateways')->delete();
    $missingEphemeralGateway = $controller->getStripeEphemeralKey(Request::create('/customer/stripe/key'));
    $missingSetupGateway     = $controller->getStripeSetupIntent(Request::create('/customer/stripe/setup'));
    Stripe\ApiRequestor::setHttpClient(new Stripe\HttpClient\CurlClient());
    $closureStart   = $controller->startAccountClosure(Request::create('/customer/closure', 'POST'));
    $closureConfirm = $controller->confirmAccountClosure(Request::create('/customer/closure/confirm', 'POST', [
        'code' => '123456',
    ]));
    $phoneWithoutUser = $controller->requestPhoneVerification(Request::create('/customer/phone', 'POST', [
        'phone' => '97699112233',
    ]));
    $verificationWithoutUser = $controller->verifyPhoneNumber(Request::create('/customer/phone/verify', 'POST', [
        'code' => '123456',
    ]));
    $connection->table('users')->insert([
        [
            'uuid'  => 'user_uuid',
            'email' => 'ada@example.test',
            'type'  => 'customer',
        ],
        [
            'uuid'  => 'other_user_uuid',
            'phone' => '+97699887766',
            'type'  => 'customer',
        ],
    ]);
    bindCustomerNotificationDispatcher();
    $sentry = new class {
        public array $exceptions = [];

        public function captureException(Throwable $exception): void
        {
            $this->exceptions[] = $exception;
        }
    };
    app()->instance('sentry', $sentry);
    $phoneConflictController                    = new PhoneConflictCustomerControllerStub();
    $phoneConflictController->existingPhoneUser = new Fleetbase\Models\User(['uuid' => 'other_user_uuid']);
    $existingPhoneConflict                      = $phoneConflictController->requestPhoneVerification(Request::create('/customer/phone', 'POST', [
        'phone' => '+97699887766',
    ]));
    $connection->table('users')->where('uuid', 'user_uuid')->update(['email' => null]);
    $closureWithoutIdentity = $controller->startAccountClosure(Request::create('/customer/closure', 'POST'));
    $connection->table('users')->where('uuid', 'user_uuid')->update(['email' => 'ada@example.test']);
    $emailClosureStarted = $controller->startAccountClosure(Request::create('/customer/closure', 'POST'));
    $connection->statement(
        "CREATE TRIGGER fail_closure_verification_insert BEFORE INSERT ON verification_codes BEGIN SELECT RAISE(ABORT, 'closure verification failed'); END"
    );
    $closureDeliveryFailure = $controller->startAccountClosure(Request::create('/customer/closure', 'POST'));
    $connection->statement('DROP TRIGGER fail_closure_verification_insert');
    $phoneConflict       = $controller->requestPhoneVerification(Request::create('/customer/phone', 'POST', [
        'phone' => '97699112233',
    ]));
    $invalidPhoneCode = $controller->verifyPhoneNumber(Request::create('/customer/phone/verify', 'POST', [
        'code' => 'invalid-code',
    ]));
    $generatedCode = $connection->table('verification_codes')
        ->where('for', 'storefront_verify_phone')
        ->value('code');
    $verifiedPhone = $controller->verifyPhoneNumber(Request::create('/customer/phone/verify', 'POST', [
        'code' => $generatedCode,
    ]));
    $closureStarted = $controller->startAccountClosure(Request::create('/customer/closure', 'POST'));
    $connection->statement(
        "CREATE TRIGGER fail_phone_verification_insert BEFORE INSERT ON verification_codes BEGIN SELECT RAISE(ABORT, 'verification insert failed'); END"
    );
    $closureChannelFailure = $controller->startAccountClosure(Request::create('/customer/closure', 'POST'));
    $phoneDeliveryFailure  = $controller->requestPhoneVerification(Request::create('/customer/phone', 'POST', [
        'phone' => '+97699112234',
    ]));
    $connection->statement('DROP TRIGGER fail_phone_verification_insert');
    $closureCode    = $connection->table('verification_codes')
        ->where('for', 'storefront_account_closure')
        ->where('meta', 'like', '%+97699112233%')
        ->value('code');
    $invalidClosure = $controller->confirmAccountClosure(Request::create('/customer/closure/confirm', 'POST', [
        'code' => 'invalid-code',
    ]));
    $connection->statement(
        "CREATE TRIGGER fail_customer_user_delete BEFORE UPDATE ON users BEGIN SELECT RAISE(ABORT, 'user delete failed'); END"
    );
    $closureDeletionFailure = $controller->confirmAccountClosure(Request::create('/customer/closure/confirm', 'POST', [
        'code' => $closureCode,
    ]));
    $connection->statement('DROP TRIGGER fail_customer_user_delete');
    $closed = $controller->confirmAccountClosure(Request::create('/customer/closure/confirm', 'POST', [
        'code' => $closureCode,
    ]));
    app()->offsetUnset(Illuminate\Contracts\Notifications\Dispatcher::class);
    app()->forgetInstance('sentry');
    expect($device->getData(true))->toHaveKey('device')
        ->and($connection->table('user_devices')->where('token', 'device-token')->value('user_uuid'))->toBe('user_uuid')
        ->and($orders->resource)->toHaveCount(1)
        ->and($orders->resource->first()->uuid)->toBe('order_visible')
        ->and($places->resource)->toHaveCount(1)
        ->and($places->resource->first()->uuid)->toBe('place_customer')
        ->and($ephemeralKey->getData(true))->toBe([
            'ephemeralKey' => 'eph_customer_secret',
            'customerId'   => 'cus_customer',
        ])->and($setupIntent->getData(true))->toBe([
            'setupIntentId' => 'seti_customer',
            'setupIntent'   => 'seti_customer_secret',
            'customerId'    => 'cus_customer',
        ])->and($createdEphemeralKey->getData(true)['customerId'])->toBe('cus_created_customer')
        ->and($createdSetupIntent->getData(true)['customerId'])->toBe('cus_created_customer')
        ->and($failedEphemeralKey->getData(true))->toBe(['error' => 'Stripe customer endpoint unavailable'])
        ->and($failedSetupIntent->getData(true))->toBe(['error' => 'Stripe customer endpoint unavailable'])
        ->and($missingEphemeralGateway->getData(true))->toBe(['error' => 'Stripe not setup.'])
        ->and($missingSetupGateway->getData(true))->toBe(['error' => 'Stripe not setup.'])
        ->and($closureStart->getData(true))->toBe(['error' => 'Customer user account not found.'])
        ->and($closureConfirm->getData(true))->toBe(['error' => 'Customer user account not found.'])
        ->and($phoneWithoutUser->getData(true))->toBe(['error' => 'No user associated with this customer.'])
        ->and($verificationWithoutUser->getData(true))->toBe(['error' => 'No user associated with this customer.'])
        ->and($closureWithoutIdentity->getData(true))->toBe([
            'error' => 'Customer account must have a valid email or phone number linked.',
        ])->and($emailClosureStarted->getData(true))->toBe(['status' => 'OK'])
        ->and($closureDeliveryFailure->getData(true))->toHaveKey('error')
        ->and($closureChannelFailure->getData(true))->toBe([
            'error' => 'Unable to send account closure verification code.',
        ])
        ->and($sentry->exceptions)->toHaveCount(4)
        ->and($existingPhoneConflict->getData(true))->toBe([
            'error' => 'This phone number is already associated with another account.',
        ])
        ->and($phoneConflict->getData(true))->toBe(['status' => 'ok'])
        ->and($connection->table('verification_codes')->where('for', 'storefront_verify_phone')->count())->toBe(1)
        ->and($invalidPhoneCode->getData(true))->toBe(['error' => 'Invalid verification code!'])
        ->and($verifiedPhone)->toBeInstanceOf(Fleetbase\Storefront\Http\Resources\Customer::class)
        ->and($connection->table('users')->where('uuid', 'user_uuid')->value('phone'))->toBe('+97699112233')
        ->and($connection->table('contacts')->where('uuid', $customerUuid)->value('phone'))->toBe('+97699112233')
        ->and($connection->table('verification_codes')->where('code', $generatedCode)->value('deleted_at'))->not->toBeNull()
        ->and(json_encode($closureStarted->getData(true)))->toBe('{"status":"OK"}')
        ->and($phoneDeliveryFailure->getData(true))->toHaveKey('error')
        ->and($invalidClosure->getData(true))->toBe(['error' => 'Invalid verification code provided!'])
        ->and($closureDeletionFailure->getData(true))->toHaveKey('error')
        ->and($closed->getData(true))->toBe(['status' => 'OK'])
        ->and($connection->table('users')->where('uuid', 'user_uuid')->value('deleted_at'))->not->toBeNull()
        ->and($connection->table('contacts')->where('uuid', $customerUuid)->value('deleted_at'))->not->toBeNull();
});

test('customer social login endpoints validate required provider parameters', function () {
    $controller = new CustomerController();

    $apple        = $controller->loginWithApple(Request::create('/customer/apple', 'POST'));
    $google       = $controller->loginWithGoogle(Request::create('/customer/google', 'POST'));
    $invalidApple = $controller->loginWithApple(Request::create('/customer/apple', 'POST', [
        'identityToken'     => 'malformed-token',
        'authorizationCode' => 'authorization-code',
    ]));
    $invalidGoogle = $controller->loginWithGoogle(Request::create('/customer/google', 'POST', [
        'idToken' => 'malformed-token',
        'clientId'=> 'client-id',
    ]));
    $socialController             = new SocialCustomerControllerStub();
    $socialController->appleValid = false;
    $rejectedApple                = $socialController->loginWithApple(Request::create('/customer/apple', 'POST', [
        'identityToken'     => 'rejected-token',
        'authorizationCode' => 'authorization-code',
    ]));

    expect($apple->getStatusCode())->toBe(400)
        ->and($apple->getData(true))->toBe(['error' => 'Missing required Apple authentication parameters.'])
        ->and($google->getStatusCode())->toBe(400)
        ->and($google->getData(true))->toBe(['error' => 'Missing required Google authentication parameters.'])
        // A malformed identityToken is client input. The JWT parser throws rather than
        // returning false, and that used to fall through to the blanket catch and come
        // back as 500 {"error":"The JWT string must have two dots"} — leaking the parser's
        // own message. $invalidGoogle below answers 400 for equally malformed input, so
        // Apple was inconsistent with Google in this very test. This assertion pinned it.
        ->and($invalidApple->getStatusCode())->toBe(400)
        ->and($invalidApple->getData(true))->toBe(['error' => 'Apple ID authentication is not valid.'])
        ->and($rejectedApple->getData(true))->toBe(['error' => 'Apple ID authentication is not valid.'])
        ->and($invalidGoogle->getStatusCode())->toBe(400)
        ->and($invalidGoogle->getData(true))->toBe(['error' => 'Google Sign-In authentication is not valid.']);
});

test('customer identity verifier seams reject malformed provider tokens', function () {
    $probe = new CustomerIdentityProbe();

    try {
        $apple = $probe->apple('malformed-token');
    } catch (Throwable) {
        $apple = false;
    }

    expect($apple)->toBeFalse()
        ->and($probe->google('malformed-token', 'client-id'))->toBeNull();
});

test('customer verification bypass is restricted to configured review identities and constant-time codes', function () {
    config([
        'storefront.storefront_app.bypass_verification_code' => null,
        'storefront.storefront_app.review_accounts'          => [],
    ]);
    expect(CustomerIdentityProbe::reviewAccountBypass('reviewer@example.test', '246810'))->toBeFalse();

    config([
        'storefront.storefront_app.bypass_verification_code' => '246810',
        'storefront.storefront_app.review_accounts'          => [' Reviewer@Example.Test '],
    ]);

    expect(CustomerIdentityProbe::reviewAccountBypass('other@example.test', '246810'))->toBeFalse()
        ->and(CustomerIdentityProbe::reviewAccountBypass('reviewer@example.test', 'wrong'))->toBeFalse()
        ->and(CustomerIdentityProbe::reviewAccountBypass('reviewer@example.test', '246810'))->toBeTrue();
});

test('facebook login links an existing customer identity and issues a local access token', function () {
    $schema = Model::getConnectionResolver()->connection('mysql')->getSchemaBuilder();
    foreach (['personal_access_tokens', 'contacts', 'users'] as $table) {
        $schema->dropIfExists($table);
    }
    $schema->create('users', function ($table) {
        $table->increments('id');
        $table->string('uuid')->default('generated_user_uuid');
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->string('facebook_user_id')->nullable();
        $table->string('apple_user_id')->nullable();
        $table->string('google_user_id')->nullable();
        $table->string('type')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('contacts', function ($table) {
        $table->increments('id');
        $table->string('uuid')->default('generated_contact_uuid');
        $table->string('public_id')->nullable();
        $table->string('company_uuid');
        $table->string('user_uuid');
        $table->string('name')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->string('type')->nullable();
        $table->text('meta')->nullable();
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

    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('users')->insert([
        'uuid'         => 'user_uuid',
        'company_uuid' => 'company_uuid',
        'name'         => 'Ada Buyer',
        'email'        => 'ada@example.test',
        'type'         => 'customer',
    ]);
    $connection->table('contacts')->insert([
        'uuid'         => 'contact_uuid',
        'public_id'    => 'contact_public',
        'company_uuid' => 'company_uuid',
        'user_uuid'    => 'user_uuid',
        'name'         => 'Ada Buyer',
        'email'        => 'ada@example.test',
        'type'         => 'customer',
    ]);
    session(['company' => 'company_uuid']);

    $resource = (new CustomerController())->loginWithFacebook(Request::create(
        '/customer/facebook',
        'POST',
        [
            'email'          => 'ada@example.test',
            'name'           => 'Ada Buyer',
            'facebookUserId' => 'facebook_123',
        ]
    ));
    $socialController = new SocialCustomerControllerStub();
    $apple            = $socialController->loginWithApple(Request::create('/customer/apple', 'POST', [
        'identityToken'     => 'valid-apple-token',
        'authorizationCode' => 'authorization-code',
        'email'             => 'ada@example.test',
        'appleUserId'       => 'apple_123',
    ]));
    $socialController->googlePayload = [
        'email'   => 'ada@example.test',
        'name'    => 'Ada Buyer',
        'sub'     => 'google_123',
        'picture' => 'https://cdn.test/avatar.png',
    ];
    $google = $socialController->loginWithGoogle(Request::create('/customer/google', 'POST', [
        'idToken' => 'valid-google-token',
        'clientId'=> 'client-id',
    ]));
    $previousDispatcher = Model::getEventDispatcher();
    Model::setEventDispatcher(new Illuminate\Events\Dispatcher(app()));
    Fleetbase\Models\User::creating(function ($user) {
        $user->uuid ??= (string) Illuminate\Support\Str::uuid();
    });
    Fleetbase\FleetOps\Models\Contact::creating(function ($contact) {
        $contact->uuid ??= (string) Illuminate\Support\Str::uuid();
    });
    $newApple = $socialController->loginWithApple(Request::create('/customer/apple', 'POST', [
        'identityToken'     => 'new-apple-token',
        'authorizationCode' => 'new-authorization-code',
        'name'              => 'Apple Buyer',
        'phone'             => '+97699110001',
        'appleUserId'       => 'apple_new',
    ]));
    $newFacebook = $socialController->loginWithFacebook(Request::create('/customer/facebook', 'POST', [
        'name'           => 'Facebook Buyer',
        'facebookUserId' => 'facebook_new',
    ]));
    $socialController->googlePayload = [
        'name'    => 'Google Buyer',
        'sub'     => 'google_new',
        'picture' => 'https://cdn.test/new-avatar.png',
    ];
    $newGoogle = $socialController->loginWithGoogle(Request::create('/customer/google', 'POST', [
        'idToken' => 'new-google-token',
        'clientId'=> 'client-id',
    ]));
    if ($previousDispatcher) {
        Model::setEventDispatcher($previousDispatcher);
    } else {
        Model::unsetEventDispatcher();
    }
    $linkedUser = $connection->table('users')->where('uuid', 'user_uuid')->first();
    $schema->drop('users');
    $appleFailure = $socialController->loginWithApple(Request::create('/customer/apple', 'POST', [
        'identityToken'     => 'apple-failure-token',
        'authorizationCode' => 'authorization-code',
        'appleUserId'       => 'apple_failure',
    ]));
    $facebookFailure = $socialController->loginWithFacebook(Request::create('/customer/facebook', 'POST', [
        'facebookUserId' => 'facebook_failure',
    ]));
    $socialController->googlePayload = ['sub' => 'google_failure'];
    $googleFailure                   = $socialController->loginWithGoogle(Request::create('/customer/google', 'POST', [
        'idToken' => 'google-failure-token',
        'clientId'=> 'client-id',
    ]));
    expect($resource)->toBeInstanceOf(Fleetbase\Storefront\Http\Resources\Customer::class)
        ->and($resource->resource->uuid)->toBe('contact_uuid')
        ->and($resource->resource->token)->not->toBeEmpty()
        ->and($linkedUser->facebook_user_id)->toBe('facebook_123')
        ->and($apple)->toBeInstanceOf(Fleetbase\Storefront\Http\Resources\Customer::class)
        ->and($linkedUser->apple_user_id)->toBe('apple_123')
        ->and($google)->toBeInstanceOf(Fleetbase\Storefront\Http\Resources\Customer::class)
        ->and($linkedUser->google_user_id)->toBe('google_123')
        ->and($newApple)->toBeInstanceOf(Fleetbase\Storefront\Http\Resources\Customer::class)
        ->and($newFacebook)->toBeInstanceOf(Fleetbase\Storefront\Http\Resources\Customer::class)
        ->and($newGoogle)->toBeInstanceOf(Fleetbase\Storefront\Http\Resources\Customer::class)
        ->and($appleFailure->getData(true))->toHaveKey('error')
        ->and($facebookFailure->getData(true))->toHaveKey('error')
        ->and($googleFailure->getData(true))->toHaveKey('error')
        ->and($connection->table('personal_access_tokens')->count())->toBe(6);
});

test('customer creation-code requests reject malformed email identities before delivery', function () {
    session(['storefront_key' => null]);
    $response = (new CustomerController())->requestCustomerCreationCode(
        VerifyCreateCustomerRequest::create('/customer/code', 'POST', [
            'mode'     => 'email',
            'identity' => 'not-an-email',
        ])
    );

    expect($response->getData(true))->toBe([
        'error' => 'Invalid email provided for identity',
    ]);
});

test('customer creation-code requests generate email and SMS verification records', function () {
    createCustomerVerificationDeliverySchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
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
    bindCustomerNotificationDispatcher();
    $controller = new CustomerController();

    $email = $controller->requestCustomerCreationCode(
        VerifyCreateCustomerRequest::create('/customer/code', 'POST', [
            'mode'     => 'email',
            'identity' => 'buyer@example.test',
        ])
    );
    $sms = $controller->requestCustomerCreationCode(
        VerifyCreateCustomerRequest::create('/customer/code', 'POST', [
            'mode'     => 'sms',
            'identity' => '97699112233',
        ])
    );
    app()->offsetUnset(Illuminate\Contracts\Notifications\Dispatcher::class);

    $records = $connection->table('verification_codes')
        ->where('for', 'storefront_create_customer')
        ->orderBy('id')
        ->get();
    app()->instance('twilio', new class {
        public function message(string $to, string $message, array $media = [], array $params = []): object
        {
            throw new Twilio\Exceptions\RestException('Twilio rejected the destination', 21211, 400);
        }
    });
    Illuminate\Support\Facades\Facade::clearResolvedInstance('twilio');
    $twilioFailure = $controller->requestCustomerCreationCode(
        VerifyCreateCustomerRequest::create('/customer/code', 'POST', [
            'mode'     => 'sms',
            'identity' => '97699000000',
        ])
    );
    $connection->getSchemaBuilder()->drop('verification_codes');
    $deliveryFailure = $controller->requestCustomerCreationCode(
        VerifyCreateCustomerRequest::create('/customer/code', 'POST', [
            'mode'     => 'email',
            'identity' => 'failure@example.test',
        ])
    );
    expect($email->getData(true))->toBe(['status' => 'ok'])
        ->and($sms->getData(true))->toBe(['status' => 'ok'])
        ->and($records)->toHaveCount(2)
        ->and(json_decode($records[0]->meta, true)['identity'])->toBe('buyer@example.test')
        ->and(json_decode($records[1]->meta, true)['identity'])->toBe('+97699112233')
        ->and($twilioFailure->getData(true))->toBe(['error' => 'Twilio rejected the destination'])
        ->and($deliveryFailure->getData(true))->toHaveKey('error');
});

test('customer creation rejects unverified identities before creating users or contacts', function () {
    $schema = Model::getConnectionResolver()->connection('mysql')->getSchemaBuilder();
    $schema->dropIfExists('verification_codes');
    $schema->create('verification_codes', function ($table) {
        $table->increments('id');
        $table->string('code')->nullable();
        $table->string('for')->nullable();
        $table->text('meta')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    session(['storefront_key' => null]);

    $response = (new CustomerController())->create(
        CreateCustomerRequest::create('/customer', 'POST', [
            'identity' => 'buyer@example.test',
            'code'     => 'invalid-code',
            'email'    => 'buyer@example.test',
            'name'     => 'Buyer',
        ])
    );

    expect($response->getData(true))->toBe([
        'error' => 'Invalid verification code provided!',
    ]);
});

test('customer creation falls back to the payload email when no identity is sent', function () {
    // A body of {name, email, code} used to leave $identity null, static::phone() turned it
    // into the literal '+', and the lookup on meta->identity could never match — so a
    // correctly issued code was rejected.
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('verification_codes');
    $schema->create('verification_codes', function ($table) {
        $table->increments('id');
        $table->string('code')->nullable();
        $table->string('for')->nullable();
        $table->text('meta')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $connection->table('verification_codes')->insert([
        'code'       => '123456',
        'for'        => 'storefront_create_customer',
        'meta'       => json_encode(['identity' => 'buyer@example.test']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    session(['storefront_key' => null]);

    $matched = (new CustomerController())->create(
        CreateCustomerRequest::create('/customer', 'POST', [
            'code'  => '123456',
            'email' => 'buyer@example.test',
            'name'  => 'Buyer',
        ])
    );

    $missing = (new CustomerController())->create(
        CreateCustomerRequest::create('/customer', 'POST', [
            'code' => '123456',
            'name' => 'Buyer',
        ])
    );

    // The fallback got past the code check — whatever it fails on next, it is no longer
    // "Invalid verification code provided!".
    expect(data_get($matched->getData(true), 'error'))->not->toBe('Invalid verification code provided!')
        ->and($missing->getData(true))->toBe(['error' => 'An identity is required to create a customer.']);
});

test('customer creation persists a verified storefront identity and issues an access token', function () {
    createCustomerVerificationDeliverySchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    foreach (['personal_access_tokens', 'contacts', 'files'] as $table) {
        $schema->dropIfExists($table);
    }
    $schema->create('files', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('slug')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('uploader_uuid')->nullable();
        $table->string('disk')->nullable();
        $table->string('original_filename')->nullable();
        $table->string('extension')->nullable();
        $table->string('content_type')->nullable();
        $table->string('path')->nullable();
        $table->string('bucket')->nullable();
        $table->string('type')->nullable();
        $table->integer('file_size')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('contacts', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id')->nullable();
        $table->string('company_uuid');
        $table->string('user_uuid');
        $table->string('name')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->string('type')->nullable();
        $table->text('meta')->nullable();
        $table->string('photo_uuid')->nullable();
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
    $connection->table('stores')->insert([
        'uuid'         => 'store_uuid',
        'public_id'    => 'store_public',
        'company_uuid' => 'company_uuid',
        'key'          => 'store_key',
        'name'         => 'Corner Store',
    ]);
    $connection->table('files')->insert([
        'uuid'      => 'customer_photo_uuid',
        'public_id' => 'file_abcdefgh',
    ]);
    $connection->table('verification_codes')->insert([
        'uuid'       => 'verification_uuid',
        'code'       => '123456',
        'for'        => 'storefront_create_customer',
        'status'     => 'pending',
        'meta'       => json_encode(['identity' => 'buyer@example.test']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    session([
        'company'        => 'company_uuid',
        'storefront_key' => 'store_key',
    ]);
    $previousDispatcher = Model::getEventDispatcher();
    Model::setEventDispatcher(new Illuminate\Events\Dispatcher(app()));
    Fleetbase\Models\User::creating(function ($user) {
        $user->uuid ??= (string) Illuminate\Support\Str::uuid();
    });
    Fleetbase\FleetOps\Models\Contact::creating(function ($contact) {
        $contact->uuid ??= (string) Illuminate\Support\Str::uuid();
    });

    $resource = (new CustomerController())->verifyCode(
        CreateCustomerRequest::create('/customer', 'POST', [
            'identity' => 'buyer@example.test',
            'code'     => '123456',
            'for'      => 'storefront_create_customer',
            'email'    => 'buyer@example.test',
            'phone'    => '97699112233',
            'name'     => 'Verified Buyer',
            'photo'    => 'file_abcdefgh',
        ])
    );
    Model::setEventDispatcher(new Illuminate\Events\Dispatcher(app()));
    Fleetbase\Models\User::creating(function ($user) {
        $user->uuid ??= (string) Illuminate\Support\Str::uuid();
    });
    Fleetbase\FleetOps\Models\Contact::creating(function ($contact) {
        $contact->uuid ??= (string) Illuminate\Support\Str::uuid();
    });
    Fleetbase\Models\File::creating(function ($file) {
        $file->uuid ??= (string) Illuminate\Support\Str::uuid();
        $file->public_id ??= 'file_' . Illuminate\Support\Str::lower(Illuminate\Support\Str::random(10));
    });
    $uploadRoot = sys_get_temp_dir() . '/storefront-customer-test-uploads';
    config([
        'filesystems.default'       => 'uploads',
        'filesystems.disks.uploads' => ['driver' => 'local', 'root' => $uploadRoot],
    ]);
    $configRepository = new Illuminate\Config\Repository(config());
    app()->instance('config', $configRepository);
    app()->instance(Illuminate\Contracts\Config\Repository::class, $configRepository);
    app()->instance('filesystem', new Illuminate\Filesystem\FilesystemManager(app()));
    app()->instance('responsecache', new class {
        public function clear(): void
        {
        }
    });
    Illuminate\Support\Facades\Storage::forgetDisk('uploads');
    $connection->table('verification_codes')->insert([
        'uuid'       => 'base64_photo_verification_uuid',
        'code'       => '333333',
        'for'        => 'storefront_create_customer',
        'status'     => 'pending',
        'meta'       => json_encode(['identity' => 'photo@example.test']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $base64PhotoResource = (new CustomerController())->create(
        CreateCustomerRequest::create('/customer', 'POST', [
            'identity' => 'photo@example.test',
            'code'     => '333333',
            'email'    => 'photo@example.test',
            'name'     => 'Photo Buyer',
            'photo'    => base64_encode('customer-photo'),
        ])
    );
    Fleetbase\FleetOps\Models\Contact::creating(function ($contact) use ($connection) {
        if ($contact->email === 'race-recovered@example.test') {
            $connection->table('contacts')->insert([
                'uuid'         => 'race_recovered_contact_uuid',
                'company_uuid' => $contact->company_uuid,
                'user_uuid'    => $contact->user_uuid,
                'name'         => $contact->name,
                'email'        => $contact->email,
                'type'         => 'customer',
                'meta'         => json_encode($contact->meta),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

            throw new Fleetbase\FleetOps\Exceptions\UserAlreadyExistsException('Concurrent contact creation');
        }

        if ($contact->email === 'race-failed@example.test') {
            throw new Fleetbase\FleetOps\Exceptions\UserAlreadyExistsException('Conflicting customer already exists');
        }
    });
    foreach ([
        ['uuid' => 'race_recovered_verification_uuid', 'code' => '444444', 'identity' => 'race-recovered@example.test'],
        ['uuid' => 'race_failed_verification_uuid', 'code' => '555555', 'identity' => 'race-failed@example.test'],
    ] as $verification) {
        $connection->table('verification_codes')->insert([
            'uuid'       => $verification['uuid'],
            'code'       => $verification['code'],
            'for'        => 'storefront_create_customer',
            'status'     => 'pending',
            'meta'       => json_encode(['identity' => $verification['identity']]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    $raceRecovered = (new CustomerController())->create(
        CreateCustomerRequest::create('/customer', 'POST', [
            'identity' => 'race-recovered@example.test',
            'code'     => '444444',
            'email'    => 'race-recovered@example.test',
            'name'     => 'Recovered Buyer',
        ])
    );
    $raceFailed = (new CustomerController())->create(
        CreateCustomerRequest::create('/customer', 'POST', [
            'identity' => 'race-failed@example.test',
            'code'     => '555555',
            'email'    => 'race-failed@example.test',
            'name'     => 'Conflicting Buyer',
        ])
    );
    $connection->table('users')->insert([
        'uuid'        => 'existing_phone_user_uuid',
        'company_uuid'=> 'company_uuid',
        'phone'       => '+97699887766',
        'type'        => null,
        'created_at'  => now(),
        'updated_at'  => now(),
    ]);
    $connection->table('verification_codes')->insert([
        'uuid'       => 'phone_verification_uuid',
        'code'       => '654321',
        'for'        => 'storefront_create_customer',
        'status'     => 'pending',
        'meta'       => json_encode(['identity' => '+97699887766']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $phoneResource = (new CustomerController())->create(
        CreateCustomerRequest::create('/customer', 'POST', [
            'identity' => '97699887766',
            'code'     => '654321',
            'phone'    => '97699887766',
            'name'     => 'Existing Phone Buyer',
        ])
    );
    $connection->table('verification_codes')->insert([
        'uuid'       => 'token_failure_verification_uuid',
        'code'       => '111111',
        'for'        => 'storefront_create_customer',
        'status'     => 'pending',
        'meta'       => json_encode(['identity' => 'token-failure@example.test']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $schema->drop('personal_access_tokens');
    $tokenFailure = (new CustomerController())->create(
        CreateCustomerRequest::create('/customer', 'POST', [
            'identity' => 'token-failure@example.test',
            'code'     => '111111',
            'email'    => 'token-failure@example.test',
            'name'     => 'Token Failure Buyer',
        ])
    );
    $connection->table('verification_codes')->insert([
        'uuid'       => 'contact_failure_verification_uuid',
        'code'       => '222222',
        'for'        => 'storefront_create_customer',
        'status'     => 'pending',
        'meta'       => json_encode(['identity' => 'contact-failure@example.test']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $connection->statement(
        "CREATE TRIGGER fail_customer_contact_insert BEFORE INSERT ON contacts BEGIN SELECT RAISE(ABORT, 'contact insert failed'); END"
    );
    $contactFailure = (new CustomerController())->create(
        CreateCustomerRequest::create('/customer', 'POST', [
            'identity' => 'contact-failure@example.test',
            'code'     => '222222',
            'email'    => 'contact-failure@example.test',
            'name'     => 'Contact Failure Buyer',
        ])
    );
    Illuminate\Support\Facades\Storage::disk('uploads')->deleteDirectory('');
    if ($previousDispatcher) {
        Model::setEventDispatcher($previousDispatcher);
    } else {
        Model::unsetEventDispatcher();
    }

    expect($resource)->toBeInstanceOf(Fleetbase\Storefront\Http\Resources\Customer::class)
        ->and($resource->resource->email)->toBe('buyer@example.test')
        ->and($resource->resource->phone)->toBe('+97699112233')
        ->and($resource->resource->type)->toBe('customer')
        ->and($resource->resource->photo_uuid)->toBe('customer_photo_uuid')
        ->and($resource->resource->token)->not->toBeEmpty()
        ->and($base64PhotoResource)->toBeInstanceOf(Fleetbase\Storefront\Http\Resources\Customer::class)
        ->and($base64PhotoResource->resource->photo_uuid)->not->toBeNull()
        ->and($raceRecovered)->toBeInstanceOf(Fleetbase\Storefront\Http\Resources\Customer::class)
        ->and($raceRecovered->resource->uuid)->toBe('race_recovered_contact_uuid')
        ->and($raceFailed->getData(true))->toBe(['error' => 'Conflicting customer already exists'])
        ->and($phoneResource)->toBeInstanceOf(Fleetbase\Storefront\Http\Resources\Customer::class)
        ->and($phoneResource->resource->phone)->toBe('+97699887766')
        ->and($tokenFailure->getData(true))->toHaveKey('error')
        ->and($contactFailure->getData(true))->toHaveKey('error')
        ->and($connection->table('users')->where('email', 'buyer@example.test')->value('type'))->toBe('customer')
        ->and($connection->table('users')->where('uuid', 'existing_phone_user_uuid')->value('type'))->toBe('customer')
    ;
});

test('customer account closure endpoints reject requests outside a storefront context', function () {
    session(['storefront_key' => null]);
    $controller = new CustomerController();

    $start   = $controller->startAccountClosure(Request::create('/customer/closure', 'POST'));
    $confirm = $controller->confirmAccountClosure(Request::create('/customer/closure/confirm', 'POST', [
        'code' => '123456',
    ]));

    expect($start->getData(true))->toBe(['error' => 'Storefront not found.'])
        ->and($confirm->getData(true))->toBe(['error' => 'Storefront not found.']);
});

test('customer account closure endpoints require an authenticated customer token', function () {
    $schema = Model::getConnectionResolver()->connection('mysql')->getSchemaBuilder();
    $schema->dropIfExists('stores');
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
    Model::getConnectionResolver()->connection('mysql')->table('stores')->insert([
        'uuid'         => 'store_uuid',
        'public_id'    => 'store_public',
        'company_uuid' => 'company_uuid',
        'key'          => 'store_key',
        'name'         => 'Corner Store',
    ]);
    session(['storefront_key' => 'store_key']);
    bindUnauthenticatedCustomerRequest();
    $controller = new CustomerController();

    $start   = $controller->startAccountClosure(Request::create('/customer/closure', 'POST'));
    $confirm = $controller->confirmAccountClosure(Request::create('/customer/closure/confirm', 'POST', [
        'code' => '123456',
    ]));

    expect($start->getData(true))->toBe(['error' => 'Not authorized to view customers places'])
        ->and($confirm->getData(true))->toBe(['error' => 'Not authorized to view customers places']);
});

test('customer password and phone login fail safely for unknown identities', function () {
    createCustomerControllerUsersSchema();
    bindUnauthenticatedCustomerRequest(['phone' => '97699112233']);
    $controller = new CustomerController();

    $password = $controller->login(Request::create('/customer/login', 'POST', [
        'identity' => 'missing@example.com',
        'password' => 'invalid-password',
    ]));
    $phone = $controller->loginWithPhone();

    expect($password->getStatusCode())->toBe(401)
        ->and($password->getData(true))->toBe(['error' => 'Authentication failed using password provided.'])
        ->and($phone->getData(true))->toBe(['error' => 'No customer with this phone # found.']);
});

test('customer phone login generates a storefront-scoped SMS verification code', function () {
    createCustomerVerificationDeliverySchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('stores')->insert([
        'uuid'         => 'store_uuid',
        'public_id'    => 'store_public',
        'company_uuid' => 'company_uuid',
        'key'          => 'store_key',
        'name'         => 'Corner Store',
    ]);
    $connection->table('users')->insert([
        'uuid'       => 'user_uuid',
        'name'       => 'Ada Buyer',
        'phone'      => '+97699112233',
        'type'       => 'customer',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    session([
        'company'        => 'company_uuid',
        'storefront_key' => 'store_key',
    ]);
    bindUnauthenticatedCustomerRequest(['phone' => '97699112233']);
    bindCustomerNotificationDispatcher();

    $response = (new CustomerController())->loginWithPhone();
    app()->offsetUnset(Illuminate\Contracts\Notifications\Dispatcher::class);

    // No phone in the request at all. static::phone() returns null rather than the bare
    // '+' it used to, and `where('phone', null)` compiles to `phone IS NULL` — which would
    // hand back an arbitrary phone-less user and send them a login code.
    bindUnauthenticatedCustomerRequest([]);
    $withoutPhone = (new CustomerController())->loginWithPhone();

    expect($response->getData(true))->toBe(['status' => 'OK', 'method' => 'sms'])
        ->and($withoutPhone->getData(true))->toBe(['error' => 'No customer with this phone # found.'])
        ->and($connection->table('verification_codes')->where([
            'subject_uuid' => 'user_uuid',
            'for'          => 'storefront_login',
        ])->count())->toBe(1);
});

test('customer phone login falls back to email when SMS is not configured', function () {
    // The Twilio SDK THROWS when the store has no credentials — ConfigurationException,
    // "Credentials are required to create a Client" — and that propagated as a 500 with an
    // HTML stack trace. A store that has simply not set up SMS is not a server error, and
    // it can still reach a customer who has an email address.
    createCustomerVerificationDeliverySchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('stores')->insert([
        'uuid'         => 'store_uuid',
        'public_id'    => 'store_public',
        'company_uuid' => 'company_uuid',
        'key'          => 'store_key',
        'name'         => 'Corner Store',
    ]);
    $connection->table('users')->insert([
        'uuid'       => 'user_uuid',
        'name'       => 'Ada Buyer',
        'phone'      => '+97699112233',
        'email'      => 'ada@example.test',
        'type'       => 'customer',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    session([
        'company'        => 'company_uuid',
        'storefront_key' => 'store_key',
    ]);
    bindUnauthenticatedCustomerRequest(['phone' => '97699112233']);
    bindCustomerNotificationDispatcher();

    // Replace the working twilio fake with one that fails the way an unconfigured
    // install does.
    app()->instance('twilio', new class {
        public function message(string $to, string $message, array $media = [], array $params = []): object
        {
            throw new RuntimeException('Credentials are required to create a Client');
        }
    });
    Illuminate\Support\Facades\Facade::clearResolvedInstance('twilio');

    $response = (new CustomerController())->loginWithPhone();

    // Restore the container before asserting. The whole file runs in ONE process, so
    // anything left bound here leaks into every later test — including the working
    // `mail.manager` that bindCustomerNotificationDispatcher() installs, which would make
    // a later test's deliberately-failing email delivery succeed instead.
    app()->forgetInstance('twilio');
    app()->forgetInstance('mail.manager');
    Illuminate\Support\Facades\Facade::clearResolvedInstance('twilio');
    Illuminate\Support\Facades\Facade::clearResolvedInstance('mail.manager');
    app()->offsetUnset(Illuminate\Contracts\Notifications\Dispatcher::class);

    // Two rows, not one: generateSmsVerificationFor persists the code BEFORE attempting
    // delivery, so the failed SMS attempt leaves its row behind and the email fallback
    // adds another. Both are valid for this subject and purpose, so either verifies —
    // FleetOps' driver login behaves identically.
    expect($response->getData(true))->toBe(['status' => 'OK', 'method' => 'email'])
        ->and($connection->table('verification_codes')->where([
            'subject_uuid' => 'user_uuid',
            'for'          => 'storefront_login',
        ])->count())->toBe(2);
});

test('customer phone login reports both delivery failures without leaking provider exceptions', function () {
    createCustomerVerificationDeliverySchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('stores')->insert([
        'uuid'         => 'store_uuid',
        'public_id'    => 'store_public',
        'company_uuid' => 'company_uuid',
        'key'          => 'store_key',
        'name'         => 'Corner Store',
    ]);
    $connection->table('users')->insert([
        'uuid'       => 'user_uuid',
        'name'       => 'Ada Buyer',
        'phone'      => '+97699112233',
        'email'      => 'ada@example.test',
        'type'       => 'customer',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    session([
        'company'        => 'company_uuid',
        'storefront_key' => 'store_key',
    ]);
    bindUnauthenticatedCustomerRequest(['phone' => '97699112233']);
    $sentry = new class {
        public array $exceptions = [];

        public function captureException(Throwable $exception): void
        {
            $this->exceptions[] = $exception;
        }
    };
    app()->instance('sentry', $sentry);
    app()->instance('twilio', new class {
        public function message(string $to, string $message, array $media = [], array $params = []): object
        {
            throw new RuntimeException('SMS provider unavailable');
        }
    });
    Illuminate\Support\Facades\Facade::clearResolvedInstance('twilio');
    app()->instance(
        Illuminate\Contracts\Notifications\Dispatcher::class,
        new class implements Illuminate\Contracts\Notifications\Dispatcher {
            public function send($notifiables, $notification)
            {
                throw new RuntimeException('Email provider unavailable');
            }

            public function sendNow($notifiables, $notification)
            {
                throw new RuntimeException('Email provider unavailable');
            }
        }
    );

    $response = (new CustomerController())->loginWithPhone();

    app()->forgetInstance('sentry');
    app()->forgetInstance('twilio');
    app()->offsetUnset(Illuminate\Contracts\Notifications\Dispatcher::class);
    Illuminate\Support\Facades\Facade::clearResolvedInstance('twilio');

    expect($response->getData(true))->toBe(['error' => 'Unable to send verification code.'])
        ->and($sentry->exceptions)->toHaveCount(2);
});

test('phone verification honours the review account bypass at both ends', function () {
    // The bypass exists so App Store review can complete flows that would otherwise need a
    // live SMS provider. It was applied to verifyCode and confirmAccountClosure but never
    // to phone verification, so that flow still required Twilio.
    config([
        'storefront.storefront_app.bypass_verification_code' => '000000',
        'storefront.storefront_app.review_accounts'          => ['+97699112233'],
    ]);

    createCustomerVerificationDeliverySchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    // The delivery schema covers stores/companies/users/verification_codes; the
    // authenticated-customer path also needs a contact and a Sanctum token.
    foreach (['contacts', 'personal_access_tokens'] as $table) {
        $schema->dropIfExists($table);
    }
    $schema->create('contacts', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('type')->nullable();
        $table->string('name')->nullable();
        $table->string('phone')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });
    // verifyPhoneNumber stamps phone_verified_at, which the shared users schema omits.
    $schema->table('users', function ($table) {
        $table->timestamp('phone_verified_at')->nullable();
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
    $connection->table('stores')->insert([
        'uuid'         => 'store_uuid',
        'public_id'    => 'store_public',
        'company_uuid' => 'company_uuid',
        'key'          => 'store_key',
        'name'         => 'Corner Store',
    ]);
    $connection->table('users')->insert([
        'uuid'       => 'user_uuid',
        'name'       => 'Ada Buyer',
        'email'      => 'ada@example.test',
        'type'       => 'customer',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    // A real uuid: Storefront::getCustomerFromToken() only resolves the contact from the
    // token's name when Str::isUuid() passes, and silently falls through otherwise.
    $connection->table('contacts')->insert([
        'uuid'         => '8f14e45f-ceea-467a-9f4d-2b5c1e0a77aa',
        'public_id'    => 'customer_public',
        'company_uuid' => 'company_uuid',
        'user_uuid'    => 'user_uuid',
        'type'         => 'customer',
        'name'         => 'Ada Buyer',
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
    $connection->table('personal_access_tokens')->insert([
        'name'       => '8f14e45f-ceea-467a-9f4d-2b5c1e0a77aa',
        'token'      => hash('sha256', 'phone-verify-secret'),
        'abilities'  => '["*"]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    session(['company' => 'company_uuid', 'storefront_key' => 'store_key']);

    $authenticate = function (array $input) {
        $request = bindUnauthenticatedCustomerRequest($input);
        $request->headers->set('Customer-Token', 'phone-verify-secret');
        app()->instance('request', $request);

        return $request;
    };

    // No SMS provider is bound at all — the send must not need one for a review account.
    $sent = (new CustomerController())->requestPhoneVerification(
        $authenticate(['phone' => '97699112233'])
    );

    expect($sent->getData(true))->toBe(['status' => 'ok', 'method' => 'bypass'])
        // and nothing was queued for delivery
        ->and($connection->table('verification_codes')->count())->toBe(0);

    $verified = (new CustomerController())->verifyPhoneNumber(
        $authenticate(['phone' => '97699112233', 'code' => '000000'])
    );

    expect($verified)->toBeInstanceOf(Fleetbase\Storefront\Http\Resources\Customer::class)
        ->and($connection->table('users')->where('uuid', 'user_uuid')->value('phone'))->toBe('+97699112233')
        ->and($connection->table('users')->where('uuid', 'user_uuid')->value('phone_verified_at'))->not->toBeNull();

    // A code that is not the bypass, for the same account, is still rejected.
    $rejected = (new CustomerController())->verifyPhoneNumber(
        $authenticate(['phone' => '97699112233', 'code' => '111111'])
    );
    expect($rejected->getData(true))->toBe(['error' => 'Invalid verification code!']);

    // No phone to verify. static::phone() returns null rather than the bare '+' it used
    // to, so this has to be caught before findExistingUserByPhone(string $phone) is
    // reached — and long before the SMS provider is.
    $noPhone = (new CustomerController())->requestPhoneVerification($authenticate([]));
    expect($noPhone->getData(true))->toBe(['error' => 'A phone number is required to request verification.']);

    // A verification row that carries no meta.phone. requestPhoneVerification always
    // writes one, but anything else that files a storefront_verify_phone code may not,
    // and the subscript used to be unguarded — a 500 where a 400 belongs.
    $connection->table('verification_codes')->insert([
        'uuid'         => 'verification_without_phone',
        'subject_uuid' => 'user_uuid',
        'subject_type' => Fleetbase\Models\User::class,
        'code'         => '222222',
        'for'          => 'storefront_verify_phone',
        'meta'         => json_encode([]),
        'expires_at'   => now()->addHour(),
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    $noMetaPhone = (new CustomerController())->verifyPhoneNumber(
        $authenticate(['phone' => '97699112233', 'code' => '222222'])
    );
    expect($noMetaPhone->getData(true))->toBe(['error' => 'Verification code is not associated with a phone number.']);

    config([
        'storefront.storefront_app.bypass_verification_code' => null,
        'storefront.storefront_app.review_accounts'          => [],
    ]);
});

test('customer password login reuses the storefront contact and issues an access token', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    foreach (['personal_access_tokens', 'verification_codes', 'contacts', 'users', 'stores'] as $table) {
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
        $table->softDeletes();
    });
    $schema->create('users', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->string('password')->nullable();
        $table->string('type')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('contacts', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id')->nullable();
        $table->string('company_uuid');
        $table->string('user_uuid');
        $table->string('name')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->string('type')->nullable();
        $table->text('meta')->nullable();
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
    $schema->create('verification_codes', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('code')->nullable();
        $table->string('for')->nullable();
        $table->text('meta')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $connection->table('stores')->insert([
        'uuid'         => 'store_uuid',
        'public_id'    => 'store_public',
        'company_uuid' => 'company_uuid',
        'key'          => 'store_key',
        'name'         => 'Corner Store',
    ]);
    $connection->table('users')->insert([
        'uuid'       => 'user_uuid',
        'name'       => 'Ada Buyer',
        'email'      => 'ada@example.test',
        'password'   => password_hash('correct-password', PASSWORD_BCRYPT),
        'type'       => 'customer',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $connection->table('contacts')->insert([
        'uuid'         => 'contact_uuid',
        'public_id'    => 'contact_public',
        'company_uuid' => 'company_uuid',
        'user_uuid'    => 'user_uuid',
        'name'         => 'Ada Buyer',
        'email'        => 'ada@example.test',
        'type'         => 'customer',
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
    $connection->table('verification_codes')->insert([
        'subject_uuid' => 'user_uuid',
        'code'         => '123456',
        'for'          => 'storefront_login',
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
    session(['company' => 'company_uuid', 'storefront_key' => 'store_key']);

    $resource = (new CustomerController())->login(Request::create('/customer/login', 'POST', [
        'identity' => 'ada@example.test',
        'password' => 'correct-password',
    ]));
    $invalidCode = (new CustomerController())->verifyCode(Request::create('/customer/code', 'POST', [
        'identity' => 'ada@example.test',
        'code'     => '000000',
    ]));
    $verified = (new CustomerController())->verifyCode(Request::create('/customer/code', 'POST', [
        'identity' => 'ada@example.test',
        'code'     => '123456',
    ]));
    $tokenCount = $connection->table('personal_access_tokens')->count();
    $schema->drop('personal_access_tokens');
    $loginTokenFailure = (new CustomerController())->login(Request::create('/customer/login', 'POST', [
        'identity' => 'ada@example.test',
        'password' => 'correct-password',
    ]));
    $verificationTokenFailure = (new CustomerController())->verifyCode(Request::create('/customer/code', 'POST', [
        'identity' => 'ada@example.test',
        'code'     => '123456',
    ]));

    // No identity at all. The lookup below the guard is `phone = $identity OR email =
    // $identity`, which with null compiles to `phone IS NULL OR email IS NULL` and picks an
    // arbitrary user to test the code against.
    //
    // The request has to be BOUND, not just passed: static::phone() falls back to
    // request()->input('phone'), so an earlier bound request carrying a phone would supply
    // an identity this call never sent. Last in the test so the rebind affects nothing else.
    $withoutIdentityRequest = Request::create('/customer/code', 'POST', ['code' => '123456']);
    app()->instance('request', $withoutIdentityRequest);
    $withoutIdentity = (new CustomerController())->verifyCode($withoutIdentityRequest);

    expect($resource)->toBeInstanceOf(Fleetbase\Storefront\Http\Resources\Customer::class)
        ->and($resource->resource->uuid)->toBe('contact_uuid')
        ->and($resource->resource->token)->not->toBeEmpty()
        ->and($invalidCode->getData(true))->toBe(['error' => 'Invalid verification code!'])
        ->and($withoutIdentity->getData(true))->toBe(['error' => 'Unable to verify code.'])
        ->and($verified)->toBeInstanceOf(Fleetbase\Storefront\Http\Resources\Customer::class)
        ->and($verified->resource->token)->not->toBeEmpty()
        ->and($tokenCount)->toBe(2)
        ->and($loginTokenFailure->getData(true))->toHaveKey('error')
        ->and($verificationTokenFailure->getData(true))->toHaveKey('error');
});

test('customer public id aliases preserve not-found update find and delete contracts', function () {
    createCustomerControllerContactsSchema();
    session(['company' => null]);
    $controller = new CustomerController();
    $update     = UpdateContactRequest::create('/customer/customer_missing', 'PATCH');

    $updated = $controller->update('customer_missing', $update);
    $found   = $controller->find('customer_missing');
    $deleted = $controller->delete('customer_missing');

    expect($updated->getData(true))->toBe(['error' => 'Customer resource not found.'])
        ->and($found->getData(true))->toBe(['error' => 'Customer resource not found.'])
        ->and($deleted->getData(true))->toBe(['error' => 'Customer resource not found.']);
});

test('customer update find and delete persist profile location and photo removal contracts', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('files');
    $schema->dropIfExists('places');
    $schema->dropIfExists('contacts');
    $schema->create('contacts', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('type')->nullable();
        $table->string('title')->nullable();
        $table->string('name')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->string('place_uuid')->nullable();
        $table->string('photo_uuid')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('places', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('files', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('slug')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('uploader_uuid')->nullable();
        $table->string('disk')->nullable();
        $table->string('original_filename')->nullable();
        $table->string('extension')->nullable();
        $table->string('content_type')->nullable();
        $table->string('path')->nullable();
        $table->string('bucket')->nullable();
        $table->string('type')->nullable();
        $table->integer('file_size')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $connection->table('contacts')->insert([
        'uuid'         => 'contact_uuid',
        'public_id'    => 'contact_public',
        'company_uuid' => 'company_uuid',
        'type'         => 'customer',
        'name'         => 'Old Name',
        'photo_uuid'   => 'old_photo_uuid',
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
    $connection->table('places')->insert([
        'uuid'         => 'place_uuid',
        'public_id'    => 'place_public',
        'company_uuid' => 'company_uuid',
    ]);
    $connection->table('files')->insert([
        'uuid'      => 'new_photo_uuid',
        'public_id' => 'file_abcdefgh',
    ]);
    session(['company' => 'company_uuid']);
    $controller = new CustomerController();
    $request    = UpdateContactRequest::create('/customer/contact_public', 'PATCH', [
        'name'  => 'Ada Buyer',
        'email' => 'ada@example.test',
        'place' => 'place_public',
        'photo' => 'REMOVE',
    ]);

    $updated      = $controller->update('contact_public', $request);
    $found        = $controller->find('customer_public');
    $photoUpdated = $controller->update(
        'contact_public',
        UpdateContactRequest::create('/customer/contact_public', 'PATCH', [
            'photo' => 'file_abcdefgh',
        ])
    );
    $previousDispatcher = Model::getEventDispatcher();
    Model::setEventDispatcher(new Illuminate\Events\Dispatcher(app()));
    Fleetbase\Models\File::creating(function ($file) {
        $file->uuid ??= (string) Illuminate\Support\Str::uuid();
        $file->public_id ??= 'file_' . Illuminate\Support\Str::lower(Illuminate\Support\Str::random(10));
    });
    $base64PhotoUpdated = $controller->update(
        'contact_public',
        UpdateContactRequest::create('/customer/contact_public', 'PATCH', [
            'photo' => base64_encode('updated-customer-photo'),
        ])
    );
    if ($previousDispatcher) {
        Model::setEventDispatcher($previousDispatcher);
    } else {
        Model::unsetEventDispatcher();
    }
    $connection->statement(
        "CREATE TRIGGER fail_customer_contact_update BEFORE UPDATE ON contacts BEGIN SELECT RAISE(ABORT, 'contact update failed'); END"
    );
    $updateFailure = $controller->update(
        'contact_public',
        UpdateContactRequest::create('/customer/contact_public', 'PATCH', [
            'name' => 'Rejected update',
        ])
    );
    $connection->statement('DROP TRIGGER fail_customer_contact_update');

    expect($updated)->toBeInstanceOf(Fleetbase\Storefront\Http\Resources\Customer::class)
        ->and($updated->resource->name)->toBe('Ada Buyer')
        ->and($updated->resource->type)->toBe('customer')
        ->and($updated->resource->place_uuid)->toBe('place_uuid')
        ->and($updated->resource->photo_uuid)->toBeNull()
        ->and($found)->toBeInstanceOf(Fleetbase\Storefront\Http\Resources\Customer::class)
        ->and($found->resource->uuid)->toBe('contact_uuid')
        ->and($photoUpdated->resource->photo_uuid)->toBe('new_photo_uuid')
        ->and($base64PhotoUpdated->resource->photo_uuid)->not->toBeNull()
        ->and($updateFailure->getData(true))->toHaveKey('error');

    $deleted = $controller->delete('customer_public');

    expect($deleted)->toBeInstanceOf(Fleetbase\FleetOps\Http\Resources\v1\DeletedResource::class)
        ->and($connection->table('contacts')->where('uuid', 'contact_uuid')->value('deleted_at'))->not->toBeNull();
});

test('customer phone normalization adds one international prefix', function () {
    expect(CustomerController::phone('97699112233'))->toBe('+97699112233')
        ->and(CustomerController::phone('+97699112233'))->toBe('+97699112233');

    bindUnauthenticatedCustomerRequest(['phone' => '15551234567']);

    expect(CustomerController::phone())->toBe('+15551234567');
});

test('customer phone normalization returns null when there is nothing to format', function () {
    // It used to return a bare '+', which was written into contacts.phone and users.phone
    // for every customer created without one, and used as a lookup key that never matched.
    bindUnauthenticatedCustomerRequest();

    expect(CustomerController::phone())->toBeNull()
        ->and(CustomerController::phone(''))->toBeNull();
});

test('customer code verification rejects identities without a user account', function () {
    createCustomerControllerUsersSchema();
    bindUnauthenticatedCustomerRequest();

    $response = (new CustomerController())->verifyCode(Request::create('/customer/code', 'POST', [
        'identity' => 'missing@example.com',
        'code'     => '123456',
    ]));

    expect($response->getData(true))->toBe(['error' => 'Unable to verify code.']);
});

test('customer query scopes records to customer type and active company', function () {
    createCustomerControllerContactsSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('contacts')->insert([
        [
            'uuid'         => 'customer_one',
            'public_id'    => 'contact_one',
            'company_uuid' => 'company_uuid',
            'type'         => 'customer',
        ],
        [
            'uuid'         => 'vendor_one',
            'public_id'    => 'contact_vendor',
            'company_uuid' => 'company_uuid',
            'type'         => 'vendor',
        ],
        [
            'uuid'         => 'customer_other',
            'public_id'    => 'contact_other',
            'company_uuid' => 'other_company',
            'type'         => 'customer',
        ],
    ]);
    session(['company' => 'company_uuid']);
    $request = bindUnauthenticatedCustomerRequest();

    $resource = (new CustomerController())->query($request);

    expect($resource->resource)->toHaveCount(1)
        ->and($resource->resource->first()->uuid)->toBe('customer_one');
});
