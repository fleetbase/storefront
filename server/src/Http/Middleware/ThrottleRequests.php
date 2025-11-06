<?php

namespace Fleetbase\Storefront\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests as ThrottleRequestsMiddleware;

class ThrottleRequests extends ThrottleRequestsMiddleware
{
    public function handle($request, \Closure $next, $maxAttempts = null, $decayMinutes = null, $prefix = '')
    {
        $maxAttempts  = config('storefront.throttle.max_attempts', 500);
        $decayMinutes = config('storefront.throttle.decay_minutes', 1);

        return parent::handle($request, $next, $maxAttempts, $decayMinutes, $prefix);
    }
}
