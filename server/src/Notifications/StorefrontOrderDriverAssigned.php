<?php

namespace Fleetbase\Storefront\Notifications;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Support\PushNotification;
use Fleetbase\Storefront\Support\Storefront;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\Apn\ApnChannel;
use NotificationChannels\Fcm\FcmChannel;

class StorefrontOrderDriverAssigned extends Notification
{
    use Queueable;

    /**
     * The order instance this notification is for.
     */
    public Order $order;

    /**
     * The driver assigned to the order.
     */
    public Driver $driver;

    /**
     * The order instance this notification is for.
     */
    public Store|Network $storefront;

    /**
     * The time the notification was sent.
     */
    public string $sentAt;

    /**
     * The ID of the notification.
     */
    public string $notificationId;

    /**
     * The notification subject.
     */
    public string $subject;

    /**
     * The notification body.
     */
    public string $body;

    /**
     * The notification order status.
     */
    public string $status;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(Order $order)
    {
        $this->order          = $order;
        $this->storefront     = Storefront::findAbout($order->getMeta('storefront_id'));
        $this->driver         = $order->driverAssigned;
        $this->sentAt         = now()->toDateTimeString();
        $this->notificationId = uniqid('notification_');

        $this->subject = 'Your driver is ' . $this->driver->name;
        $this->body    = 'A driver has been assigned to your order from ' . $this->storefront->name . ', they are currently en-route for pickup';
        $this->status  = 'order_driver_assigned';
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via($notifiable): array
    {
        $channels = ['mail', 'database'];

        if (Storefront::hasNotificationChannelConfigured($this->storefront, 'apn')) {
            $channels[] = ApnChannel::class;
        }

        if (Storefront::hasNotificationChannelConfigured($this->storefront, 'fcm')) {
            $channels[] = FcmChannel::class;
        }

        return $channels;
    }

    /**
     * Get the mail representation of the notification.
     *
     * @return MailMessage
     */
    public function toMail($notifiable)
    {
        $message = (new MailMessage())
            ->subject($this->subject)
            ->line($this->body);

        // $message->action('View Details', Utils::consoleUrl('', ['shift' => 'fleet-ops/orders/view/' . $this->order->public_id]));

        return $message;
    }

    /**
     * Get the firebase cloud message representation of the notification.
     *
     * @return \NotificationChannels\Fcm\FcmMessage
     */
    public function toFcm($notifiable)
    {
        return PushNotification::createFcmMessage(
            $this->order,
            $this->subject,
            $this->body,
            $this->status,
            $notifiable
        );
    }

    /**
     * Get the apns message representation of the notification.
     *
     * @return \NotificationChannels\Apn\ApnMessage
     */
    public function toApn($notifiable)
    {
        return PushNotification::createApnMessage(
            $this->order,
            $this->subject,
            $this->body,
            $this->status,
            $notifiable
        );
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray($notifiable): array
    {
        $this->order->loadMissing(['customer', 'company']);
        $customer = $this->order->customer;
        $company  = $this->order->company;

        return [
            'notifiable'      => $notifiable->public_id,
            'notification_id' => $this->notificationId,
            'sent_at'         => $this->sentAt,
            'subject'         => $this->subject,
            'message'         => $this->status,
            'storefront'      => $this->storefront->name,
            'storefront_id'   => $this->storefront->public_id,
            'id'              => $customer->public_id,
            'email'           => $customer->email,
            'phone'           => $customer->phone,
            'companyId'       => $company->public_id,
            'company'         => $company->name,
        ];
    }
}
