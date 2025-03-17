<?php

namespace Fleetbase\Storefront\Support;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\NotificationChannel;
use Fleetbase\Storefront\Models\Store;
use Illuminate\Container\Container;
use Kreait\Laravel\Firebase\FirebaseProjectManager;
use NotificationChannels\Apn\ApnMessage;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Fcm\Resources\Notification as FcmNotification;
use Pushok\AuthProvider\Token as PuskOkToken;
use Pushok\Client as PushOkClient;

class PushNotification
{
    public static function createApnMessage(Order $order, string $title, string $body, string $status, $notifiable = null): ApnMessage
    {
        $storefront = static::getStorefrontFromOrder($order);
        $client     = static::getApnClient($storefront, $order);

        return ApnMessage::create()
            ->badge(1)
            ->title($title)
            ->body($body)
            ->custom('type', $status)
            ->custom('order', $order->uuid)
            ->custom('id', $order->public_id)
            ->action('view_order', ['id' => $order->public_id])
            ->via($client);
    }

    public static function createFcmMessage(Order $order, string $title, string $body, string $status, $notifiable = null): FcmMessage
    {
        $storefront          = static::getStorefrontFromOrder($order);
        $notificationChannel = static::getNotificationChannel('apn', $storefront, $order);

        // Configure FCM
        static::configureFcm($notificationChannel);

        // Get FCM Client using Notification Channel
        $container      = Container::getInstance();
        $projectManager = new FirebaseProjectManager($container);
        $client         = $projectManager->project($notificationChannel->app_key)->messaging();

        // Create Notification
        $notification = new FcmNotification(
            title: $title,
            body: $body
        );

        return (new FcmMessage(notification: $notification))
            ->setData(['order' => $order->uuid, 'id' => $order->public_id, 'type' => $status])
            ->custom([
                'android' => [
                    'notification' => [
                        'color' => '#4391EA',
                        'sound' => 'default',
                    ],
                    'fcm_options' => [
                        'analytics_label' => 'analytics',
                    ],
                ],
                'apns' => [
                    'payload' => [
                        'aps' => [
                            'sound' => 'default',
                        ],
                    ],
                    'fcm_options' => [
                        'analytics_label' => 'analytics',
                    ],
                ],
            ])
            ->usingClient($client);
    }

    public static function configureFcm(NotificationChannel $notificationChannel)
    {
        // Convert the channel's config to an array.
        $config = (array) $notificationChannel->config;

        // Get the base firebase config.
        $firebaseConfig = config('firebase.projects.app');

        // Update the firebase config with values from the channel.
        data_set($firebaseConfig, 'credentials.private_key', $config['firebase_credentials_json']);
        data_set($firebaseConfig, 'database.url', $config['firebase_database_url']);

        // Update the Laravel config for this project key.
        config(['firebase.projects.' . $notificationChannel->app_key => $firebaseConfig]);

        // Return the updated firebase config.
        return $firebaseConfig;
    }

    public static function getNotificationChannel(string $scheme, Network|Store $storefront, ?Order $order = null): NotificationChannel
    {
        if ($order && $order->hasMeta('storefront_notification_channel')) {
            return NotificationChannel::where([
                'owner_uuid' => $storefront->uuid,
                'app_key'    => $order->getMeta('storefront_notification_channel'),
                'scheme'     => $scheme,
            ])->first();
        }

        return NotificationChannel::where([
            'owner_uuid' => $storefront->uuid,
            'scheme'     => $scheme,
        ])->first();
    }

    public static function getApnClient(Network|Store $storefront, ?Order $order = null): PushOkClient
    {
        $notificationChannel = static::getNotificationChannel('apn', $storefront, $order);
        $config              = (array) $notificationChannel->config;

        return new PushOkClient(PuskOkToken::create($config));
    }

    public static function getStorefrontFromOrder(Order $order): Network|Store|null
    {
        return Storefront::findAbout($order->getMeta('storefront_id'));
    }
}
