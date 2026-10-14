<?php

namespace Fleetbase\Storefront\Traits;

/**
 * The `key` a store or network hands to its apps, which SetStorefrontSession checks on
 * every storefront API request. The using model declares its prefix in `KEY_PREFIX`.
 */
trait HasStorefrontKey
{
    /**
     * Give every new record a key.
     */
    public static function bootHasStorefrontKey(): void
    {
        static::creating(function ($model) {
            $model->key = static::generateKey();
        });
    }

    /**
     * A new key: the prefix and 32 hex characters from a cryptographically secure source,
     * the same shape the md5-based keys had so apps and the middleware see no difference.
     */
    public static function generateKey(): string
    {
        return static::KEY_PREFIX . bin2hex(random_bytes(16));
    }

    /**
     * Replace the key. Apps still using the old one are rejected from the next request.
     * Saved through the model so the API model cache is invalidated with the update.
     */
    public function rotateKey(): string
    {
        $this->key = static::generateKey();
        $this->save();

        return $this->key;
    }
}
