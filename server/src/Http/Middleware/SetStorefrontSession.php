<?php

namespace Fleetbase\Storefront\Http\Middleware;

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class SetStorefrontSession
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, \Closure $next)
    {
        $key = $request->bearerToken();

        if (!$key) {
            return response()->error('Oops! No Storefront key found with this request', 401);
        }

        if ($this->isValidKey($key)) {
            $this->setKey($key);
            $this->setupCustomerSession($request);

            return $next($request);
        }

        return response()->error('Oops! The Storefront key provided was not valid', 401);
    }

    /**
     * Checks if storefront key is valid.
     */
    public function isValidKey(string $key): bool
    {
        if (!Str::startsWith($key, ['network', 'store'])) {
            return false;
        }

        if (Str::startsWith($key, 'store')) {
            return Store::select(['key'])->where('key', $key)->exists();
        }

        return Network::select(['key'])->where('key', $key)->exists();
    }

    /**
     * Sets the storefront key to session.
     */
    public function setKey(string $key): void
    {
        $session = ['storefront_key' => $key];

        if (Str::startsWith($key, 'store')) {
            $store = Store::select(['uuid', 'company_uuid', 'currency'])->where('key', $key)->first();

            if ($store) {
                $session['storefront_store']              = $store->uuid;
                $session['storefront_store_public_id']    = $store->public_id;
                $session['storefront_currency']           = $store->currency;
                $session['company']                       = $store->company_uuid;
            }
        } elseif (Str::startsWith($key, 'network')) {
            $network = Network::select(['uuid', 'company_uuid', 'currency'])->where('key', $key)->first();

            if ($network) {
                $session['storefront_network']            = $network->uuid;
                $session['storefront_network_public_id']  = $network->public_id;
                $session['storefront_currency']           = $network->currency;
                $session['company']                       = $network->company_uuid;
            }
        }

        $session['api_credential'] = $key;

        session($session);
    }

    /**
     * Set the customer id to session if applicable.
     *
     * @return void
     */
    public function setupCustomerSession(Request $request)
    {
        $token = $request->header('Customer-Token');

        if ($token) {
            $accessToken = PersonalAccessToken::findToken($token);

            if ($accessToken) {
                $tokenable = $this->getTokenableFromAccessToken($accessToken);

                if (!$tokenable) {
                    return;
                }

                $contact = Contact::select(['uuid', 'public_id'])->where('user_uuid', $tokenable->uuid)->first();

                session([
                    'customer_id' => Str::replaceFirst('contact', 'customer', $contact->public_id),
                    'contact_id'  => $contact->public_id,
                    'customer'    => $contact->uuid,
                ]);
            }
        }
    }

    public function getTokenableFromAccessToken(PersonalAccessToken $personalAccessToken)
    {
        if ($personalAccessToken->tokenable) {
            return $personalAccessToken->tokenable;
        }

        return app($personalAccessToken->tokenable_type)->where('uuid', $personalAccessToken->tokenable_id)->withoutGlobalScopes()->first();
    }
}
