<?php

namespace Fleetbase\Storefront\Http\Resources;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Models\ChatMessage;

/**
 * A message in an order chat, as the customer's app shows it.
 *
 * @mixin ChatMessage
 */
class OrderChatMessage extends FleetbaseResource
{
    public function __construct(ChatMessage $message, public ?string $customerUserUuid = null)
    {
        parent::__construct($message);
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
        $sender     = $this->sender;
        $senderUser = data_get($sender, 'user_uuid');
        $isMine     = $senderUser !== null && $senderUser === $this->customerUserUuid;

        return [
            'id'          => $this->public_id,
            'content'     => $this->content,
            'sender'      => [
                'id'   => data_get($sender, 'public_id'),
                'role' => $isMine ? 'customer' : 'driver',
                'name' => data_get($sender, 'user.name'),
            ],
            'is_mine'     => $isMine,
            'attachments' => $this->attachments->map(fn ($attachment) => [
                'id'   => data_get($attachment, 'file.public_id'),
                'url'  => data_get($attachment, 'file.url'),
                'type' => data_get($attachment, 'file.content_type'),
            ])->values()->all(),
            // Read by someone other than the sender.
            'read'        => $this->receipts->contains(fn ($receipt) => $receipt->participant_uuid !== $this->sender_uuid),
            'created_at'  => $this->created_at,
        ];
    }

    /**
     * @param iterable<ChatMessage> $messages
     *
     * @return array<int, array>
     */
    public static function list(iterable $messages, ?string $customerUserUuid): array
    {
        $items = [];
        foreach ($messages as $message) {
            $items[] = (new static($message, $customerUserUuid))->resolve();
        }

        return $items;
    }
}
