<?php

namespace Fleetbase\Storefront\Support;

use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Models\Company;
use Fleetbase\Storefront\Models\Product;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Models\StoreLocation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The times a bookable service can be booked on a day: its hours (the product's own, else the
 * store's) cut into slots of the service's length, each with how many providers are still free.
 *
 * A service is set up in the product's `meta.service`: `duration_minutes` (default 60),
 * `capacity` (bookings a slot takes at once, default 1), `deposit_percent`, `travel_fee` and
 * `provider_tag`.
 */
class BookingSlots
{
    public const DEFAULT_DURATION = 60;
    public const DEFAULT_CAPACITY = 1;

    /** Bookings in these statuses no longer take a slot. */
    public const RELEASED_STATUSES = ['canceled', 'order_canceled', 'expired', 'failed'];

    public static function service(Product $product): array
    {
        $service = (array) data_get($product->meta, 'service', []);

        return [
            'duration_minutes' => max(5, (int) ($service['duration_minutes'] ?? static::DEFAULT_DURATION)),
            'capacity'         => max(1, (int) ($service['capacity'] ?? static::DEFAULT_CAPACITY)),
            'deposit_percent'  => min(100, max(0, (int) ($service['deposit_percent'] ?? 0))),
            'travel_fee'       => max(0, (int) ($service['travel_fee'] ?? 0)),
            'provider_tag'     => $service['provider_tag'] ?? null,
        ];
    }

    /**
     * @return array<int, array{start: string, end: string, time: string, past: bool, available: bool, remaining: int, providers: int, capacity: int}>
     */
    public static function forDay(Product $product, string $date): array
    {
        $store    = Store::where('uuid', $product->store_uuid)->first();
        $timezone = static::timezone($store);
        $day      = Carbon::parse($date, $timezone)->startOfDay();
        $service  = static::service($product);
        $length   = $service['duration_minutes'];

        $windows = static::windows($product, $store, $day);
        $taken   = static::bookedStarts($product, $day->copy()->subDay(), $day->copy()->addDays(2));
        $now     = now();
        $slots   = [];

        foreach ($windows as [$open, $close]) {
            for ($start = $open->copy(); $start->copy()->addMinutes($length)->lte($close); $start->addMinutes($length)) {
                $end       = $start->copy()->addMinutes($length);
                $booked    = $taken->filter(fn (Carbon $at) => $at->gte($start) && $at->lt($end))->count();
                $remaining = max(0, $service['capacity'] - $booked);

                $slots[] = [
                    'start'     => $start->toIso8601String(),
                    'end'       => $end->toIso8601String(),
                    'time'      => $start->format('H:i'),
                    'past'      => $start->lte($now),
                    'available' => $remaining > 0 && $start->gt($now),
                    'remaining' => $remaining,
                    // The contract's name for places left (providers free at that time).
                    'providers' => $remaining,
                    'capacity'  => $service['capacity'],
                ];
            }
        }

        return $slots;
    }

    /**
     * The store's time zone, else its company's, else the app's: slots are the store's local times.
     */
    public static function timezone(?Store $store): string
    {
        $timezone = $store?->timezone ?: ($store ? Company::where('uuid', $store->company_uuid)->value('timezone') : null);

        return $timezone ?: config('app.timezone', 'UTC');
    }

    /**
     * Whether a time is a free slot start for the product.
     */
    public static function isAvailable(Product $product, Carbon $at): bool
    {
        $store = Store::where('uuid', $product->store_uuid)->first();
        $local = $at->copy()->setTimezone(static::timezone($store));

        return collect(static::forDay($product, $local->toDateString()))
            ->contains(fn ($slot) => $slot['available'] && Carbon::parse($slot['start'])->equalTo($at));
    }

    /**
     * Opening windows for the day: the product's hours for that weekday, else the store's.
     *
     * @return array<int, array{0: Carbon, 1: Carbon}>
     */
    protected static function windows(Product $product, ?Store $store, Carbon $day): array
    {
        $weekday = strtolower($day->englishDayOfWeek);
        $hours   = collect($product->hours ?? [])->filter(fn ($hour) => strtolower((string) data_get($hour, 'day_of_week')) === $weekday);
        if ($hours->isEmpty() && $store) {
            // Store hours are kept per location; a service follows the store's first location.
            $location = StoreLocation::where('store_uuid', $store->uuid)->with('hours')->orderBy('created_at')->first();
            $hours    = collect($location?->hours ?? [])->filter(fn ($hour) => strtolower((string) data_get($hour, 'day_of_week')) === $weekday);
        }

        return $hours
            ->map(function ($hour) use ($day) {
                $open  = $day->copy()->setTimeFromTimeString((string) data_get($hour, 'start'));
                $close = $day->copy()->setTimeFromTimeString((string) data_get($hour, 'end'));

                return $close->gt($open) ? [$open, $close] : null;
            })
            ->filter()
            ->sortBy(fn ($window) => $window[0]->getTimestamp())
            ->values()
            ->all();
    }

    /**
     * Start times of the product's bookings around the day, one per booked unit, leaving out
     * canceled orders.
     *
     * @return Collection<int, Carbon>
     */
    protected static function bookedStarts(Product $product, Carbon $from, Carbon $to): Collection
    {
        $entities = Entity::where('meta->product_id', $product->public_id)
            ->whereNotNull('meta->scheduled_at')
            ->where('created_at', '>=', now()->subDays(120))
            ->get(['uuid', 'payload_uuid', 'meta']);
        if ($entities->isEmpty()) {
            return collect();
        }

        $released = Order::whereIn('payload_uuid', $entities->pluck('payload_uuid')->unique()->values())
            ->whereIn('status', static::RELEASED_STATUSES)
            ->pluck('payload_uuid')
            ->all();

        return $entities
            ->reject(fn ($entity) => in_array($entity->payload_uuid, $released, true))
            ->flatMap(function ($entity) use ($from, $to) {
                try {
                    $at = Carbon::parse(data_get($entity->meta, 'scheduled_at'));
                } catch (\Throwable $e) {
                    return [];
                }

                return $at->between($from, $to) ? array_fill(0, max(1, (int) data_get($entity->meta, 'quantity', 1)), $at) : [];
            })
            ->values();
    }
}
