<?php

namespace Fleetbase\Storefront\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests as ThrottleRequestsMiddleware;

/**
 * A much smaller bucket for the storefront endpoints that send a text message.
 *
 * The group throttle (ThrottleRequests) allows 120 requests a minute per store key and
 * client address, which suits browsing and carting and is far too much for anything that
 * costs money per call. The creation-code, SMS login, phone verification and account
 * closure routes get this limit on top of it. It is an extra layer only: with many
 * addresses it slows an attacker down, and the SMS guard's budgets in core-api are what
 * bound the spend.
 */
class ThrottleSmsRequests extends ThrottleRequestsMiddleware
{
    /**
     * The limit when the package config is not loaded; keep in step with config/storefront.php.
     */
    public const DEFAULT_MAX_ATTEMPTS = 5;

    public function handle($request, \Closure $next, $maxAttempts = null, $decayMinutes = null, $prefix = '')
    {
        $maxAttempts  = config('storefront.throttle.sms_max_attempts', static::DEFAULT_MAX_ATTEMPTS);
        $decayMinutes = config('storefront.throttle.sms_decay_minutes', 1);

        return parent::handle($request, $next, $maxAttempts, $decayMinutes, 'storefront-sms');
    }

    /**
     * Same bucket shape as the group throttle (store key + client address), under its own
     * name so the two never share a counter.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return string
     */
    protected function resolveRequestSignature($request)
    {
        return sha1('storefront-sms-throttle|' . ($request->bearerToken() ?? '') . '|' . $request->ip());
    }
}
