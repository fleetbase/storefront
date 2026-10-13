<?php

namespace Fleetbase\Storefront\Support;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Models\ChatChannel;
use Fleetbase\Models\ChatParticipant;
use Illuminate\Support\Facades\Schema;

/**
 * The chats about a storefront order: the customer with the driver delivering it (`driver`, the
 * original thread), and the customer with the store (`customer`, which needs no driver).
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
    public const META_KIND       = 'kind';

    /** The customer and the driver. Channels made before kinds existed are this kind. */
    public const KIND_DRIVER = 'driver';

    /** The customer and the store's staff. */
    public const KIND_CUSTOMER = 'customer';

    public const KINDS = [self::KIND_DRIVER, self::KIND_CUSTOMER];

    public const ROLE_CUSTOMER = 'customer';
    public const ROLE_DRIVER   = 'driver';
    public const ROLE_STORE    = 'store';
    public const ROLE_SYSTEM   = 'system';

    /** @var array<string, bool> */
    protected static array $metaColumns = [];

    /**
     * Order statuses after which the customer can read the chat but no longer send messages.
     */
    public const CLOSED_STATUSES = ['completed', 'canceled', 'expired'];

    /**
     * The order's existing chat, if one was started.
     */
    public static function find(Order $order, string $kind = self::KIND_DRIVER): ?ChatChannel
    {
        return ChatChannel::where('company_uuid', $order->company_uuid)
            ->where('meta->' . static::META_ORDER_UUID, $order->uuid)
            ->when(
                $kind === static::KIND_DRIVER,
                fn ($query) => $query->where(fn ($query) => $query->whereNull('meta->' . static::META_KIND)->orWhere('meta->' . static::META_KIND, static::KIND_DRIVER)),
                fn ($query) => $query->where('meta->' . static::META_KIND, $kind)
            )
            ->first();
    }

    public static function normalizeKind(?string $kind): string
    {
        return in_array($kind, static::KINDS, true) ? $kind : static::KIND_DRIVER;
    }

    public static function kindOf(ChatChannel $channel): string
    {
        return static::normalizeKind(data_get($channel->meta, static::META_KIND));
    }

    /**
     * The order's thread with the store, started when needed. Works before a driver is assigned.
     * The customer and any staff who write in it take part; staff are never removed.
     * Null when neither the customer nor a staff user has an account to take part with.
     */
    public static function openStore(Order $order, ?string $staffUserUuid = null): ?ChatChannel
    {
        $customerUserUuid = static::customerUserUuid($order);
        $creator          = $customerUserUuid ?? $staffUserUuid;
        if (!$creator) {
            return null;
        }

        $channel = static::find($order, static::KIND_CUSTOMER);
        if (!$channel) {
            $channel = ChatChannel::create([
                'company_uuid'    => $order->company_uuid,
                'created_by_uuid' => $creator,
                'name'            => 'Order ' . $order->public_id . ' · store',
                'meta'            => [
                    static::META_ORDER_UUID => $order->uuid,
                    static::META_ORDER_ID   => $order->public_id,
                    static::META_KIND       => static::KIND_CUSTOMER,
                ],
            ]);
        }

        if ($customerUserUuid) {
            static::join($channel, $customerUserUuid, static::ROLE_CUSTOMER);
        }
        if ($staffUserUuid && $staffUserUuid !== $customerUserUuid) {
            static::join($channel, $staffUserUuid, static::ROLE_STORE);
        }

        return $channel;
    }

    /**
     * Add a user to the channel when they are not in it, with their role where the participant
     * table records roles.
     */
    public static function join(ChatChannel $channel, string $userUuid, string $role): ChatParticipant
    {
        $participant = static::participantFor($channel, $userUuid);
        if ($participant) {
            return $participant;
        }

        $attributes = [
            'company_uuid'      => $channel->company_uuid,
            'chat_channel_uuid' => $channel->uuid,
            'user_uuid'         => $userUuid,
        ];
        if (static::hasMetaColumn('chat_participants')) {
            $attributes['meta'] = ['role' => $role];
        }

        return ChatParticipant::create($attributes);
    }

    /**
     * Who a participant is in the thread: their recorded role, else the customer, else the
     * store in a store thread and the driver in a driver thread.
     */
    public static function roleOf(ChatChannel $channel, $participant, ?string $customerUserUuid): string
    {
        $recorded = data_get($participant, 'meta.role');
        if (in_array($recorded, [static::ROLE_CUSTOMER, static::ROLE_DRIVER, static::ROLE_STORE, static::ROLE_SYSTEM], true)) {
            return $recorded;
        }

        if ($customerUserUuid && data_get($participant, 'user_uuid') === $customerUserUuid) {
            return static::ROLE_CUSTOMER;
        }

        return static::kindOf($channel) === static::KIND_CUSTOMER ? static::ROLE_STORE : static::ROLE_DRIVER;
    }

    /**
     * Whether a chat table has the `meta` column (core-api 1.6.69 adds it).
     */
    public static function hasMetaColumn(string $table): bool
    {
        if (!array_key_exists($table, static::$metaColumns)) {
            try {
                static::$metaColumns[$table] = Schema::connection((new ChatParticipant())->getConnectionName())->hasColumn($table, 'meta');
            } catch (\Throwable $e) {
                static::$metaColumns[$table] = false;
            }
        }

        return static::$metaColumns[$table];
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
                    static::META_KIND       => static::KIND_DRIVER,
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
