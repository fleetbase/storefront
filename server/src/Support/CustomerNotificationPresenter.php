<?php

namespace Fleetbase\Storefront\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Normalizes stored storefront notification payloads into the customer inbox shape.
 *
 * Handles current payloads (type/title/body) as well as rows written before they existed,
 * where order notifications stored the status code in `message` and the title in `subject`.
 */
class CustomerNotificationPresenter
{
    /**
     * Payload keys that are presentation or recipient details rather than deep-link data.
     */
    protected const HIDDEN_KEYS = [
        'notifiable', 'notification_id', 'sent_at', 'type', 'title', 'body', 'subject', 'message', 'image',
        'id', 'email', 'phone', 'companyId', 'company', 'storefront', 'order', 'store',
    ];

    public static function present(array $data, ?string $notificationClass = null): array
    {
        $message      = $data['message'] ?? null;
        $legacyStatus = is_string($message) && preg_match('/^order_[a-z_]+$/', $message) ? $message : null;

        $type  = $data['type'] ?? $legacyStatus ?? ($notificationClass ? Str::snake(class_basename($notificationClass)) : 'notification');
        $title = $data['title'] ?? $data['subject'] ?? null;
        $body  = $data['body'] ?? ($legacyStatus ? null : $message);

        if ($title && !$body) {
            $body = $title;
        }

        $payload = Arr::except($data, static::HIDDEN_KEYS);

        return [
            'type'  => $type,
            'title' => $title,
            'body'  => $body,
            'image' => $data['image'] ?? null,
            'data'  => $payload,
        ];
    }
}
