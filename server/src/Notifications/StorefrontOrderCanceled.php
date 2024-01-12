<?php

namespace Fleetbase\Storefront\Notifications;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Storefront\Models\NotificationChannel;
use Fleetbase\Storefront\Support\Storefront;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\Apn\ApnChannel;
use NotificationChannels\Apn\ApnMessage;
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Fcm\Resources\AndroidConfig;
use NotificationChannels\Fcm\Resources\AndroidFcmOptions;
use NotificationChannels\Fcm\Resources\AndroidNotification;
use NotificationChannels\Fcm\Resources\ApnsConfig;
use NotificationChannels\Fcm\Resources\ApnsFcmOptions;
use Pushok\AuthProvider\Token as PuskOkToken;
use Pushok\Client as PushOkClient;

class StorefrontOrderCanceled extends Notification
{
    use Queueable;

    /**
     * The order instance this notification is for.
     *
     * @var \Fleetbase\FleetOps\Models\Order
     */
    public $order;

    /**
     * The order instance this notification is for.
     *
     * @var \Fleetbase\Storefront\Models\Store|\Fleetbase\Storefront\Models\Network
     */
    public $storefront;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(Order $order)
    {
        $this->order      = $order->setRelations([]);
        $this->storefront = Storefront::findAbout($this->order->getMeta('storefront_id'));
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array
     */
    public function via($notifiable)
    {
        return ['mail', FcmChannel::class, ApnChannel::class];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        $message = (new MailMessage())
            ->subject('Your order from ' . $this->storefront->name . ' was canceled')
            ->line('Your order from ' . $this->storefront->name . ' has been canceled, if your card has been charged you will be refunded.')
            ->line('No further action is necessary.');

        // $message->action('View Details', Utils::consoleUrl('', ['shift' => 'fleet-ops/orders/view/' . $this->order->public_id]));

        return $message;
    }

    /**
     * Get the firebase cloud message representation of the notification.
     *
     * @return array
     */
    public function toFcm($notifiable)
    {
        $notification = \NotificationChannels\Fcm\Resources\Notification::create()
            ->setTitle('Your order from ' . $this->storefront->name . ' was canceled')
            ->setBody('Your order from ' . $this->storefront->name . ' has been canceled, if your card has been charged you will be refunded.');

        $message = FcmMessage::create()
            ->setData(['order' => $this->order->uuid, 'id' => $this->order->public_id, 'type' => 'order_canceled'])
            ->setNotification($notification)
            ->setAndroid(
                AndroidConfig::create()
                    ->setFcmOptions(AndroidFcmOptions::create()->setAnalyticsLabel('analytics'))
                    ->setNotification(AndroidNotification::create()->setColor('#4391EA'))
            )->setApns(
                ApnsConfig::create()
                    ->setFcmOptions(ApnsFcmOptions::create()->setAnalyticsLabel('analytics_ios'))
            );

        return $message;
    }

    /**
     * Get the apns message representation of the notification.
     *
     * @return array
     */
    public function toApn($notifiable)
    {
        $about = Storefront::findAbout($this->order->getMeta('storefront_id'));

        if ($this->order->hasMeta('storefront_notification_channel')) {
            $notificationChannel = NotificationChannel::where([
                'owner_uuid' => $about->uuid,
                'app_key'    => $this->order->getMeta('storefront_notification_channel'),
                'scheme'     => 'apn',
            ])->first();
        } else {
            $notificationChannel = NotificationChannel::where([
                'owner_uuid' => $about->uuid,
                'scheme'     => 'apn',
            ])->first();
        }

        $config = (array) $notificationChannel->config;

        try {
            $channelClient = new PushOkClient(PuskOkToken::create($config));
        } catch (\Exception $e) {
            app('sentry')->captureException($e);

            return;
        }

        $message = ApnMessage::create()
            ->badge(1)
            ->title('Your order from ' . $this->storefront->name . ' was canceled')
            ->body('Your order from ' . $this->storefront->name . ' has been canceled, if your card has been charged you will be refunded.')
            ->custom('type', 'order_canceled')
            ->custom('order', $this->order->uuid)
            ->custom('id', $this->order->public_id)
            ->action('view_order', ['id' => $this->order->public_id])
            ->via($channelClient);

        return $message;
    }

    public function fcmProject($notifiable, $message)
    {
        $about = Storefront::findAbout($this->order->getMeta('storefront_id'));

        if ($this->order->hasMeta('storefront_notification_channel')) {
            $notificationChannel = NotificationChannel::where([
                'owner_uuid' => $about->uuid,
                'app_key'    => $this->order->getMeta('storefront_notification_channel'),
                'scheme'     => 'fcm',
            ])->first();
        } else {
            $notificationChannel = NotificationChannel::where([
                'owner_uuid' => $about->uuid,
                'scheme'     => 'fcm',
            ])->first();
        }

        if (!$notificationChannel) {
            return 'app';
        }

        $this->configureFcm($notificationChannel);

        return $notificationChannel->app_key;
    }

    public function configureFcm($notificationChannel)
    {
        $config    = (array) $notificationChannel->config;
        $fcmConfig = config('firebase.projects.app');

        // set credentials
        Utils::set($fcmConfig, 'credentials.file', $config['firebase_credentials_json']);

        // set db url
        Utils::set($fcmConfig, 'database.url', $config['firebase_database_url']);

        config('firebase.projects.' . $notificationChannel->app_key, $fcmConfig);

        return $fcmConfig;
    }
}
