<?php

namespace Fleetbase\Storefront\Http\Resources;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Models\ChatChannel;
use Fleetbase\Storefront\Support\OrderChat as OrderChatSupport;

/**
 * An order chat, as the customer's app shows it: who is in it, whether the customer can
 * still write, the latest messages and how many are unread.
 *
 * @mixin ChatChannel
 */
class OrderChat extends FleetbaseResource
{
    /**
     * @param iterable<\Fleetbase\Models\ChatMessage> $messages latest messages, oldest first
     */
    public function __construct(ChatChannel $channel, public Order $order, public ?string $customerUserUuid, public iterable $messages = [], public int $unreadCount = 0)
    {
        parent::__construct($channel);
    }

    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return array
     */
    public function toArray($request)
    {
        $closed = OrderChatSupport::isClosed($this->order);
        $me     = $this->participants->firstWhere('user_uuid', $this->customerUserUuid);

        return [
            'id'           => $this->public_id,
            'channel'      => 'chat_channel.' . $this->uuid,
            'order'        => $this->order->public_id,
            'status'       => $closed ? 'closed' : 'open',
            'me'           => data_get($me, 'public_id'),
            'participants' => $this->participants->map(fn ($participant) => [
                'id'         => $participant->public_id,
                'role'       => $participant->user_uuid === $this->customerUserUuid ? 'customer' : 'driver',
                'name'       => data_get($participant, 'user.name'),
                // Null without an uploaded avatar, so the app shows initials instead of a placeholder.
                'avatar_url' => data_get($participant, 'user.avatar_uuid') ? data_get($participant, 'user.avatar_url') : null,
                'is_online'  => (bool) $participant->is_online,
            ])->values()->all(),
            'messages'     => OrderChatMessage::list($this->messages, $this->customerUserUuid),
            'unread_count' => $this->unreadCount,
        ];
    }
}
