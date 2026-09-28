<?php

namespace Fleetbase\Storefront\Push;

use Fleetbase\Storefront\Models\NotificationChannel;
use Pushok\AuthProvider\Token;
use Pushok\Client;

/**
 * Builds APNs (pushok) clients for a storefront APN channel.
 */
class ApnClientFactory
{
    public const PRODUCTION = 'production';
    public const SANDBOX    = 'sandbox';

    public function make(NotificationChannel $channel, string $environment): Client
    {
        $config = static::credentialsFromChannel($channel);

        return new Client(Token::create($config), $environment === self::PRODUCTION);
    }

    /**
     * The APNs environments to try, in order, for a channel.
     *
     * An explicit `environment` of production or sandbox is strict. Otherwise the legacy
     * `production` flag only decides which environment is tried first: a token rejected
     * with BadDeviceToken is retried against the other environment, so development and
     * TestFlight/App Store builds of the same app can share one channel.
     *
     * @return string[]
     */
    public static function environments(NotificationChannel $channel, ?string $deviceEnvironment = null): array
    {
        if (in_array($deviceEnvironment, [self::PRODUCTION, self::SANDBOX], true)) {
            return [$deviceEnvironment];
        }

        $config      = (array) $channel->config;
        $environment = $config['environment'] ?? null;

        if ($environment === self::PRODUCTION || $environment === self::SANDBOX) {
            return [$environment];
        }

        if ($environment === null && array_key_exists('production', $config)) {
            $production = filter_var($config['production'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($production === false) {
                return [self::SANDBOX, self::PRODUCTION];
            }
        }

        return [self::PRODUCTION, self::SANDBOX];
    }

    /**
     * @throws PushConfigurationException
     */
    public static function credentialsFromChannel(NotificationChannel $channel): array
    {
        $config = (array) $channel->config;

        foreach (['key_id', 'team_id', 'app_bundle_id'] as $required) {
            if (empty($config[$required])) {
                throw new PushConfigurationException('APN channel "' . $channel->name . '" is missing "' . $required . '".');
            }
        }

        if (empty($config['private_key_content']) && empty($config['private_key_path'])) {
            throw new PushConfigurationException('APN channel "' . $channel->name . '" is missing its .p8 private key.');
        }

        if (!empty($config['private_key_content'])) {
            $config['private_key_content'] = str_replace('\\n', "\n", trim($config['private_key_content']));
        }

        return [
            'key_id'              => trim($config['key_id']),
            'team_id'             => trim($config['team_id']),
            'app_bundle_id'       => trim($config['app_bundle_id']),
            'private_key_content' => $config['private_key_content'] ?? null,
            'private_key_path'    => $config['private_key_path'] ?? null,
            'private_key_secret'  => $config['private_key_secret'] ?? null,
        ];
    }
}
