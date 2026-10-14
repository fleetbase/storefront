<?php

namespace Fleetbase\Storefront\Http\Controllers;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\ChatChannel;
use Fleetbase\Models\ChatMessage;
use Fleetbase\Models\ChatReceipt;
use Fleetbase\Storefront\Http\Resources\OrderChat as OrderChatResource;
use Fleetbase\Storefront\Http\Resources\OrderChatMessage;
use Fleetbase\Storefront\Support\OrderChat;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

/**
 * The store's side of an order's chats, in the console: `customer` (store and customer),
 * `dispatch` (store and driver) and `group` (the customer's thread with the driver, which staff
 * join when they write in it, making it all three).
 *
 * storefront/int/v1/orders/{id}/chat/{kind}
 */
class OrderChatController extends Controller
{
    public const PAGE_SIZE = 50;

    /** Replies offered under the message box when the store has not set its own. */
    public const DEFAULT_QUICK_REPLIES = [
        'Your order is being prepared.',
        'Your driver is on the way.',
        'We are running about 10 minutes late, sorry.',
        'Can you confirm the delivery address?',
    ];

    public function show(string $id, string $kind)
    {
        $order = $this->findOrder($id);
        if (!$order) {
            return response()->error('Order not found', 404);
        }

        // Looking does not start a thread; the store's first message does.
        $kind     = OrderChat::normalizeKind($kind);
        $channel  = OrderChat::find($order, $kind);
        $canWrite = static::canWrite($order, $kind, $channel);

        if (!$channel) {
            return response()->json([
                'chat'          => null,
                'reason'        => $kind === OrderChat::KIND_CUSTOMER ? 'not_started' : (OrderChat::driverUserUuid($order) ? 'not_started' : 'driver_not_assigned'),
                'can_write'     => $canWrite,
                'quick_replies' => $canWrite ? static::quickReplies($order, $kind) : [],
            ]);
        }

        $messages = $this->messageQuery($channel)->limit(static::PAGE_SIZE)->get()->reverse()->values();
        // What the store has not read here, as the panel counts it (a staff member who has not
        // joined the thread still reads it for the store).
        $unread   = static::unread($order, session('user'))[static::unreadKey($kind)] ?? 0;
        $channel->load(['participants.user']);

        return response()->json([
            'chat'          => (new OrderChatResource($channel, $order, OrderChat::customerUserUuid($order), $messages, $unread, session('user')))->resolve(),
            'can_write'     => $canWrite,
            'quick_replies' => $canWrite ? static::quickReplies($order, $kind) : [],
        ]);
    }

    /**
     * Whether staff can write in the thread: the customer thread while the order is open, the
     * driver threads once a driver is assigned.
     */
    protected static function canWrite(Order $order, string $kind, ?ChatChannel $channel): bool
    {
        if (OrderChat::isClosed($order)) {
            return false;
        }

        return match ($kind) {
            OrderChat::KIND_CUSTOMER => true,
            OrderChat::KIND_DISPATCH => (bool) OrderChat::driverUserUuid($order),
            default                  => (bool) $channel || (OrderChat::driverUserUuid($order) && OrderChat::customerUserUuid($order)),
        };
    }

    /**
     * Write as the store in one of the order's threads.
     */
    public function send(Request $request, string $id, string $kind)
    {
        $request->validate(['content' => ['required', 'string', 'max:2000']]);

        $order = $this->findOrder($id);
        if (!$order) {
            return response()->error('Order not found', 404);
        }
        $kind = OrderChat::normalizeKind($kind);
        if (OrderChat::isClosed($order)) {
            return response()->error('This chat closed when the order finished.', 423);
        }

        $channel = match ($kind) {
            OrderChat::KIND_CUSTOMER => OrderChat::openStore($order, session('user')),
            OrderChat::KIND_DISPATCH => OrderChat::openDispatch($order, session('user')),
            default                  => OrderChat::find($order) ?? OrderChat::open($order),
        };
        if (!$channel) {
            return response()->error($kind === OrderChat::KIND_CUSTOMER ? 'This order has no chat with the store.' : 'Assign a driver to message them.', 409);
        }

        $sender  = OrderChat::join($channel, session('user'), OrderChat::ROLE_STORE);
        $message = ChatMessage::create([
            'company_uuid'      => $channel->company_uuid,
            'chat_channel_uuid' => $channel->uuid,
            'sender_uuid'       => $sender->uuid,
            'content'           => trim((string) $request->input('content')),
        ]);
        $message->load(['sender.user', 'attachments.file', 'receipts']);
        $message->notifyParticipants();

        return response()->json(['message' => (new OrderChatMessage($message, OrderChat::customerUserUuid($order), $channel, session('user')))->resolve()]);
    }

