<?php

use Fleetbase\Storefront\Support\OrderActivityFlow;

/** The default storefront flow's shape: a pickup/delivery branch after "preparing". */
function storefrontFlow(): array
{
    $pickupOnly = [['type' => 'if', 'conditions' => [['field' => 'meta.is_pickup', 'operator' => 'equal', 'value' => 'true']]]];

    return [
        'created'                 => ['code' => 'created', 'status' => 'Order Created', 'activities' => ['started', 'canceled']],
        'started'                 => ['code' => 'started', 'status' => 'Order Started', 'activities' => ['canceled', 'preparing']],
        'preparing'               => ['code' => 'preparing', 'status' => 'Order is being prepared', 'details' => '{storefront.name} is preparing your order {unknown}', 'activities' => ['driver_enroute_to_store', 'pickup_ready']],
        'driver_enroute_to_store' => ['code' => 'driver_enroute_to_store', 'status' => 'Driver en-route', 'activities' => ['driver_picked_up']],
        'driver_picked_up'        => ['code' => 'driver_picked_up', 'status' => 'Driver picked up', 'activities' => ['driver_enroute']],
        'driver_enroute'          => ['code' => 'driver_enroute', 'status' => 'Driver en-route to you', 'activities' => ['completed']],
        'completed'               => ['code' => 'completed', 'status' => 'Order completed', 'complete' => true],
        'pickup_ready'            => ['code' => 'pickup_ready', 'status' => 'Ready for pickup', 'logic' => $pickupOnly, 'activities' => ['picked_up']],
        'picked_up'               => ['code' => 'picked_up', 'status' => 'Picked up', 'complete' => 'true'],
        'canceled'                => ['code' => 'canceled', 'status' => 'Order canceled', 'events' => ['order.canceled']],
        'not-an-activity'         => 'ignored',
    ];
}

function flowCodes(array $result): array
{
    return array_map(fn ($step) => $step['code'] . ':' . $step['state'], $result['steps']);
}

test('a delivery order follows the unconditional branch and stops at the completing activity', function () {
    $history = [
        ['code' => 'CREATED', 'label' => 'Order Created', 'at' => '2026-10-08T10:00:00+00:00'],
        ['code' => 'started', 'label' => 'Order Started', 'at' => '2026-10-08T10:01:00+00:00'],
        ['code' => 'started', 'label' => 'Order Started', 'at' => '2026-10-08T10:02:00+00:00'],
        ['code' => '', 'label' => 'Blank'],
        ['code' => 'preparing', 'label' => 'Order is being prepared', 'at' => '2026-10-08T10:03:00+00:00'],
    ];
    // Only pickup_ready has conditions, and a delivery order fails them.
    $result = OrderActivityFlow::resolve(storefrontFlow(), 'preparing', $history, fn ($activity) => empty($activity['logic']), null, ['storefront' => ['name' => 'Orchard Grocers']]);

    expect(flowCodes($result))->toBe([
        'created:done', 'started:done', 'preparing:current',
        'driver_enroute_to_store:upcoming', 'driver_picked_up:upcoming', 'driver_enroute:upcoming', 'completed:upcoming',
    ])
        ->and($result['status'])->toBe('preparing')
        ->and($result['canceled'])->toBeFalse()
        ->and($result['completed'])->toBeFalse()
        ->and($result['steps'][0]['reached_at'])->toBe('2026-10-08T10:00:00+00:00')
        ->and($result['steps'][1]['reached_at'])->toBe('2026-10-08T10:01:00+00:00')
        ->and($result['steps'][2]['details'])->toBe('Orchard Grocers is preparing your order')
        ->and($result['steps'][3]['reached_at'])->toBeNull()
        ->and($result['steps'][6]['complete'])->toBeTrue();
});

