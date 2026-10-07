<?php

namespace Fleetbase\Storefront\Support;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Models\ChatChannel;
use Fleetbase\Models\ChatParticipant;

/**
 * The chat between a storefront customer and the driver delivering their order.
 *
 * It is an ordinary core chat channel, so the driver sees it in the Navigator app's chat list
 * and both sides receive messages over the `chat_channel.{id}` socket channel. Storefront marks
 * it as an order chat with the order uuid in the channel meta, keeps its participants to the
 * customer and the currently assigned driver, and closes it to the customer once the order is
 * finished.
 */
class OrderChat
{
    public const META_ORDER_UUID = 'storefront_order_uuid';
    public const META_ORDER_ID   = 'storefront_order_id';

    /**
     * Order statuses after which the customer can read the chat but no longer send messages.
     */
    public const CLOSED_STATUSES = ['completed', 'canceled', 'expired'];

    /**
     * The order's existing chat, if one was started.
     */
    public static function find(Order $order): ?ChatChannel
    {
        return ChatChannel::where('company_uuid', $order->company_uuid)
            ->where('meta->' . static::META_ORDER_UUID, $order->uuid)
            ->first();
    }

    /**
     * The order's chat, started when needed, with the customer and the assigned driver as its
     * only participants. Null when either side has no user account yet, typically because no
     * driver has been assigned.
     */
    public static function open(Order $order): ?ChatChannel
    {
        $customerUserUuid = static::customerUserUuid($order);
        $driverUserUuid   = static::driverUserUuid($order);
        if (!$customerUserUuid || !$driverUserUuid) {
            return null;
        }

        $channel = static::find($order);
        if (!$channel) {
            // Creating the channel adds its creator, the customer, as the first participant.
            $channel = ChatChannel::create([
                'company_uuid'    => $order->company_uuid,
                'created_by_uuid' => $customerUserUuid,
                'name'            => 'Order ' . $order->public_id,
                'meta'            => [
                    static::META_ORDER_UUID => $order->uuid,
                    static::META_ORDER_ID   => $order->public_id,
                ],
            ]);
        }

        static::syncParticipants($channel, [$customerUserUuid, $driverUserUuid]);

        return $channel;
    }

    /**
     * Whether the customer can still send messages.
     */
    public static function isClosed(Order $order): bool
    {
        return in_array($order->status, static::CLOSED_STATUSES, true);
    }

    /**
     * The channel participant for a user, if they take part.
     */
    public static function participantFor(ChatChannel $channel, ?string $userUuid): ?ChatParticipant
    {
        if (!$userUuid) {
            return null;
        }

        return ChatParticipant::where('chat_channel_uuid', $channel->uuid)->where('user_uuid', $userUuid)->first();
    }

    /**
     * The order a chat channel belongs to, when it is an order chat.
     */
    public static function orderFor(ChatChannel $channel): ?Order
    {
        $orderUuid = data_get($channel->meta, static::META_ORDER_UUID);

        return $orderUuid ? Order::where('uuid', $orderUuid)->first() : null;
    }

    public static function customerUserUuid(Order $order): ?string
    {
        return data_get($order->customer, 'user_uuid');
    }

    public static function driverUserUuid(Order $order): ?string
    {
        return data_get($order->driverAssigned, 'user_uuid');
    }

    /**
     * Add missing participants and remove anyone else, such as a driver the order was taken
     * away from, so a reassigned order's chat moves to the new driver.
     *
     * @param array<int, string> $userUuids
     */
    protected static function syncParticipants(ChatChannel $channel, array $userUuids): void
    {
        $existing = ChatParticipant::where('chat_channel_uuid', $channel->uuid)->get();

        foreach ($existing as $participant) {
            if (!in_array($participant->user_uuid, $userUuids, true)) {
                $participant->delete();
            }
        }

        $present = $existing->pluck('user_uuid')->all();
        foreach ($userUuids as $userUuid) {
            if (!in_array($userUuid, $present, true)) {
                ChatParticipant::create([
                    'company_uuid'      => $channel->company_uuid,
                    'chat_channel_uuid' => $channel->uuid,
                    'user_uuid'         => $userUuid,
                ]);
            }
        }
    }
}
