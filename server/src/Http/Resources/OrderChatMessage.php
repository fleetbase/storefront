<?php

namespace Fleetbase\Storefront\Http\Resources;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Models\ChatChannel;
use Fleetbase\Models\ChatMessage;
use Fleetbase\Storefront\Support\OrderChat;

/**
 * A message in an order chat, as the customer's app shows it.
 *
 * @mixin ChatMessage
 */
class OrderChatMessage extends FleetbaseResource
{
    /**
     * @param string|null $viewerUserUuid whose messages are `is_mine`: the customer in the app, the staff user in the console
     */
    public function __construct(ChatMessage $message, public ?string $customerUserUuid = null, public ?ChatChannel $channel = null, public ?string $viewerUserUuid = null)
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
        $viewer     = $this->viewerUserUuid ?? $this->customerUserUuid;
        $isMine     = $senderUser !== null && $senderUser === $viewer;
        $isSystem   = (bool) data_get($this->resource, 'meta.system');
        $role       = match (true) {
            $isSystem               => OrderChat::ROLE_SYSTEM,
            $this->channel !== null => OrderChat::roleOf($this->channel, $sender, $this->customerUserUuid),
            default                 => $senderUser !== null && $senderUser === $this->customerUserUuid ? OrderChat::ROLE_CUSTOMER : OrderChat::ROLE_DRIVER,
        };

        return [
            'id'          => $this->public_id,
            'content'     => $this->content,
            'sender'      => [
                'id'   => data_get($sender, 'public_id'),
                'role' => $role,
                'name' => data_get($sender, 'user.name'),
            ],
            'is_mine'     => $isMine,
            'system'      => $isSystem,
            'kind'        => data_get($this->resource, 'meta.kind'),
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
    public static function list(iterable $messages, ?string $customerUserUuid, ?ChatChannel $channel = null, ?string $viewerUserUuid = null): array
    {
        $items = [];
        foreach ($messages as $message) {
            $items[] = (new static($message, $customerUserUuid, $channel, $viewerUserUuid))->resolve();
        }

        return $items;
    }
}
