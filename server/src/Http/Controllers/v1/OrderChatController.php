<?php

namespace Fleetbase\Storefront\Http\Controllers\v1;

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\ChatAttachment;
use Fleetbase\Models\ChatChannel;
use Fleetbase\Models\ChatMessage;
use Fleetbase\Models\ChatParticipant;
use Fleetbase\Models\ChatReceipt;
use Fleetbase\Models\File;
use Fleetbase\Storefront\Http\Requests\SendOrderChatMessageRequest;
use Fleetbase\Storefront\Http\Resources\OrderChat as OrderChatResource;
use Fleetbase\Storefront\Http\Resources\OrderChatMessage;
use Fleetbase\Storefront\Support\OrderChat;
use Fleetbase\Storefront\Support\Storefront;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Chat between a signed-in customer and the driver delivering one of their orders.
 *
 * storefront/v1/orders/{id}/chat
 */
class OrderChatController extends Controller
{
    public const PAGE_SIZE     = 50;
    public const MAX_PAGE_SIZE = 100;

    /**
     * The order's chat with its participants and latest messages. Starts the chat on first use.
     */
    public function show(string $id)
    {
        $context = $this->resolveContext($id);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        [$order, $customer, $channel] = $context;

        $customerUserUuid = $customer->user_uuid;
        $participant      = OrderChat::participantFor($channel, $customerUserUuid);
        $messages         = $this->messageQuery($channel)->limit(static::PAGE_SIZE)->get()->reverse()->values();
        $unread           = $participant ? $channel->getUnreadMessagesForParticipant($participant)->count() : 0;

        $channel->load(['participants.user']);

        return new OrderChatResource($channel, $order, $customerUserUuid, $messages, $unread);
    }

    /**
     * Older messages, newest first in the query and returned oldest first.
     *
     * Query params: `before` (a message id) and `limit` (up to 100).
     */
    public function messages(Request $request, string $id)
    {
        $context = $this->resolveContext($id);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        [, $customer, $channel] = $context;

        $limit = min(max((int) $request->input('limit', static::PAGE_SIZE), 1), static::MAX_PAGE_SIZE);
        $query = $this->messageQuery($channel);

        if ($request->filled('before')) {
            $before = ChatMessage::where('chat_channel_uuid', $channel->uuid)->where('public_id', $request->input('before'))->first();
            if (!$before) {
                return response()->apiError('Message not found.', 404);
            }
            $query->where(fn ($older) => $older->where('created_at', '<', $before->created_at)
                ->orWhere(fn ($sameTime) => $sameTime->where('created_at', $before->created_at)->where('id', '<', $before->id)));
        }

        $messages = $query->limit($limit)->get()->reverse()->values();

        return response()->json(['messages' => OrderChatMessage::list($messages, $customer->user_uuid)]);
    }

    /**
     * Send a message as the customer. Closed once the order is completed or canceled.
     */
    public function send(SendOrderChatMessageRequest $request, string $id)
    {
        $context = $this->resolveContext($id);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        [$order, $customer, $channel] = $context;

        if (OrderChat::isClosed($order)) {
            return response()->json(['error' => 'This chat closed when the order finished.', 'reason' => 'chat_closed'], 423);
        }

        $sender = OrderChat::participantFor($channel, $customer->user_uuid);
        if (!$sender) {
            return response()->apiError('You are not part of this chat.', 403);
        }

        $message = ChatMessage::create([
            'company_uuid'      => $channel->company_uuid,
            'chat_channel_uuid' => $channel->uuid,
            'sender_uuid'       => $sender->uuid,
            'content'           => (string) $request->input('content', ''),
        ]);

        foreach ((array) $request->input('files', []) as $upload) {
            $this->attachPhoto($message, $sender, $customer, $upload);
        }

        $message->load(['sender.user', 'attachments.file', 'receipts']);
        $message->notifyParticipants();

        return response()->json(['message' => (new OrderChatMessage($message, $customer->user_uuid))->resolve()]);
    }

