<?php

namespace Fleetbase\Storefront\Support;

use Fleetbase\FleetOps\Flow\Activity;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The steps of an order as its customer sees them, from the order's own order config.
 *
 * An order config's activity flow is a graph: each activity lists the activities that can
 * follow it, and some only apply under a condition (a pickup order goes to "ready for
 * pickup" instead of "driver en route"). For one order that becomes a line:
 *
 * - the activities the order has actually been through, from its tracking history;
 * - its current activity;
 * - then the path it is expected to take, choosing at each branch the activity whose
 *   condition this order meets, and an unconditional one otherwise. Cancellation
 *   branches are never projected.
 */
class OrderActivityFlow
{
    /** Projected steps stop here, so a looping flow can't run on. */
    public const MAX_PROJECTED = 20;

    /**
     * The order's steps, with its config's identity.
     */
    public static function forOrder(Order $order): array
    {
        // Plain queries: only a few columns are needed, and the tracking status model's
        // spatial columns are not.
        $db      = DB::connection($order->getConnectionName());
        $config  = $order->order_config_uuid ? $db->table('order_configs')->where('uuid', $order->order_config_uuid)->first(['public_id', 'key', 'name', 'flow']) : null;
        $flow    = $config ? (json_decode((string) $config->flow, true) ?: []) : [];
        $history = $order->tracking_number_uuid
            ? $db->table('tracking_statuses')
                ->where('tracking_number_uuid', $order->tracking_number_uuid)
                ->whereNull('deleted_at')
                ->orderBy('created_at')
                ->get(['code', 'status', 'created_at'])
                ->map(fn ($status) => ['code' => $status->code, 'label' => $status->status, 'at' => $status->created_at ? Carbon::parse($status->created_at)->toIso8601String() : null])
                ->all()
            : [];
        $tokens = ['storefront' => ['name' => $order->getMeta('storefront')]];
        $passes = function (array $activity) use ($order, $flow): bool {
            try {
                return (new Activity($activity, $flow))->passes($order);
            } catch (\Throwable $e) {
                return false;
            }
        };

        return [
            'order'        => $order->public_id,
            'order_config' => $config ? ['id' => $config->public_id, 'key' => $config->key, 'name' => $config->name] : null,
            ...static::resolve($flow, $order->status, $history, $passes, $order->created_at ? Carbon::parse($order->created_at)->toIso8601String() : null, $tokens),
        ];
    }

    /**
     * @param array       $flow      the order config's activities, keyed by code
     * @param string|null $status    the order's current status code
     * @param array       $history   tracking statuses, oldest first: ['code', 'label', 'at']
     * @param callable    $passes    whether an activity's conditions hold for the order
     * @param string|null $createdAt when the order was placed
     * @param array       $tokens    values for `{placeholders}` in activity details
     */
    public static function resolve(array $flow, ?string $status, array $history, callable $passes, ?string $createdAt = null, array $tokens = []): array
    {
        $activities = [];
        foreach ($flow as $key => $activity) {
            if (is_array($activity)) {
                $code              = static::code($activity['code'] ?? $key);
                // A flow keyed by code may leave `code` out of the activity itself.
                $activities[$code] = $activity + ['code' => $code];
            }
        }

        // The path so far: each activity once, in the order it was first reached.
        $path    = [];
        $reached = [];
        foreach ($history as $entry) {
            $code = static::code($entry['code'] ?? null);
            // Reached activities may have no timestamp, so check for the key, not a value.
            if ($code === '' || array_key_exists($code, $reached)) {
                continue;
            }
            $reached[$code] = $entry['at'] ?? null;
            $path[]         = ['code' => $code, 'label' => $entry['label'] ?? null];
        }
        if (isset($activities['created']) && !array_key_exists('created', $reached)) {
            array_unshift($path, ['code' => 'created', 'label' => null]);
            $reached['created'] = $createdAt;
        }

        $current = static::code($status);
        if ($current !== '' && !array_key_exists($current, $reached)) {
            $path[]            = ['code' => $current, 'label' => null];
            $reached[$current] = null;
        }
        $currentIndex = $current === '' ? count($path) - 1 : array_search($current, array_column($path, 'code'), true);

        $canceled  = $current !== '' && static::isCancellation($current, $activities[$current] ?? []);
        $completed = !$canceled && static::isComplete($activities[$current] ?? []);

        // Where the order is expected to go next.
        $upcoming = [];
        if (!$canceled && !$completed) {
            $visited = array_flip(array_column($path, 'code'));
            $cursor  = $activities[$current] ?? null;
            while ($cursor && count($upcoming) < static::MAX_PROJECTED) {
                $next = static::nextActivity($cursor, $activities, $visited, $passes);
                if (!$next) {
                    break;
                }
                $code           = static::code($next['code']);
                $visited[$code] = true;
                $upcoming[]     = ['code' => $code, 'label' => null];
                $cursor         = static::isComplete($next) ? null : $next;
            }
        }

        $steps = [];
        foreach (array_merge($path, $upcoming) as $index => $step) {
            $activity = $activities[$step['code']] ?? [];
            $state    = $index < $currentIndex ? 'done' : ($index === $currentIndex ? ($completed ? 'done' : 'current') : 'upcoming');
            $steps[]  = [
                'code'       => $step['code'],
                'label'      => $activity['status'] ?? $step['label'] ?? static::humanize($step['code']),
                'details'    => isset($activity['details']) ? static::fill((string) $activity['details'], $tokens) : null,
                'state'      => $state,
                'reached_at' => $state === 'upcoming' ? null : ($reached[$step['code']] ?? null),
                'complete'   => static::isComplete($activity),
            ];
        }

        // What the order can be moved to now: the current activity's next activities whose
        // conditions hold, cancellations aside; the one the order is expected to take first.
        $next = [];
        if (!$canceled && !$completed && isset($activities[$current])) {
            foreach ((array) ($activities[$current]['activities'] ?? []) as $nextCode) {
                $code     = static::code($nextCode);
                $activity = $activities[$code] ?? null;
                if (!$activity || static::isCancellation($code, $activity) || !$passes($activity)) {
                    continue;
                }
                $next[] = static::option($activity, $code, $code === ($upcoming[0]['code'] ?? null));
            }
            usort($next, fn ($a, $b) => $b['expected'] <=> $a['expected']);
        }

        // The activity that finishes the order: where the expected path ends, else the first
        // completing activity this order qualifies for.
        $completion = null;
        if (!$canceled && !$completed) {
            $last = end($upcoming) ?: null;
            if ($last && static::isComplete($activities[$last['code']] ?? [])) {
                $completion = static::option($activities[$last['code']], $last['code'], false);
            } else {
                foreach ($activities as $code => $activity) {
                    if (static::isComplete($activity) && !static::isCancellation($code, $activity) && $passes($activity)) {
                        $completion = static::option($activity, $code, false);
                        break;
                    }
                }
            }
        }

        return ['status' => $current ?: null, 'canceled' => $canceled, 'completed' => $completed, 'steps' => $steps, 'next' => $next, 'completion' => $completion];
    }

    /**
     * An activity the console can move the order to.
     */
    protected static function option(array $activity, string $code, bool $expected): array
    {
        return [
            'code'        => $code,
            'label'       => $activity['status'] ?? static::humanize($code),
            'complete'    => static::isComplete($activity),
            'require_pod' => filter_var($activity['require_pod'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'expected'    => $expected,
        ];
    }

    /**
     * The activity this order is expected to take after `$activity`: among the following
     * activities it hasn't been through, not cancellations, whose conditions pass, one
     * with conditions (it was chosen for this kind of order) before one without.
     */
    protected static function nextActivity(array $activity, array $activities, array $visited, callable $passes): ?array
    {
        $candidates = [];
        foreach ((array) ($activity['activities'] ?? []) as $nextCode) {
            $code = static::code($nextCode);
            $next = $activities[$code] ?? null;
            if (!$next || isset($visited[$code]) || static::isCancellation($code, $next) || !$passes($next)) {
                continue;
            }
            $candidates[] = $next;
        }

        foreach ($candidates as $candidate) {
            if (!empty($candidate['logic'])) {
                return $candidate;
            }
        }

        return $candidates[0] ?? null;
    }

    protected static function isCancellation(string $code, array $activity): bool
    {
        return in_array($code, ['canceled', 'cancelled', 'order_canceled'], true) || in_array('order.canceled', (array) ($activity['events'] ?? []), true);
    }

    protected static function isComplete(array $activity): bool
    {
        return filter_var($activity['complete'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    protected static function code(mixed $code): string
    {
        return strtolower(trim((string) $code));
    }

    protected static function humanize(string $code): string
    {
        return ucfirst(str_replace('_', ' ', $code));
    }

    /** Fill `{storefront.name}`-style placeholders; ones without a value are dropped. */
    protected static function fill(string $text, array $tokens): string
    {
        $filled = preg_replace_callback('/\{([a-z0-9_.]+)\}/i', function ($match) use ($tokens) {
            $value = data_get($tokens, $match[1]);

            return is_scalar($value) ? (string) $value : '';
        }, $text);

        return trim(preg_replace('/\s{2,}/', ' ', $filled));
    }
}
