<?php

namespace Fleetbase\Storefront\Push;

use Fleetbase\Storefront\Models\NotificationChannel;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Factory;

/**
 * Builds an isolated Firebase Messaging client for a storefront FCM channel.
 *
 * The channel's service account JSON is passed straight to the Firebase SDK. The
 * application-wide `firebase.projects.*` config is never read or mutated, so a
 * storefront channel works whether or not the instance has its own Firebase project.
 */
class FirebaseMessagingFactory
{
    /**
     * @var array<string, Messaging>
     */
    protected static array $clients = [];

    public function make(NotificationChannel $channel): Messaging
    {
        $serviceAccount = static::serviceAccountFromChannel($channel);
        $cacheKey       = ($channel->uuid ?? $channel->app_key) . ':' . md5(json_encode($serviceAccount));

        if (isset(static::$clients[$cacheKey])) {
            return static::$clients[$cacheKey];
        }

        return static::$clients[$cacheKey] = $this->createMessaging($serviceAccount);
    }

    protected function createMessaging(array $serviceAccount): Messaging
    {
        return (new Factory())
            ->withServiceAccount($serviceAccount)
            ->createMessaging();
    }

    /**
     * Decode and normalize the service account stored on the channel.
     *
     * Accepts the JSON string pasted in the console, an already decoded array/object, and
     * repairs private keys whose newlines were escaped (`\\n`) during copy/paste.
     *
     * @throws PushConfigurationException
     */
    public static function serviceAccountFromChannel(NotificationChannel $channel): array
    {
        $config      = (array) $channel->config;
        $credentials = $config['firebase_credentials_json'] ?? $config['credentials'] ?? null;

        if (is_object($credentials)) {
            $credentials = json_decode(json_encode($credentials), true);
        }

        if (is_string($credentials)) {
            $decoded = json_decode(trim($credentials), true);
            if (!is_array($decoded)) {
                throw new PushConfigurationException('FCM channel "' . $channel->name . '" has invalid Firebase service account JSON.');
            }
            $credentials = $decoded;
        }

        if (!is_array($credentials)) {
            throw new PushConfigurationException('FCM channel "' . $channel->name . '" is missing its Firebase service account JSON.');
        }

        foreach (['project_id', 'client_email', 'private_key'] as $required) {
            if (empty($credentials[$required])) {
                throw new PushConfigurationException('FCM channel "' . $channel->name . '" service account is missing "' . $required . '".');
            }
        }

        $credentials['private_key'] = str_replace('\\n', "\n", $credentials['private_key']);
        $credentials['type'] ??= 'service_account';

        return $credentials;
    }

    public static function flush(): void
    {
        static::$clients = [];
    }
}