    public function read(string $id, string $kind)
    {
        $order   = $this->findOrder($id);
        $channel = $order ? OrderChat::find($order, OrderChat::normalizeKind($kind)) : null;
        if (!$channel) {
            return response()->json(['read' => 0]);
        }

        // A staff member reading a thread they have not written in joins it as the store, so the
        // receipt counts for the store.
        $participant = OrderChat::participantFor($channel, session('user')) ?? (session('user') ? OrderChat::join($channel, session('user'), OrderChat::ROLE_STORE) : null);
        if (!$participant) {
            return response()->json(['read' => 0]);
        }

        $unread = $channel->getUnreadMessagesForParticipant($participant);
        foreach ($unread as $message) {
            ChatReceipt::create([
                'company_uuid'      => $channel->company_uuid,
                'chat_message_uuid' => $message->uuid,
                'participant_uuid'  => $participant->uuid,
            ]);
        }

        return response()->json(['read' => $unread->count()]);
    }

    /**
     * Close the customer's "the address is wrong" request without changing the address.
     */
    public function dismissAddressChange(string $id)
    {
        $order = $this->findOrder($id);
        if (!$order) {
            return response()->error('Order not found', 404);
        }

        $request = $order->getMeta('address_change_request');
        if (is_array($request)) {
            $order->updateMeta('address_change_request', [...$request, 'status' => 'dismissed', 'closed_at' => now()->toIso8601String()]);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Messages the store has not read, per thread: what no staff member has a receipt for.
     *
     * @return array{customer: int, dispatch: int, group: int}
     */
    public static function unread(Order $order, ?string $viewerUuid = null): array
    {
        // Whoever is looking has read what they sent: the store owner can be the driver too.
        $from = fn (array $userUuids) => array_values(array_filter($userUuids, fn ($uuid) => $uuid && $uuid !== $viewerUuid));

        return [
            'customer' => static::unreadIn(OrderChat::find($order, OrderChat::KIND_CUSTOMER), $from([OrderChat::customerUserUuid($order)])),
            'dispatch' => static::unreadIn(OrderChat::find($order, OrderChat::KIND_DISPATCH), $from([OrderChat::driverUserUuid($order)])),
            'group'    => static::unreadIn(OrderChat::find($order), $from([OrderChat::customerUserUuid($order), OrderChat::driverUserUuid($order)])),
        ];
    }

    /** The key of a thread kind in unread(): the customer-and-driver thread is `group`. */
    protected static function unreadKey(string $kind): string
    {
        return $kind === OrderChat::KIND_DRIVER ? 'group' : $kind;
    }

    /**
     * Messages in a thread from the given users (the customer, the driver) that no one else has read.
     */
    protected static function unreadIn(?ChatChannel $channel, array $userUuids): int
    {
        $userUuids = array_values(array_filter($userUuids));
        if (!$channel || !$userUuids) {
            return 0;
        }

        $senders = \Fleetbase\Models\ChatParticipant::where('chat_channel_uuid', $channel->uuid)->whereIn('user_uuid', $userUuids)->pluck('uuid');
        if ($senders->isEmpty()) {
            return 0;
        }

        return ChatMessage::where('chat_channel_uuid', $channel->uuid)
            ->whereIn('sender_uuid', $senders)
            ->whereDoesntHave('receipts', fn ($query) => $query->whereNotIn('participant_uuid', $senders))
            ->count();
    }

    /**
     * Messages from the customer the store has not read: what no staff member has a receipt for.
     */
    public static function unreadFromCustomer(Order $order): int
    {
        $channel          = OrderChat::find($order, OrderChat::KIND_CUSTOMER);
        $customerUserUuid = OrderChat::customerUserUuid($order);
        if (!$channel || !$customerUserUuid) {
            return 0;
        }

        $customer = OrderChat::participantFor($channel, $customerUserUuid);
        if (!$customer) {
            return 0;
        }

        return ChatMessage::where('chat_channel_uuid', $channel->uuid)
            ->where('sender_uuid', $customer->uuid)
            ->whereDoesntHave('receipts', fn ($query) => $query->where('participant_uuid', '!=', $customer->uuid))
            ->count();
    }

    /**
     * The store's own replies (store option `quick_replies`), else the defaults.
     */
    public static function quickReplies(Order $order, string $kind = OrderChat::KIND_CUSTOMER): array
    {
        $isBooking = (bool) $order->isMeta('is_booking');
        if ($kind === OrderChat::KIND_DISPATCH) {
            return $isBooking ? static::DEFAULT_BOOKING_DRIVER_REPLIES : static::DEFAULT_DRIVER_REPLIES;
        }

        // The store's own replies (`options.booking_quick_replies` for bookings, else
        // `options.quick_replies`), else the defaults for the kind of order.
        $store   = \Fleetbase\Storefront\Models\Store::where('public_id', $order->getMeta('storefront_id'))->first();
        $own     = fn (string $option) => array_values(array_filter(array_map(fn ($reply) => is_string($reply) ? trim($reply) : '', (array) data_get($store?->options, $option, [])), fn ($reply) => $reply !== ''));
        $replies = $isBooking ? $own('booking_quick_replies') : $own('quick_replies');
        if ($replies) {
            return $replies;
        }

        if ($isBooking) {
            return $order->isMeta('is_pickup') ? static::DEFAULT_BOOKING_AT_STORE_REPLIES : static::DEFAULT_BOOKING_REPLIES;
        }

        return static::DEFAULT_QUICK_REPLIES;
    }

    /** Replies offered when writing to the driver. */
    public const DEFAULT_DRIVER_REPLIES = [
        'The order is ready for pickup.',
        'The drop-off address changed, please check the app.',
        'Please call the customer on arrival.',
        'How long until you reach the store?',
    ];

    /** Replies offered to the customer of a booking at their address (the provider goes to them). */
    public const DEFAULT_BOOKING_REPLIES = [
        'Your booking is confirmed.',
        'Your provider is on the way.',
        'We are running about 10 minutes late, sorry.',
        'Can you confirm the address and how to get in?',
    ];

    /** Replies offered to the customer of a booking at the store (they come in). */
    public const DEFAULT_BOOKING_AT_STORE_REPLIES = [
        'Your booking is confirmed.',
        'We are ready for you.',
        'We are running about 10 minutes behind, sorry.',
        'Would you like to change your time?',
    ];

    /** Replies offered when writing to the provider (the driver) of a booking. */
    public const DEFAULT_BOOKING_DRIVER_REPLIES = [
        'The booking is confirmed, please head to the customer.',
        'The booking time changed, please check the app.',
        'Please call the customer on arrival.',
        'How long until you reach the customer?',
    ];

    protected function findOrder(string $id): ?Order
    {
        try {
            return Order::findRecordOrFail($id, ['customer', 'driverAssigned']);
        } catch (ModelNotFoundException $exception) {
            return null;
        }
    }

    protected function messageQuery(ChatChannel $channel)
    {
        return ChatMessage::where('chat_channel_uuid', $channel->uuid)
            ->with(['sender.user', 'attachments.file', 'receipts'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }
}
