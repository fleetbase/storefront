<?php

use Fleetbase\Storefront\Http\Middleware\ThrottleSmsRequests;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

test('storefront sms throttle applies its own smaller default and forwards successful requests', function () {
    $middleware = new ThrottleSmsRequests(new RateLimiter(app('cache')));
    $request    = Request::create('/storefront/v1/customers/request-creation-code', 'POST', [], [], [], [
        'REMOTE_ADDR'        => '10.0.0.5',
        'HTTP_AUTHORIZATION' => 'Bearer store_default',
    ]);

    $response = $middleware->handle($request, fn () => new JsonResponse(['status' => 'ok']), 120, 99);

    expect($response->getData(true))->toBe(['status' => 'ok'])
        ->and($response->headers->get('X-RateLimit-Limit'))->toBe('5')
        ->and($response->headers->get('X-RateLimit-Remaining'))->toBe('4');
});

test('the package config and the sms throttle fallback agree on the default limit', function () {
    $config = file_get_contents(__DIR__ . '/../../../../config/storefront.php');

    expect($config)->toContain("env('STOREFRONT_THROTTLE_SMS_REQUESTS_PER_MINUTE', " . ThrottleSmsRequests::DEFAULT_MAX_ATTEMPTS . ')')
        ->and($config)->toContain("env('STOREFRONT_THROTTLE_SMS_DECAY_MINUTES', 1)")
        ->and($config)->toContain("env('STOREFRONT_VERIFICATION_RESEND_SECONDS', 60)");
});

test('storefront sms throttle keeps its own bucket per store key and address, apart from the group throttle', function () {
    config(['storefront.throttle.sms_max_attempts' => 1, 'storefront.throttle.max_attempts' => 120]);

    $limiter    = new RateLimiter(app('cache'));
    $middleware = new ThrottleSmsRequests($limiter);
    $group      = new Fleetbase\Storefront\Http\Middleware\ThrottleRequests($limiter);
    $send       = function ($middleware, string $storeKey, string $clientIp) {
        $request = Request::create('/storefront/v1/customers/login-with-sms', 'POST', [], [], [], [
            'REMOTE_ADDR'        => $clientIp,
            'HTTP_AUTHORIZATION' => 'Bearer ' . $storeKey,
        ]);

        try {
            return $middleware->handle($request, fn () => new JsonResponse(['ok' => true]))->getStatusCode();
        } catch (Illuminate\Http\Exceptions\ThrottleRequestsException $exception) {
            return $exception->getStatusCode();
        }
    };

    expect($send($middleware, 'store_sms', '10.0.0.5'))->toBe(200)
        ->and($send($middleware, 'store_sms', '10.0.0.5'))->toBe(429)
        ->and($send($middleware, 'store_other', '10.0.0.5'))->toBe(200)
        ->and($send($middleware, 'store_sms', '10.0.0.6'))->toBe(200)
        ->and($send($group, 'store_sms', '10.0.0.5'))->toBe(200);

    config(['storefront.throttle.sms_max_attempts' => ThrottleSmsRequests::DEFAULT_MAX_ATTEMPTS, 'storefront.throttle.max_attempts' => Fleetbase\Storefront\Http\Middleware\ThrottleRequests::DEFAULT_MAX_ATTEMPTS]);
});
