<?php

namespace Fleetbase\Storefront\Support;

use Fleetbase\Auth\OAuth\AppleClientSecretFactory;
use Fleetbase\Auth\OAuth\OAuthProviderConfig;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\Storefront\Models\AppleSignInToken;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\NotificationChannel;
use Fleetbase\Storefront\Models\Store;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;

/**
 * Sign in with Apple token revocation for customer account deletion (App Review 5.1.1(v)).
 *
 * At sign-in the app's one-time authorization code is exchanged with Apple for a refresh
 * token, which is kept encrypted (AppleSignInToken). When the customer deletes their account
 * the token is revoked with Apple. Both calls need a client secret signed with a Sign in with
 * Apple key from the app's developer team, which a store keeps as a notification channel:
 *
 *   apple_sign_in — a key with Sign in with Apple enabled, tried first
 *   apn           — the push key, used when it also has Sign in with Apple enabled
 *
 * The channel is matched by its `app_bundle_id`, which must equal the app's bundle ID (the
 * identity token's audience). Neither call may stop a customer signing in or deleting their
 * account: when no key is configured or Apple refuses, it is logged and the flow carries on.
 */
class AppleSignIn
{
    public const SCHEME          = 'apple_sign_in';
    public const TOKEN_URL       = 'https://appleid.apple.com/auth/token';
    public const REVOKE_URL      = 'https://appleid.apple.com/auth/revoke';
    public const TIMEOUT_SECONDS = 10;

    public function __construct(protected AppleClientSecretFactory $secrets)
    {
    }

