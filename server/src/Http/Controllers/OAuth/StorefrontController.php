<?php

namespace Fleetbase\Storefront\Http\Controllers\OAuth;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Http\Middleware\AuthenticateOAuthToken;
use Fleetbase\Models\ApiCredential;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Support\AppReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Storefront endpoints for apps using "Continue with Fleetbase".
 *
 * Every query is scoped to the organization the access token is bound to.
 */
class StorefrontController extends Controller
{
    /**
     * GET storefront/oauth/v1/storefronts (scope: storefront:read).
     *
     * The organization's stores and networks, with the public `store_`/`network_` key a
     * Storefront app is built with. Nothing about customers, orders or payments.
     */
    public function storefronts(Request $request): JsonResponse
    {
        $company = AuthenticateOAuthToken::company($request);

        $stores   = Store::where('company_uuid', $company->uuid)->orderBy('name')->get()->map(fn (Store $store) => static::payload($store, 'store'));
        $networks = Network::where('company_uuid', $company->uuid)->orderBy('name')->get()->map(fn (Network $network) => static::payload($network, 'network'));

        return new JsonResponse(['storefronts' => $stores->concat($networks)->values()], 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * POST storefront/oauth/v1/app-key (scope: storefront:app-key).
     *
     * The Fleetbase API key (`flb_live_…`) a Storefront app is built with. Creates one named key
     * per app, in the token's organization, or returns the one this app created before. It is
     * listed under Developers → API Keys, where the organization can revoke it. An existing key
     * the app didn't create is never returned.
     */
    public function appKey(Request $request): JsonResponse
    {
        $token   = AuthenticateOAuthToken::token($request);
        $company = AuthenticateOAuthToken::company($request);
        $name    = 'Storefront app (' . $token->client->name . ')';

        $credential = ApiCredential::where('company_uuid', $company->uuid)
            ->where('name', $name)
            ->where('test_mode', false)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();

        $created = false;
        if (!$credential) {
            $credential = ApiCredential::create([
                'company_uuid' => $company->uuid,
                'user_uuid'    => $token->user_uuid,
                'name'         => $name,
                'test_mode'    => false,
            ]);
            $created = true;
        }

        return new JsonResponse([
            'api_key' => [
                'id'         => $credential->public_id ?? $credential->uuid,
                'name'       => $credential->name,
                'key'        => $credential->fresh()->key,
                'created'    => $created,
                'created_at' => $credential->created_at,
            ],
        ], $created ? 201 : 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function payload(Store|Network $storefront, string $type): array
    {
        return [
            'id'                 => $storefront->public_id,
            'type'               => $type,
            'key'                => $storefront->key,
            'name'               => $storefront->name,
            'description'        => $storefront->description,
            'currency'           => $storefront->currency,
            'timezone'           => $storefront->timezone,
            'email'              => $storefront->email,
            'phone'              => $storefront->phone,
            'website'            => $storefront->website,
            'logo_url'           => $storefront->logo_url,
            'backdrop_url'       => $storefront->backdrop_url,
            'online'             => (bool) $storefront->online,
            'pickup_enabled'     => (bool) data_get($storefront->options, 'pickup_enabled', false),
            'review_access_type' => $type === 'store' ? AppReview::STORE_TYPE : AppReview::NETWORK_TYPE,
        ];
    }
}