test('a pickup order takes the branch whose conditions it meets', function () {
    $result = OrderActivityFlow::resolve(storefrontFlow(), 'preparing', [], fn () => true, '2026-10-08T09:59:00+00:00');

    expect(flowCodes($result))->toBe(['created:done', 'preparing:current', 'pickup_ready:upcoming', 'picked_up:upcoming'])
        ->and($result['steps'][0]['reached_at'])->toBe('2026-10-08T09:59:00+00:00')
        ->and($result['steps'][1]['details'])->toBe('is preparing your order');
});

test('a custom linear flow shows its own steps and labels', function () {
    $flow = [
        'created'    => ['status' => 'Created', 'activities' => ['dispatched']],
        'dispatched' => ['status' => 'Dispatched', 'activities' => ['started']],
        'started'    => ['status' => 'Started', 'activities' => ['enroute']],
        'enroute'    => ['status' => 'Enroute', 'activities' => ['completed']],
        'completed'  => ['status' => 'Completed', 'complete' => true],
    ];
    $result = OrderActivityFlow::resolve($flow, 'started', [['code' => 'created', 'label' => 'Created'], ['code' => 'dispatched', 'label' => 'Dispatched']], fn () => true);

    expect(flowCodes($result))->toBe(['created:done', 'dispatched:done', 'started:current', 'enroute:upcoming', 'completed:upcoming'])
        ->and(array_column($result['steps'], 'label'))->toBe(['Created', 'Dispatched', 'Started', 'Enroute', 'Completed']);
});

test('completed and canceled orders project nothing further', function () {
    $completed = OrderActivityFlow::resolve(storefrontFlow(), 'completed', [['code' => 'created'], ['code' => 'completed']], fn () => true);
    $canceled  = OrderActivityFlow::resolve(storefrontFlow(), 'canceled', [['code' => 'created'], ['code' => 'started']], fn () => true);

    expect(flowCodes($completed))->toBe(['created:done', 'completed:done'])
        ->and($completed['completed'])->toBeTrue()
        ->and(flowCodes($canceled))->toBe(['created:done', 'started:done', 'canceled:current'])
        ->and($canceled['canceled'])->toBeTrue()
        ->and($canceled['completed'])->toBeFalse();
});

test('a status the flow does not know is still shown, with its tracking label or a readable code', function () {
    $tracked = OrderActivityFlow::resolve(storefrontFlow(), 'at_warehouse', [['code' => 'created'], ['code' => 'at_warehouse', 'label' => 'At Warehouse']], fn () => true);
    $untracked = OrderActivityFlow::resolve([], 'at_warehouse', [], fn () => true);
    $empty     = OrderActivityFlow::resolve([], null, [], fn () => true);
    $historyOnly = OrderActivityFlow::resolve([], null, [['code' => 'created', 'label' => 'Created']], fn () => true);

    expect(flowCodes($tracked))->toBe(['created:done', 'at_warehouse:current'])
        ->and($tracked['steps'][1]['label'])->toBe('At Warehouse')
        ->and($untracked['steps'][0]['label'])->toBe('At warehouse')
        ->and($empty)->toBe(['status' => null, 'canceled' => false, 'completed' => false, 'steps' => []])
        ->and(flowCodes($historyOnly))->toBe(['created:current']);
});

test('projection skips cancellations, visited activities and failing branches, and stops at a limit', function () {
    $loop = [
        'created' => ['activities' => ['canceled', 'missing', 'created', 'step_0']],
        'canceled' => ['activities' => []],
        'cancel_event' => ['events' => ['order.canceled']],
    ];
    for ($i = 0; $i < 30; $i++) {
        $loop["step_$i"] = ['activities' => ['cancel_event', 'step_' . ($i + 1)]];
    }
    $result = OrderActivityFlow::resolve($loop, 'created', [], fn () => true);
    $blocked = OrderActivityFlow::resolve(['created' => ['activities' => ['next']], 'next' => []], 'created', [], fn () => false);

    expect($result['steps'])->toHaveLength(1 + OrderActivityFlow::MAX_PROJECTED)
        ->and($result['steps'][1]['code'])->toBe('step_0')
        ->and(flowCodes($blocked))->toBe(['created:current']);
});
