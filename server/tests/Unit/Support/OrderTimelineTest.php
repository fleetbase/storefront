<?php

require_once __DIR__ . '/../../Support/OrderOpsCoverageHelpers.php';

use Fleetbase\Storefront\Support\OrderChat;
use Fleetbase\Storefront\Support\OrderTimeline;

function orderTimelineStatus(string $at, string $status, string $details, string $code): object
{
    return (object) ['created_at' => $at, 'status' => $status, 'details' => $details, 'code' => $code];
}

function orderTimelineComment(array $attributes, ?string $author = null, array $meta = []): object
{
    return (object) array_merge(['public_id' => null, 'uuid' => null, 'meta' => $meta, 'author' => $author ? (object) ['name' => $author] : null], $attributes);
}

beforeEach(function () {
    orderOpsCoverageSchema();
    $this->notifications = orderOpsCoverageBoot();
});

afterEach(function () {
    orderOpsCoverageShutdown();
});

test('the timeline puts steps, notes, edits, payments and messages in one feed, newest first', function () {
    $order = orderOpsCoverageOrder('order_timeline', [], [
        'storefront_id'       => 'store_bloom',
        'currency'            => 'SGD',
        'gateway'             => 'stripe',
        'edits'               => [
            ['at' => '2026-10-14T10:20:00+00:00', 'summary' => 'Removed Mango Lassi', 'by_name' => 'Sam Store', 'changes' => ['items']],
            // An edit that was never dated is left out.
            ['summary' => 'Draft'],
        ],
        'payment_adjustments' => [
            ['type' => 'refund', 'amount' => 450, 'gateway' => 'stripe', 'reference' => 're_123', 'reason' => 'Removed Mango Lassi', 'at' => '2026-10-14T10:21:00+00:00'],
        ],
    ]);
    $order->setRelation('trackingStatuses', collect([
        orderTimelineStatus('2026-10-14 10:00:00', 'Order created', 'New order was created.', 'CREATED'),
        orderTimelineStatus('2026-10-14 10:30:00', 'Driver dispatched', 'Ravi is on the way.', 'DISPATCHED'),
    ]));
    $order->setRelation('comments', collect([
        orderTimelineComment(['public_id' => 'comment_public', 'content' => 'Customer called about the gate code.', 'created_at' => '2026-10-14 10:10:00'], 'Sam Store'),
        orderTimelineComment(['uuid' => 'comment_uuid', 'content' => 'Shared with the driver.', 'created_at' => '2026-10-14 10:25:00'], null, ['visibility' => 'driver']),
    ]));
    $channel = OrderChat::open($order);
    orderOpsCoverageSay($channel, 'customer_user_uuid', 'Gate code is 4412.', '2026-10-14 10:35:00');

    $timeline = OrderTimeline::forOrder($order);

    expect(collect($timeline)->map(fn ($entry) => $entry['type'] . ' ' . $entry['at'])->all())->toBe([
        'message 2026-10-14T10:35:00+00:00',
        'status 2026-10-14T10:30:00+00:00',
        'note 2026-10-14T10:25:00+00:00',
        'payment 2026-10-14T10:21:00+00:00',
        'edit 2026-10-14T10:20:00+00:00',
        'note 2026-10-14T10:10:00+00:00',
        'status 2026-10-14T10:00:00+00:00',
    ])
        ->and($timeline[0])->toBe([
            'type'  => 'message',
            'at'    => '2026-10-14T10:35:00+00:00',
            'title' => null,
            'body'  => 'Gate code is 4412.',
            'by'    => 'Mei Tan',
            'meta'  => ['role' => null, 'system' => false],
        ])
        ->and($timeline[1])->toBe([
            'type'  => 'status',
            'at'    => '2026-10-14T10:30:00+00:00',
            'title' => 'Driver dispatched',
            'body'  => 'Ravi is on the way.',
            'by'    => null,
            'meta'  => ['code' => 'DISPATCHED'],
        ])
        ->and($timeline[2]['by'])->toBeNull()
        ->and($timeline[2]['meta'])->toBe(['id' => 'comment_uuid', 'visibility' => 'driver'])
        ->and($timeline[3])->toBe([
            'type'  => 'payment',
            'at'    => '2026-10-14T10:21:00+00:00',
            'title' => 'refund',
            'body'  => 'Removed Mango Lassi',
            'by'    => null,
            'meta'  => ['amount' => 450, 'gateway' => 'stripe', 'reference' => 're_123', 'currency' => 'SGD'],
        ])
        ->and($timeline[4])->toBe([
            'type'  => 'edit',
            'at'    => '2026-10-14T10:20:00+00:00',
            'title' => null,
            'body'  => 'Removed Mango Lassi',
            'by'    => 'Sam Store',
            'meta'  => ['changes' => ['items']],
        ])
        ->and($timeline[5]['by'])->toBe('Sam Store')
        ->and($timeline[5]['meta'])->toBe(['id' => 'comment_public', 'visibility' => 'internal']);
});

test('the timeline of an order without a chat has no messages, even when chats cannot be read', function () {
    $order = orderOpsCoverageOrder('order_timeline_quiet', [], ['storefront_id' => 'store_bloom', 'edits' => 'not a list']);
    $order->setRelation('trackingStatuses', collect([orderTimelineStatus('2026-10-14 10:00:00', 'Order created', 'New order was created.', 'CREATED')]));
    $order->setRelation('comments', collect());

    $quiet = OrderTimeline::forOrder($order);
    orderOpsCoverageDb()->getSchemaBuilder()->drop('chat_channels');
    $unreadable = OrderTimeline::forOrder($order);

    expect(collect($quiet)->pluck('type')->all())->toBe(['status'])
        ->and($unreadable)->toBe($quiet);
});

test('the timeline keeps the latest messages, with the system flag and the sender role', function () {
    $order   = orderOpsCoverageOrder('order_timeline_busy');
    $order->setRelation('trackingStatuses', collect());
    $order->setRelation('comments', collect());
    $channel = OrderChat::open($order);
    $driver  = Fleetbase\Models\ChatParticipant::where('chat_channel_uuid', $channel->uuid)->where('user_uuid', 'driver_user_uuid')->first();
    $driver->update(['meta' => ['role' => 'driver']]);
    for ($minute = 0; $minute < OrderTimeline::MAX_MESSAGES + 2; $minute++) {
        orderOpsCoverageSay($channel, 'driver_user_uuid', 'Update ' . $minute, sprintf('2026-10-14 10:%02d:00', $minute));
    }
    orderOpsCoverageDb()->table('chat_messages')->where('content', 'Update 51')->update(['meta' => json_encode(['system' => true])]);

    $timeline = OrderTimeline::forOrder($order);

    expect($timeline)->toHaveCount(OrderTimeline::MAX_MESSAGES)
        ->and($timeline[0]['body'])->toBe('Update 51')
        ->and($timeline[0]['by'])->toBe('Ravi K.')
        ->and($timeline[0]['meta'])->toBe(['role' => 'driver', 'system' => true])
        ->and($timeline[1]['meta'])->toBe(['role' => 'driver', 'system' => false])
        ->and(end($timeline)['body'])->toBe('Update 2');
});
