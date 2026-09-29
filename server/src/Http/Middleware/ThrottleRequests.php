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

    /**
     * Resolve the limiter key from the store and the device rather than the connection alone.
     *
     * This runs before the storefront session is set, so Laravel's default signature always
     * fell through to route domain + client IP. Behind a proxy that IP is the proxy's, so every
     * store (and core's own limiter, which produced the identical key) shared one bucket.
     *
     * The store key ships inside every customer app, so it identifies the tenant, not a caller;
     * pairing it with the client IP limits each device while keeping stores isolated from one
     * another even when the IP cannot be resolved.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return string
     */
    protected function resolveRequestSignature($request)
    {
        return sha1('storefront-throttle|' . ($request->bearerToken() ?? '') . '|' . $request->ip());
    }
}
