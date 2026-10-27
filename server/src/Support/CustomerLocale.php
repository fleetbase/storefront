<?php

namespace Fleetbase\Storefront\Support;

use Fleetbase\FleetOps\Models\Contact;
use Illuminate\Http\Request;

/**
 * The language storefront answers a customer in.
 *
 * The customer app sends its language as `Accept-Language` on every request. API responses
 * use it straight away. Queued notifications have no request, so the customer's last
 * language is kept on their contact's meta and notifications read it from there.
 *
 * A language is supported when `server/resources/lang/<code>` exists.
 */
class CustomerLocale
{
    public const META_KEY = 'locale';

    /**
     * The supported language codes, English first so it is the fallback.
     */
    protected static ?array $available = null;

    public static function available(): array
    {
        if (static::$available === null) {
            $codes = array_map('basename', glob(static::path() . '/*', GLOB_ONLYDIR) ?: []);
            usort($codes, fn ($a, $b) => ($b === 'en') <=> ($a === 'en') ?: strcmp($a, $b));

            static::$available = $codes;
        }

        return static::$available;
    }

    public static function path(): string
    {
        return dirname(__DIR__, 2) . '/resources/lang';
    }

    public static function supports(?string $locale): bool
    {
        return is_string($locale) && in_array($locale, static::available(), true);
    }

    /**
     * The best supported language for the request's `Accept-Language`, or null without the header.
     * Regional codes match their language (`mn-MN` is `mn`), and an unsupported language is English.
     */
    public static function fromRequest(Request $request): ?string
    {
        if (!$request->headers->has('Accept-Language')) {
            return null;
        }

        return $request->getPreferredLanguage(static::available());
    }

    /**
     * The language a customer last used, or null when they have none stored.
     */
    public static function for($notifiable): ?string
    {
        if (!is_object($notifiable) || !method_exists($notifiable, 'getMeta')) {
            return null;
        }

        $locale = $notifiable->getMeta(static::META_KEY);

        return static::supports($locale) ? $locale : null;
    }

    /**
     * Stores the language for the customer signed in to this request, when it changed.
     * Written straight to the table so it fires no model events.
     */
    public static function remember(Request $request, string $locale): void
    {
        $uuid = session('customer');

        if (!$uuid || !$request->header('Customer-Token')) {
            return;
        }

        $contact = Contact::select(['uuid', 'meta'])->where('uuid', $uuid)->first();

        if ($contact && $contact->getMeta(static::META_KEY) !== $locale) {
            $contact->updateMetaProperties([static::META_KEY => $locale]);
        }
    }
}
