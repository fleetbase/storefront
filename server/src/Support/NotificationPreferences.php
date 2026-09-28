<?php

namespace Fleetbase\Storefront\Support;

/**
 * Customer notification preferences, stored on the customer contact's meta.
 *
 * - `order_updates`: push notifications about the customer's orders. Order updates are
 *   always kept in the inbox, only the push can be turned off.
 * - `promotions`: promotional notifications, both push and inbox.
 */
class NotificationPreferences
{
    public const META_KEY = 'notification_preferences';

    public const DEFAULTS = [
        'order_updates' => true,
        'promotions'    => true,
    ];

    public static function for($notifiable): array
    {
        $stored = [];
        if (is_object($notifiable) && method_exists($notifiable, 'getMeta')) {
            $stored = (array) $notifiable->getMeta(static::META_KEY, []);
        }

        $preferences = [];
        foreach (static::DEFAULTS as $key => $default) {
            $preferences[$key] = array_key_exists($key, $stored) ? filter_var($stored[$key], FILTER_VALIDATE_BOOLEAN) : $default;
        }

        return $preferences;
    }

    public static function allows($notifiable, string $preference): bool
    {
        return static::for($notifiable)[$preference] ?? true;
    }

    /**
     * Merge the known preference keys from the input into the notifiable's stored preferences.
     */
    public static function update($notifiable, array $input): array
    {
        $preferences = static::for($notifiable);
        foreach (array_keys(static::DEFAULTS) as $key) {
            if (array_key_exists($key, $input)) {
                $preferences[$key] = filter_var($input[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        $notifiable->updateMeta(static::META_KEY, $preferences);

        return $preferences;
    }
}