    /**
     * Exchange a sign-in's authorization code for a refresh token and keep it for the customer.
     */
    public function remember(Contact $customer, string $identityToken, string $authorizationCode): bool
    {
        try {
            $clientId = static::clientIdFromIdentityToken($identityToken);
            if (!$clientId) {
                Log::warning('[Storefront] Sign in with Apple token not kept: the identity token names no app.', ['customer' => $customer->uuid]);

                return false;
            }

            $credentials = $this->credentials($clientId, $customer->company_uuid);
            if ($credentials->isEmpty()) {
                Log::info('[Storefront] Sign in with Apple token not kept: no Sign in with Apple key is configured for the app.', ['customer' => $customer->uuid, 'client_id' => $clientId]);

                return false;
            }

            $response = $this->callApple(self::TOKEN_URL, $credentials, [
                'code'       => $authorizationCode,
                'grant_type' => 'authorization_code',
            ]);

            $refreshToken = $response?->successful() ? $response->json('refresh_token') : null;
            if (!is_string($refreshToken) || $refreshToken === '') {
                Log::warning('[Storefront] Sign in with Apple token not kept: Apple refused the authorization code.', [
                    'customer'  => $customer->uuid,
                    'client_id' => $clientId,
                    'status'    => $response?->status(),
                    'error'     => $response?->json('error'),
                ]);

                return false;
            }

            $token = AppleSignInToken::withTrashed()->firstOrNew(['customer_uuid' => $customer->uuid, 'client_id' => $clientId]);
            $token->fill([
                'company_uuid'  => $customer->company_uuid,
                'user_uuid'     => $customer->user_uuid,
                'apple_user_id' => static::subjectFromIdentityToken($identityToken),
                'refresh_token' => $refreshToken,
            ]);
            $token->deleted_at = null;
            $token->save();

            return true;
        } catch (\Throwable $e) {
            Log::error('[Storefront] Sign in with Apple token not kept.', ['customer' => $customer->uuid, 'exception' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Revoke with Apple every token kept for a customer that is being deleted, and forget them.
     *
     * When the customer's user is deleted too, the tokens kept for that user's other storefront
     * customers go as well.
     */
    public function revokeFor(Contact $customer, bool $userDeleted = false): void
    {
        try {
            $tokens = AppleSignInToken::query()
                ->where(function ($query) use ($customer, $userDeleted) {
                    $query->where('customer_uuid', $customer->uuid);
                    if ($userDeleted && $customer->user_uuid) {
                        $query->orWhere('user_uuid', $customer->user_uuid);
                    }
                })
                ->get();
        } catch (\Throwable $e) {
            Log::error('[Storefront] Sign in with Apple tokens could not be looked up for revocation.', ['customer' => $customer->uuid, 'exception' => $e->getMessage()]);

            return;
        }

        foreach ($tokens as $token) {
            $this->revoke($token);
        }
    }

    protected function revoke(AppleSignInToken $token): bool
    {
        $revoked = false;

        try {
            $credentials = $this->credentials($token->client_id, $token->company_uuid);
            if ($credentials->isEmpty()) {
                Log::warning('[Storefront] Sign in with Apple token not revoked: no Sign in with Apple key is configured for the app.', ['customer' => $token->customer_uuid, 'client_id' => $token->client_id]);
            } else {
                $response = $this->callApple(self::REVOKE_URL, $credentials, [
                    'token'           => $token->refresh_token,
                    'token_type_hint' => 'refresh_token',
                ]);
                $revoked = (bool) $response?->successful();

                $context = ['customer' => $token->customer_uuid, 'client_id' => $token->client_id];
                if ($revoked) {
                    Log::info('[Storefront] Sign in with Apple token revoked.', $context);
                } else {
                    Log::warning('[Storefront] Sign in with Apple token not revoked: Apple refused it.', $context + ['status' => $response?->status(), 'error' => $response?->json('error')]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('[Storefront] Sign in with Apple token not revoked.', ['customer' => $token->customer_uuid, 'client_id' => $token->client_id, 'exception' => $e->getMessage()]);
        }

        // The account is going either way; a token for it is not kept on.
        try {
            $token->forceDelete();
        } catch (\Throwable $e) {
            Log::error('[Storefront] Sign in with Apple token could not be removed.', ['customer' => $token->customer_uuid, 'exception' => $e->getMessage()]);
        }

        return $revoked;
    }

    /**
     * POST to Apple with each candidate key in turn. A key Apple does not accept for the app
     * (`invalid_client`: wrong team, or a push key without Sign in with Apple) falls through
     * to the next; any other answer is final.
     *
     * @param Collection<int, OAuthProviderConfig> $credentials
     */
    protected function callApple(string $url, Collection $credentials, array $form): ?Response
    {
        $response = null;

        foreach ($credentials as $config) {
            try {
                $clientSecret = $this->secrets->make($config);
            } catch (\Throwable $e) {
                Log::warning('[Storefront] A Sign in with Apple key could not sign a client secret.', ['client_id' => $config->get('client_id'), 'key_id' => $config->get('key_id')]);

                continue;
            }

            $response = Http::asForm()
                ->acceptJson()
                ->timeout(self::TIMEOUT_SECONDS)
                ->post($url, array_merge($form, [
                    'client_id'     => $config->get('client_id'),
                    'client_secret' => $clientSecret,
                ]));

            if ($response->json('error') !== 'invalid_client') {
                return $response;
            }
        }

        return $response;
    }

    /**
     * Keys that can sign for an app: the company's `apple_sign_in` channels for the bundle ID,
     * then its `apn` channels for it, oldest first within each.
     *
     * @return Collection<int, OAuthProviderConfig>
     */
    public function credentials(string $clientId, ?string $companyUuid): Collection
    {
        if (!$companyUuid) {
            return collect();
        }

        $owners = Store::where('company_uuid', $companyUuid)->pluck('uuid')
            ->merge(Network::where('company_uuid', $companyUuid)->pluck('uuid'))
            ->filter()
            ->values();
        if ($owners->isEmpty()) {
            return collect();
        }

        return NotificationChannel::query()
            ->whereIn('scheme', [self::SCHEME, 'apn'])
            ->whereIn('owner_uuid', $owners->all())
            ->orderBy('created_at')
            ->get()
            ->sortBy(fn (NotificationChannel $channel) => $channel->scheme === self::SCHEME ? 0 : 1)
            ->map(fn (NotificationChannel $channel) => static::configFromChannel($channel))
            ->filter(fn (?OAuthProviderConfig $config) => $config && $config->get('client_id') === $clientId)
            ->unique(fn (OAuthProviderConfig $config) => $config->get('team_id') . '|' . $config->get('key_id'))
            ->values();
    }

    public static function configFromChannel(NotificationChannel $channel): ?OAuthProviderConfig
    {
        $config = (array) $channel->config;
        $values = [];
        foreach (['key_id', 'team_id', 'app_bundle_id'] as $key) {
            $values[$key] = trim((string) ($config[$key] ?? ''));
        }

        $privateKey = str_replace('\\n', "\n", trim((string) ($config['private_key_content'] ?? '')));
        if ($privateKey === '' && !empty($config['private_key_path']) && is_readable($config['private_key_path'])) {
            $privateKey = trim((string) file_get_contents($config['private_key_path']));
        }

        if (in_array('', $values, true) || $privateKey === '') {
            return null;
        }

        return new OAuthProviderConfig('apple', [
            'team_id'     => $values['team_id'],
            'key_id'      => $values['key_id'],
            'client_id'   => $values['app_bundle_id'],
            'private_key' => $privateKey,
        ]);
    }

    /**
     * The bundle ID an identity token was issued for (its audience). Only read once the token
     * has been verified.
     */
    public static function clientIdFromIdentityToken(string $identityToken): ?string
    {
        $audience = static::claims($identityToken)?->get('aud');
        $audience = is_array($audience) ? ($audience[0] ?? null) : $audience;

        return is_string($audience) && $audience !== '' ? $audience : null;
    }

    public static function subjectFromIdentityToken(string $identityToken): ?string
    {
        $subject = static::claims($identityToken)?->get('sub');

        return is_string($subject) && $subject !== '' ? $subject : null;
    }

    protected static function claims(string $identityToken): ?\Lcobucci\JWT\Token\DataSet
    {
        try {
            $token = (new Parser(new JoseEncoder()))->parse($identityToken);
        } catch (\Throwable $e) {
            return null;
        }

        return $token instanceof UnencryptedToken ? $token->claims() : null;
    }
}
