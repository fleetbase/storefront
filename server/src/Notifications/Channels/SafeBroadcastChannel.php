<?php

namespace Fleetbase\Storefront\Notifications\Channels;

use Illuminate\Notifications\Channels\BroadcastChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Broadcasts a notification in realtime without letting a socket failure fail the send.
 *
 * Broadcasting runs after push and database delivery. If it threw, a queued listener would be
 * retried and the customer would receive the push again, so failures are only logged.
 */
class SafeBroadcastChannel extends BroadcastChannel
{
    public function send($notifiable, Notification $notification)
    {
        try {
            return parent::send($notifiable, $notification);
        } catch (\Throwable $e) {
            try {
                Log::warning('[Storefront] Unable to broadcast notification.', ['notification' => get_class($notification), 'error' => $e->getMessage()]);
            } catch (\Throwable $logError) {
                // Logging must never break delivery.
            }

            return null;
        }
    }
}
