<?php

require_once __DIR__ . '/../../../Support/OrderOpsCoverageHelpers.php';

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\Models\ChatMessage;
use Fleetbase\Models\ChatParticipant;
use Fleetbase\Models\User;
use Fleetbase\Storefront\Http\Resources\Order as StorefrontOrderResource;
use Fleetbase\Storefront\Http\Resources\OrderChatMessage;
use Illuminate\Http\Request;

function orderConsoleResourceRequest(string $uri): Request
{
    $request = Request::create('/' . $uri);
    $request->setLaravelSession(new Illuminate\Session\Store('order-console', new Illuminate\Session\ArraySessionHandler(120)));
    $request->setRouteResolver(fn () => new class($uri) {
        public array $action = [];

        public function __construct(private string $routeUri)
        {
        }

        public function uri(): string
        {
            return $this->routeUri;
        }
    });

    return $request;
}

beforeEach(function () {
    orderOpsCoverageSchema();
    $this->previousRequest = app('request');
});

afterEach(function () {
    app()->instance('request', $this->previousRequest);
    Illuminate\Support\Carbon::setTestNow();
});

test('the console order resource adds what a dispatcher needs; the public one does not', function () {
    Illuminate\Support\Carbon::setTestNow('2026-10-14 12:00:00');
    $order = new Order();
    $order->forceFill([
        'uuid'          => 'order_console_uuid',
        'public_id'     => 'order_console',
        'customer_uuid' => null,
        'status'        => 'started',
        'created_at'    => '2026-10-14 11:00:00',
        'meta'          => ['storefront_id' => 'store_bloom', 'currency' => 'SGD', 'gateway' => 'stripe', 'total' => 2500, 'is_pickup' => true],
    ]);
    $customer = new Contact();
    $customer->forceFill(['uuid' => 'contact_uuid', 'name' => 'Mei Tan']);
    $customer->setRelation('place', null);
    $customer->setRelation('places', collect());
    $customer->setRelation('user', null);
    $customer->setRelation('customFieldValues', collect());
    $payload = new Payload();
    $payload->forceFill(['uuid' => 'payload_uuid']);
    $payload->setRelation('entities', collect());
    $payload->setRelation('customFieldValues', collect());
    $order->setRelation('customer', $customer);
    $order->setRelation('payload', $payload);
    $order->setRelation('trackingStatuses', collect());
    $order->setRelation('comments', collect());
    $order->setRelation('files', collect());
    $order->setRelation('customFieldValues', collect());

    $internalRequest = orderConsoleResourceRequest('int/v1/storefront/orders/order_console');
    app()->instance('request', $internalRequest);
    $internal = (new StorefrontOrderResource($order))->toArray($internalRequest);

    $publicRequest = orderConsoleResourceRequest('v1/orders/order_console');
    app()->instance('request', $publicRequest);
    $public = (new StorefrontOrderResource($order))->toArray($publicRequest);

    // Placed at 11:00, prepared in the default 20 minutes, picked up at the store: 40 minutes late.
    expect($internal['due_at'])->toBe('2026-10-14T11:20:00+00:00')
        ->and($internal['late_minutes'])->toBe(40)
        ->and(collect($internal['alerts'])->pluck('code')->all())->toBe(['late'])
        ->and($internal['customer_summary'])->toBeNull()
        ->and($internal['tracking_url'])->toBeNull()
        ->and($internal['payment']['state'])->toBe('paid')
        ->and($public)->not->toHaveKeys(['due_at', 'late_minutes', 'alerts', 'customer_summary', 'tracking_url'])
        ->and($public['payment']['state'])->toBe('paid');
});

test('a chat message outside a known channel is the customer\'s when they sent it, else the driver\'s', function () {
    $customerUser = new User();
    $customerUser->forceFill(['name' => 'Mei Tan']);
    $driverUser = new User();
    $driverUser->forceFill(['name' => 'Ravi K.']);
    $message = function (string $senderUuid, string $userUuid, User $user, array $meta = []) {
        $sender = new ChatParticipant();
        $sender->forceFill(['uuid' => $senderUuid, 'public_id' => $senderUuid . '_public', 'user_uuid' => $userUuid]);
        $sender->setRelation('user', $user);
        $chatMessage = new ChatMessage();
        $chatMessage->forceFill(['uuid' => 'message_' . $senderUuid, 'public_id' => 'chat_message_' . $senderUuid, 'sender_uuid' => $senderUuid, 'content' => 'Hello', 'meta' => $meta]);
        $chatMessage->setRelation('sender', $sender);
        $chatMessage->setRelation('attachments', collect());
        $chatMessage->setRelation('receipts', collect());

        return $chatMessage;
    };

    $fromCustomer = (new OrderChatMessage($message('participant_customer', 'customer_user_uuid', $customerUser), 'customer_user_uuid'))->resolve();
    $fromDriver   = (new OrderChatMessage($message('participant_driver', 'driver_user_uuid', $driverUser), 'customer_user_uuid'))->resolve();
    $system       = (new OrderChatMessage($message('participant_system', 'driver_user_uuid', $driverUser, ['system' => true, 'kind' => 'order_update']), 'customer_user_uuid'))->resolve();

    expect($fromCustomer['sender'])->toBe(['id' => 'participant_customer_public', 'role' => 'customer', 'name' => 'Mei Tan'])
        ->and($fromCustomer['is_mine'])->toBeTrue()
        ->and($fromDriver['sender']['role'])->toBe('driver')
        ->and($fromDriver['is_mine'])->toBeFalse()
        ->and($fromDriver['read'])->toBeFalse()
        ->and($system['sender']['role'])->toBe('system')
        ->and($system['system'])->toBeTrue()
        ->and($system['kind'])->toBe('order_update');
});
