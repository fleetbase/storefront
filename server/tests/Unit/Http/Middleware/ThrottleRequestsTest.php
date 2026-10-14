<?php

use Fleetbase\Storefront\Http\Middleware\ThrottleRequests;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

test('storefront throttle applies package defaults and forwards successful requests', function () {
    $middleware = new ThrottleRequests(new RateLimiter(app('cache')));
    $request    = Request::create('/storefront/v1/about');
    $request->setRouteResolver(fn () => new class {
        public function getDomain(): string
        {
            return 'storefront.test';
        }
    });

    $response = $middleware->handle(
        $request,
        fn () => new JsonResponse(['status' => 'ok']),
        1,
        99
    );

    expect($response->getData(true))->toBe(['status' => 'ok'])
        ->and($response->headers->get('X-RateLimit-Limit'))->toBe('120')
        ->and($response->headers->get('X-RateLimit-Remaining'))->toBe('119');
});

test('the package config and the middleware fallback agree on the default limit', function () {
    // The config file calls env(), which this test app does not load, so read its default.
    $config = file_get_contents(__DIR__ . '/../../../../config/storefront.php');

    expect($config)->toContain("env('STOREFRONT_THROTTLE_REQUESTS_PER_MINUTE', " . ThrottleRequests::DEFAULT_MAX_ATTEMPTS . ')')
        ->and($config)->toContain("env('STOREFRONT_THROTTLE_DECAY_MINUTES', 1)");
});

test('storefront throttle keeps stores and devices behind the same proxy in separate buckets', function () {
    config(['storefront.throttle.max_attempts' => 1]);

    $middleware = new ThrottleRequests(new RateLimiter(app('cache')));
    $send       = function (string $storeKey, string $clientIp) use ($middleware) {
        $request = Request::create('/storefront/v1/products', 'GET', [], [], [], [
            'REMOTE_ADDR'        => $clientIp,
            'HTTP_AUTHORIZATION' => 'Bearer ' . $storeKey,
        ]);

        try {
            return $middleware->handle($request, fn () => new JsonResponse(['ok' => true]))->getStatusCode();
        } catch (Illuminate\Http\Exceptions\ThrottleRequestsException $exception) {
            return $exception->getStatusCode();
        }
    };

    expect($send('store_busy', '10.0.0.5'))->toBe(200)
        ->and($send('store_busy', '10.0.0.5'))->toBe(429)
        ->and($send('store_other', '10.0.0.5'))->toBe(200)
        ->and($send('store_busy', '10.0.0.6'))->toBe(200);
});
