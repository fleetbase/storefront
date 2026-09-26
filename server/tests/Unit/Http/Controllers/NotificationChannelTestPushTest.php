<?php

use Fleetbase\Storefront\Http\Controllers\NotificationChannelController;
use Fleetbase\Storefront\Models\NotificationChannel;
use Fleetbase\Storefront\Push\ApnClientFactory;
use Fleetbase\Storefront\Push\FirebaseMessagingFactory;
use Fleetbase\Storefront\Push\PushMessage;
use Fleetbase\Storefront\Push\PushOutcome;
use Fleetbase\Storefront\Push\Transports\ApnTransport;
use Fleetbase\Storefront\Push\Transports\FcmTransport;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Http\Request;

beforeEach(function () {
    $schema = Capsule::schema('mysql');
    $schema->dropIfExists('notification_channels');
    $schema->create('notification_channels', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->string('owner_type')->nullable();
        $table->string('name')->nullable();
        $table->string('scheme')->nullable();
        $table->string('app_key')->nullable();
        $table->text('config')->nullable();
        $table->text('options')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    Capsule::connection('mysql')->table('notification_channels')->insert([
        ['uuid' => 'fcm_uuid', 'company_uuid' => 'company_uuid', 'name' => 'Android', 'scheme' => 'fcm', 'app_key' => 'fcm_key', 'config' => '{}'],
        ['uuid' => 'apn_uuid', 'company_uuid' => 'company_uuid', 'name' => 'iOS', 'scheme' => 'apn', 'app_key' => 'apn_key', 'config' => '{"environment":"auto"}'],
        ['uuid' => 'foreign_uuid', 'company_uuid' => 'other_company', 'name' => 'Other', 'scheme' => 'fcm', 'app_key' => 'foreign_key', 'config' => '{}'],
    ]);
    session(['company' => 'company_uuid']);
});

function testPushFcm(PushOutcome|Throwable $result): FcmTransport
{
    return new class($result) extends FcmTransport {
        public function __construct(public PushOutcome|Throwable $result)
        {
            parent::__construct(new FirebaseMessagingFactory());
        }

        public function send(NotificationChannel $channel, PushMessage $message, array $tokens): array
        {
            if ($this->result instanceof Throwable) {
                throw $this->result;
            }

            return [$tokens[0] => $this->result];
        }
    };
}

function testPushApn(array $byEnvironment): ApnTransport
{
    return new class($byEnvironment) extends ApnTransport {
        public array $environments = [];

        public function __construct(public array $byEnvironment)
        {
            parent::__construct(new ApnClientFactory());
        }

        public function send(NotificationChannel $channel, string $environment, PushMessage $message, array $tokens): array
        {
            $this->environments[] = $environment;

            return [$tokens[0] => $this->byEnvironment[$environment]];
        }
    };
}

test('test push reports FCM provider outcomes for a company channel', function () {
    $controller = new NotificationChannelController();
    $apn        = testPushApn([]);

    $sent   = $controller->testPush('fcm_uuid', Request::create('/', 'POST', ['token' => 'android-token']), testPushFcm(PushOutcome::sent()), $apn);
    $failed = $controller->testPush('fcm_key', Request::create('/', 'POST', ['token' => 'android-token']), testPushFcm(new RuntimeException('missing "private_key"')), $apn);

    expect($sent->getData(true))->toBe([
        'status'  => 'OK',
        'scheme'  => 'fcm',
        'results' => [['environment' => null, 'status' => 'sent', 'reason' => null]],
    ])->and($failed->getData(true))->toBe([
        'status'  => 'FAILED',
        'scheme'  => 'fcm',
        'results' => [['environment' => null, 'status' => 'error', 'reason' => 'missing "private_key"']],
    ]);
});

test('test push tries each APNs environment until one accepts the token', function () {
    $apn = testPushApn([
        'production' => PushOutcome::wrongEnvironment('BadDeviceToken'),
        'sandbox'    => PushOutcome::sent(),
    ]);

    $response = (new NotificationChannelController())->testPush('apn_uuid', Request::create('/', 'POST', ['token' => 'ios-token']), testPushFcm(PushOutcome::sent()), $apn);

    expect($response->getData(true)['status'])->toBe('OK')
        ->and($response->getData(true)['results'])->toBe([
            ['environment' => 'production', 'status' => 'wrong_environment', 'reason' => 'BadDeviceToken'],
            ['environment' => 'sandbox', 'status' => 'sent', 'reason' => null],
        ])
        ->and($apn->environments)->toBe(['production', 'sandbox']);
});

test('test push requires a token and a channel owned by the company', function () {
    $controller = new NotificationChannelController();
    $fcm        = testPushFcm(PushOutcome::sent());
    $apn        = testPushApn([]);

    $missingToken = $controller->testPush('fcm_uuid', Request::create('/', 'POST'), $fcm, $apn);
    $foreign      = $controller->testPush('foreign_uuid', Request::create('/', 'POST', ['token' => 'android-token']), $fcm, $apn);

    expect($missingToken->getStatusCode())->toBe(400)
        ->and($foreign->getStatusCode())->toBe(404);
});
