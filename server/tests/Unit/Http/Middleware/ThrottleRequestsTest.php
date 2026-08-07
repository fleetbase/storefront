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
        ->and($response->headers->get('X-RateLimit-Limit'))->toBe('500')
        ->and($response->headers->get('X-RateLimit-Remaining'))->toBe('499');
});
