<?php

namespace Fleetbase\Storefront\Notifications;

use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Support\PushNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\Apn\ApnChannel;
use NotificationChannels\Fcm\FcmChannel;

class PromotionalPushNotification extends Notification
{
    use Queueable;

    /**
     * The notification title.
     */
    public string $title;

    /**
     * The notification body.
     */
    public string $body;

    /**
     * The store instance.
     */
    public Store $store;

    /**
     * The time the notification was sent.
     */
    public string $sentAt;

    /**
     * The ID of the notification.
     */
    public string $notificationId;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(string $title, string $body, Store $store)
    {
        $this->title          = $title;
        $this->body           = $body;
        $this->store          = $store;
        $this->sentAt         = now()->toDateTimeString();
        $this->notificationId = uniqid('notification_');
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via($notifiable): array
    {
        $channels = [];

        // Check if store has APN notification channel configured
        $apnChannel = PushNotification::getNotificationChannel('apn', $this->store);
        if ($apnChannel) {
            $channels[] = ApnChannel::class;
        }

        // Check if store has FCM notification channel configured
        $fcmChannel = PushNotification::getNotificationChannel('fcm', $this->store);
        if ($fcmChannel) {
            $channels[] = FcmChannel::class;
        }

        return $channels;
    }

    /**
     * Get the APN representation of the notification.
     */
    public function toApn($notifiable)
    {
        $client = PushNotification::getApnClient($this->store);
        if (!$client) {
            return null;
        }

        return \NotificationChannels\Apn\ApnMessage::create()
            ->badge(1)
            ->title($this->title)
            ->body($this->body)
            ->custom('type', 'promotional')
            ->custom('store', $this->store->uuid)
            ->custom('store_id', $this->store->public_id)
            ->via($client);
    }

    /**
     * Get the FCM representation of the notification.
     */
    public function toFcm($notifiable)
    {
        $notificationChannel = PushNotification::getNotificationChannel('fcm', $this->store);
        if (!$notificationChannel) {
            return null;
        }

        // Configure FCM
        PushNotification::configureFcm($notificationChannel);

        // Get FCM Client
        $container      = \Illuminate\Container\Container::getInstance();
        $projectManager = new \Kreait\Laravel\Firebase\FirebaseProjectManager($container);
        $client         = $projectManager->project($notificationChannel->app_key)->messaging();

        // Create Notification
        $notification = new \NotificationChannels\Fcm\Resources\Notification(
            title: $this->title,
            body: $this->body
        );

        return (new \NotificationChannels\Fcm\FcmMessage(notification: $notification))
            ->data([
                'type'     => 'promotional',
                'store'    => $this->store->uuid,
                'store_id' => $this->store->public_id,
            ])
            ->custom([
                'android' => [
                    'notification' => [
                        'color' => '#4391EA',
                        'sound' => 'default',
                    ],
                    'fcm_options' => [
                        'analytics_label' => 'promotional',
                    ],
                ],
                'apns' => [
                    'payload' => [
                        'aps' => [
                            'sound' => 'default',
                        ],
                    ],
                    'fcm_options' => [
                        'analytics_label' => 'promotional',
                    ],
                ],
            ])
            ->usingClient($client);
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray($notifiable): array
    {
        return [
            'title'           => $this->title,
            'body'            => $this->body,
            'store'           => $this->store->uuid,
            'store_id'        => $this->store->public_id,
            'type'            => 'promotional',
            'sent_at'         => $this->sentAt,
            'notification_id' => $this->notificationId,
        ];
    }
}