    /**
     * Mark every message from the driver as read by the customer.
     */
    public function read(string $id)
    {
        $context = $this->resolveContext($id);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        [, $customer, $channel] = $context;

        $participant = OrderChat::participantFor($channel, $customer->user_uuid);
        if (!$participant) {
            return response()->apiError('You are not part of this chat.', 403);
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
     * Resolve the signed-in customer, their order in this storefront and its chat.
     *
     * @return array{0: Order, 1: Contact, 2: ChatChannel}|JsonResponse
     */
    protected function resolveContext(string $id): array|JsonResponse
    {
        $customer = Storefront::getCustomerFromToken();
        if (!$customer) {
            return response()->apiError('Customer is not authenticated.', 401);
        }

        $order = Order::where('public_id', $id)->with(['customer', 'driverAssigned'])->first();
        if (!$order || !$this->belongsToStorefront($order)) {
            return response()->apiError('Order not found.', 404);
        }

        if ($order->customer_uuid !== $customer->uuid) {
            return response()->apiError('Not authorized to view this order.', 403);
        }

        // Once the order is finished the chat stays readable, but is not started any more.
        $channel = OrderChat::isClosed($order) ? OrderChat::find($order) : OrderChat::open($order);
        if (!$channel) {
            return response()->json([
                'error'  => 'You can message your driver once one is assigned to this order.',
                'reason' => 'driver_not_assigned',
            ], 409);
        }

        return [$order, $customer, $channel];
    }

    /**
     * Whether the order was placed through the storefront the request is made with.
     */
    protected function belongsToStorefront(Order $order): bool
    {
        $about = Storefront::about();
        if (!$about) {
            return false;
        }

        $storefrontId = $about->is_network ? $order->getMeta('storefront_network_id') : $order->getMeta('storefront_id');

        return $storefrontId === $about->public_id;
    }

    protected function messageQuery(ChatChannel $channel): Builder
    {
        return ChatMessage::where('chat_channel_uuid', $channel->uuid)
            ->with(['sender.user', 'attachments.file', 'receipts'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * Store a base64 photo and attach it to the message. Written without a public ACL: the
     * media bucket is private and file URLs are served through the File model.
     *
     * @param array{data?: string, type?: string} $upload
     */
    protected function attachPhoto(ChatMessage $message, ChatParticipant $sender, Contact $customer, array $upload): void
    {
        $disk      = config('filesystems.default');
        $bucket    = config('filesystems.disks.' . $disk . '.bucket', config('filesystems.disks.s3.bucket'));
        $data      = base64_decode((string) ($upload['data'] ?? ''));
        $mimeType  = (string) ($upload['type'] ?? 'image/jpeg');
        $extension = File::getExtensionFromMimeType($mimeType);
        $path      = 'hyperstore/' . data_get(Storefront::about(), 'public_id') . '/order-chat/' . $message->chat_channel_uuid . '/' . File::randomFileName($extension);

        Storage::disk($disk)->put($path, $data);

        $file = File::create([
            'company_uuid'      => $message->company_uuid,
            'uploader_uuid'     => $customer->user_uuid,
            'subject_uuid'      => $message->uuid,
            'subject_type'      => ChatMessage::class,
            'disk'              => $disk,
            'original_filename' => basename($path),
            'content_type'      => $mimeType,
            'path'              => $path,
            'bucket'            => $bucket,
            'type'              => 'storefront_chat_upload',
            'file_size'         => strlen($data),
        ]);

        ChatAttachment::create([
            'company_uuid'      => $message->company_uuid,
            'chat_channel_uuid' => $message->chat_channel_uuid,
            'chat_message_uuid' => $message->uuid,
            'sender_uuid'       => $sender->uuid,
            'file_uuid'         => $file->uuid,
        ]);
    }
}
