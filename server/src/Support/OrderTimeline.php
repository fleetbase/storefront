<?php

namespace Fleetbase\Storefront\Support;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Models\ChatMessage;
use Illuminate\Support\Carbon;

/**
 * One list of everything that happened to an order, newest first: the steps it went through,
 * staff notes, edits, payment changes and chat messages. Each entry has the same shape so the
 * console renders them as one feed:
 *
 * `{ type: status|note|edit|payment|message, at, title, body, by, meta }`
 */
class OrderTimeline
{
    public const MAX_MESSAGES = 50;

    public static function forOrder(Order $order): array
    {
        return collect()
            ->merge(static::statuses($order))
            ->merge(static::notes($order))
            ->merge(static::edits($order))
            ->merge(static::payments($order))
            ->merge(static::messages($order))
            ->filter(fn ($entry) => !empty($entry['at']))
            ->sortByDesc(fn ($entry) => Carbon::parse($entry['at'])->getTimestamp())
            ->values()
            ->all();
    }

    protected static function statuses(Order $order): array
    {
        return collect($order->trackingStatuses ?? [])
            ->map(fn ($status) => static::entry('status', $status->created_at, $status->status, $status->details, null, ['code' => $status->code]))
            ->all();
    }

    protected static function notes(Order $order): array
    {
        return collect($order->comments ?? [])
            ->map(fn ($comment) => static::entry(
                'note',
                $comment->created_at,
                null,
                $comment->content,
                data_get($comment, 'author.name'),
                ['id' => $comment->public_id ?? $comment->uuid, 'visibility' => data_get($comment, 'meta.visibility', 'internal')]
            ))
            ->all();
    }

    protected static function edits(Order $order): array
    {
        $edits = $order->getMeta('edits', []);

        return collect(is_array($edits) ? $edits : [])
            ->map(fn ($edit) => static::entry('edit', $edit['at'] ?? null, null, $edit['summary'] ?? null, $edit['by_name'] ?? null, ['changes' => $edit['changes'] ?? []]))
            ->all();
    }

    protected static function payments(Order $order): array
    {
        return collect(OrderPayments::adjustments($order))
            ->map(fn ($adjustment) => static::entry('payment', $adjustment['at'] ?? null, $adjustment['type'] ?? null, $adjustment['reason'] ?? null, null, [
                'amount'    => $adjustment['amount'] ?? 0,
                'gateway'   => $adjustment['gateway'] ?? null,
                'reference' => $adjustment['reference'] ?? null,
                'currency'  => $order->getMeta('currency'),
            ]))
            ->all();
    }

    protected static function messages(Order $order): array
    {
        try {
            $channel = OrderChat::find($order);
        } catch (\Throwable $e) {
            return [];
        }

        if (!$channel) {
            return [];
        }

        return ChatMessage::where('chat_channel_uuid', $channel->uuid)
            ->with(['sender.user'])
            ->latest()
            ->limit(static::MAX_MESSAGES)
            ->get()
            ->map(fn ($message) => static::entry(
                'message',
                $message->created_at,
                null,
                $message->content,
                data_get($message, 'sender.user.name') ?? data_get($message, 'sender.name'),
                ['role' => data_get($message, 'sender.meta.role'), 'system' => (bool) data_get($message, 'meta.system')]
            ))
            ->all();
    }

    protected static function entry(string $type, $at, ?string $title, ?string $body, ?string $by, array $meta = []): array
    {
        return [
            'type'  => $type,
            'at'    => $at ? Carbon::parse($at)->toIso8601String() : null,
            'title' => $title,
            'body'  => $body,
            'by'    => $by,
            'meta'  => $meta,
        ];
    }
}
