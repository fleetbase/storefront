<?php

namespace Fleetbase\Storefront\Http\Middleware;

use Fleetbase\Storefront\Support\CustomerLocale;
use Illuminate\Http\Request;

class SetStorefrontLocale
{
    /**
     * Answers the request in the language of its `Accept-Language` header and remembers that
     * language for the signed-in customer, so their notifications use it too.
     *
     * The previous locale is restored afterwards, because a long-running worker reuses the
     * application between requests.
     */
    public function handle(Request $request, \Closure $next)
    {
        $locale = CustomerLocale::fromRequest($request);

        if (!$locale) {
            return $next($request);
        }

        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            $response = $next($request);
        } finally {
            app()->setLocale($previous);
        }

        CustomerLocale::remember($request, $locale);

        return $response;
    }
}
