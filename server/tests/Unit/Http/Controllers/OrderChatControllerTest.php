<?php

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Models\ChatChannel;
use Fleetbase\Models\ChatMessage;
use Fleetbase\Models\ChatParticipant;
use Fleetbase\Storefront\Http\Controllers\v1\OrderChatController;
use Fleetbase\Storefront\Http\Requests\SendOrderChatMessageRequest;
use Fleetbase\Storefront\Notifications\StorefrontOrderChatMessage;
use Fleetbase\Storefront\Observers\ChatMessageObserver;
use Fleetbase\Storefront\Support\OrderChat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

const CHAT_CUSTOMER_UUID = '33333333-3333-4333-8333-333333333333';

/**
 * An in-memory disk that records writes; Storage::disk() returns it for every disk name.
 */
class OrderChatFakeDisk implements Illuminate\Contracts\Filesystem\Cloud
{
    public array $writes = [];

    public function disk(?string $name = null): self
    {
        return $this;
    }

    public function put($path, $contents, $options = [])
    {
        $this->writes[] = [$path, $contents, $options];

        return true;
    }

    public function url($path)
    {
        return 'https://cdn.test/' . $path;
    }

    public function exists($path)
    {
        return false;
    }

    public function get($path)
    {
        return null;
    }

    public function readStream($path)
    {
        return null;
    }

    public function writeStream($path, $resource, array $options = [])
    {
        return false;
    }

    public function getVisibility($path)
    {
        return 'private';
    }

    public function setVisibility($path, $visibility)
    {
        return false;
    }

    public function prepend($path, $data)
    {
        return false;
    }

    public function append($path, $data)
    {
        return false;
    }

    public function delete($paths)
    {
        return false;
    }

    public function copy($from, $to)
    {
        return false;
    }

    public function move($from, $to)
    {
        return false;
    }

    public function size($path)
    {
        return 0;
    }

    public function lastModified($path)
    {
        return 0;
    }

    public function files($directory = null, $recursive = false)
    {
        return [];
    }

    public function allFiles($directory = null)
    {
        return [];
    }

    public function directories($directory = null, $recursive = false)
    {
        return [];
    }

    public function allDirectories($directory = null)
    {
        return [];
    }

    public function makeDirectory($path)
    {
        return false;
    }

    public function deleteDirectory($directory)
    {
        return false;
    }
}

function orderChatDb(): Illuminate\Database\Connection
{
    return Model::getConnectionResolver()->connection('mysql');
}

function createOrderChatSchema(): void
{
    $schema = orderChatDb()->getSchemaBuilder();
    foreach (['stores', 'networks', 'contacts', 'personal_access_tokens', 'users', 'drivers', 'orders', 'chat_channels', 'chat_participants', 'chat_messages', 'chat_attachments', 'chat_receipts', 'chat_logs', 'files'] as $table) {
        $schema->dropIfExists($table);
    }
    foreach (['stores', 'networks'] as $storefrontTable) {
        $schema->create($storefrontTable, function ($table) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('public_id')->nullable();
            $table->string('company_uuid')->nullable();
            $table->string('key')->nullable();
            $table->string('name')->nullable();
            $table->text('options')->nullable();
            $table->text('translations')->nullable();
            $table->text('tags')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
    }
    $schema->create('contacts', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('type')->nullable();
        $table->string('name')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('personal_access_tokens', function ($table) {
        $table->increments('id');
        $table->string('tokenable_type')->nullable();
        $table->string('tokenable_id')->nullable();
        $table->string('name');
        $table->string('token', 64)->unique();
        $table->text('abilities')->nullable();
        $table->timestamp('last_used_at')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
    });
    $schema->create('users', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('avatar_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->string('type')->nullable();
        $table->string('status')->nullable();
        $table->timestamp('last_login')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('drivers', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('orders', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('customer_uuid')->nullable();
        $table->string('customer_type')->nullable();
        $table->string('driver_assigned_uuid')->nullable();
        $table->string('status')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('chat_channels', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('created_by_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('slug')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('chat_participants', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('chat_channel_uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('chat_messages', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('chat_channel_uuid')->nullable();
        $table->string('sender_uuid')->nullable();
        $table->text('content')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('chat_attachments', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('chat_channel_uuid')->nullable();
        $table->string('chat_message_uuid')->nullable();
        $table->string('sender_uuid')->nullable();
        $table->string('file_uuid')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('chat_receipts', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('chat_message_uuid')->nullable();
        $table->string('participant_uuid')->nullable();
        $table->timestamp('read_at')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('chat_logs', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('chat_channel_uuid')->nullable();
        $table->string('initiator_uuid')->nullable();
        $table->string('event_type')->nullable();
        $table->text('content')->nullable();
        $table->text('subjects')->nullable();
        $table->text('meta')->nullable();
        $table->string('status')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('files', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('uploader_uuid')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('name')->nullable();
        $table->string('original_filename')->nullable();
        $table->string('slug')->nullable();
        $table->string('disk')->nullable();
        $table->string('extension')->nullable();
        $table->string('content_type')->nullable();
        $table->string('path')->nullable();
        $table->string('bucket')->nullable();
        $table->string('type')->nullable();
        $table->integer('file_size')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });

    $db = orderChatDb();
    $db->table('stores')->insert(['uuid' => 'store_uuid', 'public_id' => 'store_bloom', 'company_uuid' => 'company_uuid', 'key' => 'store_key', 'name' => 'Bloom']);
    $db->table('networks')->insert(['uuid' => 'network_uuid', 'public_id' => 'network_market', 'company_uuid' => 'company_uuid', 'key' => 'network_key', 'name' => 'Market']);
    $db->table('users')->insert([
        ['uuid' => 'customer_user_uuid', 'public_id' => 'user_customer', 'company_uuid' => 'company_uuid', 'name' => 'Mei Tan'],
        ['uuid' => 'driver_user_uuid', 'public_id' => 'user_driver', 'company_uuid' => 'company_uuid', 'name' => 'Ravi K.'],
        ['uuid' => 'second_driver_user_uuid', 'public_id' => 'user_driver_2', 'company_uuid' => 'company_uuid', 'name' => 'Arun S.'],
    ]);
    $db->table('contacts')->insert([
        ['uuid' => CHAT_CUSTOMER_UUID, 'public_id' => 'contact_mei', 'company_uuid' => 'company_uuid', 'user_uuid' => 'customer_user_uuid', 'type' => 'customer', 'name' => 'Mei Tan'],
        ['uuid' => 'other_customer_uuid', 'public_id' => 'contact_other', 'company_uuid' => 'company_uuid', 'user_uuid' => 'other_user_uuid', 'type' => 'customer', 'name' => 'Other'],
    ]);
    $db->table('drivers')->insert([
        ['uuid' => 'driver_uuid', 'public_id' => 'driver_ravi', 'company_uuid' => 'company_uuid', 'user_uuid' => 'driver_user_uuid'],
        ['uuid' => 'second_driver_uuid', 'public_id' => 'driver_arun', 'company_uuid' => 'company_uuid', 'user_uuid' => 'second_driver_user_uuid'],
    ]);
    $order = fn (string $publicId, array $overrides = []) => array_merge([
        'uuid'                 => $publicId . '_uuid',
        'public_id'            => $publicId,
        'company_uuid'         => 'company_uuid',
        'customer_uuid'        => CHAT_CUSTOMER_UUID,
        'customer_type'        => Contact::class,
        'driver_assigned_uuid' => 'driver_uuid',
        'status'               => 'dispatched',
        'meta'                 => json_encode(['storefront_id' => 'store_bloom']),
    ], $overrides);
    $db->table('orders')->insert([
        $order('order_active'),
        $order('order_waiting', ['driver_assigned_uuid' => null]),
        $order('order_done', ['status' => 'completed']),
        $order('order_done_never_chatted', ['status' => 'completed']),
        $order('order_other_customer', ['customer_uuid' => 'other_customer_uuid']),
        $order('order_other_store', ['meta' => json_encode(['storefront_id' => 'store_elsewhere'])]),
        $order('order_network', ['meta' => json_encode(['storefront_id' => 'store_bloom', 'storefront_network_id' => 'network_market'])]),
    ]);
    $db->table('personal_access_tokens')->insert([
        'name'       => CHAT_CUSTOMER_UUID,
        'token'      => hash('sha256', 'chat-secret'),
        'abilities'  => '["*"]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function bindOrderChatRequest(?string $token = 'chat-secret', string $storefrontKey = 'store_key', array $input = [], string $method = 'GET', string $requestClass = Request::class): Request
{
    $request = $requestClass::create('/orders/chat', $method, $input);
    if ($token) {
        $request->headers->set('Customer-Token', $token);
    }
    $request->setLaravelSession(new Illuminate\Session\Store('order-chat', new Illuminate\Session\ArraySessionHandler(120)));
    app()->instance('request', $request);
    session(['company' => 'company_uuid', 'storefront_key' => $storefrontKey, 'storefront_store' => null, 'storefront_network' => null]);

    return $request;
}

function orderChatData($response): array
{
    return $response->resolve(request());
}

function driverSays(ChatChannel $channel, string $content, string $at, string $driverUserUuid = 'driver_user_uuid'): ChatMessage
{
    $sender = ChatParticipant::where('chat_channel_uuid', $channel->uuid)->where('user_uuid', $driverUserUuid)->first();

    $message = ChatMessage::create(['company_uuid' => 'company_uuid', 'chat_channel_uuid' => $channel->uuid, 'sender_uuid' => $sender->uuid, 'content' => $content]);
    orderChatDb()->table('chat_messages')->where('uuid', $message->uuid)->update(['created_at' => $at]);

    return $message->fresh();
}

/**
 * Records notifications instead of sending them.
 */
function fakeChatNotifications(): object
{
    $dispatcher = new class implements Illuminate\Contracts\Notifications\Dispatcher {
        public array $sent = [];

        public function send($notifiables, $notification)
        {
            $this->sendNow($notifiables, $notification);
        }

        public function sendNow($notifiables, $notification, ?array $channels = null)
        {
            foreach (is_iterable($notifiables) && !$notifiables instanceof Model ? $notifiables : [$notifiables] as $notifiable) {
                $this->sent[] = [$notifiable, $notification];
            }
        }

        public function sentTo(string $uuid, string $class): array
        {
            return array_values(array_map(fn ($pair) => $pair[1], array_filter($this->sent, fn ($pair) => data_get($pair[0], 'uuid') === $uuid && $pair[1] instanceof $class)));
        }
    };
    app()->instance(Illuminate\Contracts\Notifications\Dispatcher::class, $dispatcher);
    Illuminate\Support\Facades\Notification::clearResolvedInstance(Illuminate\Contracts\Notifications\Dispatcher::class);

    return $dispatcher;
}

beforeEach(function () {
    createOrderChatSchema();
    // Model events generate uuids and public ids, and run ChatChannel's creator hook.
    Model::setEventDispatcher(new Illuminate\Events\Dispatcher(app()));
    Model::clearBootedModels();
    app()->instance('responsecache', new class {
        public function clear(): void
        {
        }
    });
    Spatie\ResponseCache\Facades\ResponseCache::clearResolvedInstance('responsecache');
    // Core macros and a disabled activity log, as the framework would provide them.
    if (!Illuminate\Support\Str::hasMacro('humanize')) {
        Illuminate\Support\Str::macro('humanize', (new Fleetbase\Expansions\Str())->humanize());
    }
    app()->instance(Spatie\Activitylog\ActivityLogStatus::class, new Spatie\Activitylog\ActivityLogStatus(new Illuminate\Config\Repository(['activitylog' => ['enabled' => false]])));
    $this->notifications = fakeChatNotifications();
});

afterEach(function () {
    Model::unsetEventDispatcher();
    Model::clearBootedModels();
    app()->offsetUnset(Illuminate\Contracts\Notifications\Dispatcher::class);
});

test('order chat requires a signed-in customer and their own order in this storefront', function () {
    $controller = new OrderChatController();

    bindOrderChatRequest(null);
    $signedOut = $controller->show('order_active');
    bindOrderChatRequest();
    $missing    = $controller->show('order_missing');
    $otherStore = $controller->show('order_other_store');
    $notMine    = $controller->show('order_other_customer');
    bindOrderChatRequest('chat-secret', 'unknown_key');
    $noStorefront = $controller->show('order_active');

    expect($signedOut->getStatusCode())->toBe(401)
        ->and($missing->getStatusCode())->toBe(404)
        ->and($otherStore->getStatusCode())->toBe(404)
        ->and($notMine->getStatusCode())->toBe(403)
        ->and($noStorefront->getStatusCode())->toBe(404);
});

test('order chat waits for a driver and stays closed to new chats after the order finishes', function () {
    bindOrderChatRequest();
    $controller = new OrderChatController();

    $waiting  = $controller->show('order_waiting');
    $finished = $controller->show('order_done_never_chatted');

    expect($waiting->getStatusCode())->toBe(409)
        ->and($waiting->getData(true))->toBe([
            'error'  => 'You can message your driver once one is assigned to this order.',
            'reason' => 'driver_not_assigned',
        ])
        ->and($finished->getStatusCode())->toBe(409)
        ->and(ChatChannel::query()->count())->toBe(0);
});

test('opening an order chat creates one channel with the customer and driver', function () {
    bindOrderChatRequest();
    $controller = new OrderChatController();

    $first   = orderChatData($controller->show('order_active'));
    $second  = orderChatData($controller->show('order_active'));
    $channel = ChatChannel::query()->first();

    expect(ChatChannel::query()->count())->toBe(1)
        ->and($channel->name)->toBe('Order order_active')
        ->and(data_get($channel->meta, 'storefront_order_uuid'))->toBe('order_active_uuid')
        ->and(data_get($channel->meta, 'storefront_order_id'))->toBe('order_active')
        ->and($first['id'])->toBe($channel->public_id)
        ->and($first['channel'])->toBe('chat.' . $channel->public_id)
        ->and($first['order'])->toBe('order_active')
        ->and($first['status'])->toBe('open')
        ->and($first['me'])->toBe(OrderChat::participantFor($channel, 'customer_user_uuid')->public_id)
        ->and(collect($first['participants'])->pluck('role', 'name')->all())->toBe(['Mei Tan' => 'customer', 'Ravi K.' => 'driver'])
        ->and($first['participants'][0]['is_online'])->toBeFalse()
        ->and($first['participants'][0]['avatar_url'])->toBeNull()
        ->and($first['messages'])->toBe([])
        ->and($first['unread_count'])->toBe(0)
        ->and($second['id'])->toBe($first['id']);
});

test('network apps open chats for orders placed through the network', function () {
    bindOrderChatRequest('chat-secret', 'network_key');

    $response = orderChatData((new OrderChatController())->show('order_network'));

    expect($response['order'])->toBe('order_network');
});

test('customers send text and photos, and only driver messages are pushed to the customer', function () {
    $storage = new OrderChatFakeDisk();
    Illuminate\Support\Facades\Storage::swap($storage);
    config(['filesystems.default' => 'public', 'filesystems.disks.public.bucket' => 'media-bucket']);
    $controller = new OrderChatController();
    bindOrderChatRequest();
    $controller->show('order_active');
    $channel = ChatChannel::query()->first();

    $request = bindOrderChatRequest('chat-secret', 'store_key', [
        'content' => 'Tower B, unit 08-112',
        'files'   => [['data' => base64_encode('photo-bytes'), 'type' => 'image/png']],
    ], 'POST', SendOrderChatMessageRequest::class);
    $sent = $controller->send($request, 'order_active')->getData(true)['message'];
    $file = orderChatDb()->table('files')->first();

    $driverMessage = driverSays($channel, '', now()->toDateTimeString());

    expect($sent['content'])->toBe('Tower B, unit 08-112')
        ->and($sent['is_mine'])->toBeTrue()
        ->and($sent['sender']['role'])->toBe('customer')
        ->and($sent['sender']['name'])->toBe('Mei Tan')
        ->and($sent['read'])->toBeFalse()
        ->and($sent['attachments'])->toHaveCount(1)
        ->and($sent['attachments'][0]['type'])->toBe('image/png')
        ->and($sent['attachments'][0]['url'])->toStartWith('https://cdn.test/hyperstore/store_bloom/order-chat/' . $channel->uuid . '/')
        ->and($file->disk)->toBe('public')
        ->and($file->bucket)->toBe('media-bucket')
        ->and($file->type)->toBe('storefront_chat_upload')
        ->and($file->file_size)->toBe(strlen('photo-bytes'))
        ->and($file->subject_type)->toBe(ChatMessage::class)
        ->and($storage->writes[0][1])->toBe('photo-bytes')
        // chat photos are written without a public ACL
        ->and($storage->writes[0][2])->toBe([]);

    // The customer's own message is not pushed back to them; the driver's is (here the
    // observer is called directly, since this harness has no model event dispatcher).
    (new ChatMessageObserver())->created($driverMessage);
    $pushed         = $this->notifications->sentTo(CHAT_CUSTOMER_UUID, StorefrontOrderChatMessage::class);
    $driverNotified = $this->notifications->sentTo('driver_user_uuid', Fleetbase\Notifications\ChatMessageReceived::class);

    expect($pushed)->toHaveCount(1)
        ->and($pushed[0]->message->uuid)->toBe($driverMessage->uuid)
        ->and($driverNotified)->toHaveCount(1);
});

test('messages page backwards with a stable cursor and read receipts clear the unread count', function () {
    $controller = new OrderChatController();
    bindOrderChatRequest();
    $controller->show('order_active');
    $channel = ChatChannel::query()->first();
    $first   = driverSays($channel, 'Picked up your flowers', '2026-10-06 13:51:00');
    $second  = driverSays($channel, 'At the lobby', '2026-10-06 14:00:00');
    $third   = driverSays($channel, 'Which tower?', '2026-10-06 14:00:00');

    $opened  = orderChatData($controller->show('order_active'));
    $page    = $controller->messages(bindOrderChatRequest('chat-secret', 'store_key', ['before' => $third->public_id, 'limit' => 1]), 'order_active')->getData(true);
    $earlier = $controller->messages(bindOrderChatRequest('chat-secret', 'store_key', ['before' => $second->public_id]), 'order_active')->getData(true);
    $unknown = $controller->messages(bindOrderChatRequest('chat-secret', 'store_key', ['before' => 'chat_message_missing']), 'order_active');
    $all     = $controller->messages(bindOrderChatRequest('chat-secret', 'store_key', ['limit' => 500]), 'order_active')->getData(true);
    bindOrderChatRequest();
    $read    = $controller->read('order_active')->getData(true);
    $after   = orderChatData($controller->show('order_active'));
    $again   = $controller->read('order_active')->getData(true);

    expect(collect($opened['messages'])->pluck('content')->all())->toBe(['Picked up your flowers', 'At the lobby', 'Which tower?'])
        ->and($opened['messages'][0]['is_mine'])->toBeFalse()
        ->and($opened['messages'][0]['sender']['role'])->toBe('driver')
        ->and($opened['unread_count'])->toBe(3)
        ->and(collect($page['messages'])->pluck('id')->all())->toBe([$second->public_id])
        ->and(collect($earlier['messages'])->pluck('id')->all())->toBe([$first->public_id])
        ->and($unknown->getStatusCode())->toBe(404)
        ->and($all['messages'])->toHaveCount(3)
        ->and($read)->toBe(['read' => 3])
        ->and($after['unread_count'])->toBe(0)
        ->and($after['messages'][2]['read'])->toBeTrue()
        ->and($again)->toBe(['read' => 0]);
});

test('reassigning the order moves the chat to the new driver', function () {
    $controller = new OrderChatController();
    bindOrderChatRequest();
    $controller->show('order_active');
    orderChatDb()->table('orders')->where('public_id', 'order_active')->update(['driver_assigned_uuid' => 'second_driver_uuid']);

    $response = orderChatData($controller->show('order_active'));

    expect(collect($response['participants'])->pluck('name')->sort()->values()->all())->toBe(['Arun S.', 'Mei Tan'])
        ->and(ChatChannel::query()->count())->toBe(1);
});

test('finished orders keep their chat readable but refuse new messages', function () {
    $controller = new OrderChatController();
    bindOrderChatRequest();
    $controller->show('order_active');
    orderChatDb()->table('orders')->where('public_id', 'order_active')->update(['status' => 'completed']);

    $shown   = orderChatData($controller->show('order_active'));
    $request = bindOrderChatRequest('chat-secret', 'store_key', ['content' => 'Thanks!'], 'POST', SendOrderChatMessageRequest::class);
    $sent    = $controller->send($request, 'order_active');

    expect($shown['status'])->toBe('closed')
        ->and($sent->getStatusCode())->toBe(423)
        ->and($sent->getData(true))->toBe(['error' => 'This chat closed when the order finished.', 'reason' => 'chat_closed'])
        ->and(ChatMessage::query()->count())->toBe(0);
});

test('customers removed from a finished order chat cannot mark messages read until it is reopened', function () {
    $controller = new OrderChatController();
    bindOrderChatRequest();
    $controller->show('order_active');
    $channel = ChatChannel::query()->first();
    // A channel whose customer participant is gone (for example removed by an operator) and
    // that is not re-synced because the order has finished.
    OrderChat::participantFor($channel, 'customer_user_uuid')->delete();
    orderChatDb()->table('orders')->where('public_id', 'order_active')->update(['status' => 'canceled']);
    $read = $controller->read('order_active');
    orderChatDb()->table('orders')->where('public_id', 'order_active')->update(['status' => 'dispatched']);
    $channel->refresh();

    expect($read->getStatusCode())->toBe(403)
        ->and(OrderChat::participantFor($channel, null))->toBeNull();

    // Re-opening the chat for an active order restores the customer.
    bindOrderChatRequest();
    $controller->show('order_active');
    expect(OrderChat::participantFor($channel, 'customer_user_uuid'))->not->toBeNull();
});

test('a customer missing from an open chat cannot send messages', function () {
    $controller = new class extends OrderChatController {
        protected function resolveContext(string $id): array|Illuminate\Http\JsonResponse
        {
            $context = parent::resolveContext($id);
            OrderChat::participantFor($context[2], 'customer_user_uuid')?->delete();

            return $context;
        }
    };
    $request = bindOrderChatRequest('chat-secret', 'store_key', ['content' => 'Hi'], 'POST', SendOrderChatMessageRequest::class);

    $sent  = $controller->send($request, 'order_active');
    $shown = orderChatData($controller->show('order_active'));

    expect($sent->getStatusCode())->toBe(403)
        ->and($shown['unread_count'])->toBe(0)
        ->and($shown['me'])->toBeNull();
});

test('every chat action reports a missing context the same way', function () {
    $controller = new OrderChatController();
    bindOrderChatRequest(null);

    expect($controller->messages(request(), 'order_active')->getStatusCode())->toBe(401)
        ->and($controller->read('order_active')->getStatusCode())->toBe(401)
        ->and($controller->send(SendOrderChatMessageRequest::create('/', 'POST', ['content' => 'x']), 'order_active')->getStatusCode())->toBe(401);
});

test('the chat observer ignores non-order chats, missing orders and customers, and customer messages', function () {
    $observer = new ChatMessageObserver();
    $plain    = ChatChannel::create(['company_uuid' => 'company_uuid', 'created_by_uuid' => 'driver_user_uuid', 'name' => 'Dispatch']);
    $orphan   = ChatChannel::create(['company_uuid' => 'company_uuid', 'created_by_uuid' => 'driver_user_uuid', 'name' => 'Old order', 'meta' => ['storefront_order_uuid' => 'gone_uuid']]);
    $sender   = ChatParticipant::where('chat_channel_uuid', $plain->uuid)->first();

    $observer->created(new ChatMessage(['chat_channel_uuid' => 'missing_channel']));
    $observer->created(new ChatMessage(['chat_channel_uuid' => $plain->uuid, 'sender_uuid' => $sender->uuid]));
    $observer->created(new ChatMessage(['chat_channel_uuid' => $orphan->uuid, 'sender_uuid' => $sender->uuid]));

    $order               = Order::where('public_id', 'order_active')->first();
    $chat                = OrderChat::open($order);
    $customerParticipant = OrderChat::participantFor($chat, 'customer_user_uuid');
    $observer->created(new ChatMessage(['chat_channel_uuid' => $chat->uuid, 'sender_uuid' => $customerParticipant->uuid]));
    $observer->created(new ChatMessage(['chat_channel_uuid' => $chat->uuid, 'sender_uuid' => 'missing_participant']));
    orderChatDb()->table('orders')->where('public_id', 'order_active')->update(['customer_uuid' => 'nobody']);
    $observer->created(new ChatMessage(['chat_channel_uuid' => $chat->uuid, 'sender_uuid' => $customerParticipant->uuid]));

    expect($this->notifications->sent)->toBe([]);
});

test('order chat helpers find the order and refuse to open without accounts', function () {
    $order = Order::where('public_id', 'order_active')->first();
    $chat  = OrderChat::open($order);
    orderChatDb()->table('contacts')->where('uuid', CHAT_CUSTOMER_UUID)->update(['user_uuid' => null]);
    $guest = Order::where('public_id', 'order_active')->first();

    expect(OrderChat::orderFor($chat)->uuid)->toBe('order_active_uuid')
        ->and(OrderChat::orderFor(new ChatChannel()))->toBeNull()
        ->and(OrderChat::find($order)->uuid)->toBe($chat->uuid)
        ->and(OrderChat::open($guest))->toBeNull()
        ->and(OrderChat::isClosed($order))->toBeFalse();
});

test('chat notifications describe the driver message for push, inbox and sockets', function () {
    $order     = Order::where('public_id', 'order_network')->first();
    $chat      = OrderChat::open($order);
    $message   = driverSays($chat, 'At the lobby', now()->toDateTimeString());
    $photo     = driverSays($chat, '   ', now()->toDateTimeString());
    $anonymous = new ChatMessage(['content' => 'Hi']);

    $notification = new StorefrontOrderChatMessage($message->load(['sender.user', 'chatChannel']), $order);
    $array        = $notification->toArray(null);
    $push         = $notification->toPush(null);

    expect($notification->via(null))->toBe([Fleetbase\Storefront\Push\StorefrontPushChannel::class, 'database', Fleetbase\Storefront\Notifications\Channels\SafeBroadcastChannel::class])
        ->and($array)->toMatchArray([
            'title'      => 'Ravi K. sent a message',
            'body'       => 'At the lobby',
            'type'       => 'order_chat_message',
            'order_id'   => 'order_network',
            'chat_id'    => $chat->public_id,
            'message_id' => $message->public_id,
            'store_id'   => 'store_bloom',
            'network_id' => 'network_market',
        ])
        ->and($push)->toBeInstanceOf(Fleetbase\Storefront\Push\PushMessage::class)
        ->and($notification->broadcastType())->toBe('order_chat_message')
        ->and($notification->toBroadcast(null))->toBeInstanceOf(Illuminate\Notifications\Messages\BroadcastMessage::class)
        ->and($notification->pushStorefronts())->toBeArray()
        ->and((new StorefrontOrderChatMessage($photo, $order))->body())->toBe('Sent a photo')
        ->and((new StorefrontOrderChatMessage($anonymous, $order))->title())->toBe('Your driver sent a message');
});

test('send message requests need text or photos', function () {
    session(['storefront_key' => 'store_key']);
    $rules = (new SendOrderChatMessageRequest())->rules();
    session(['storefront_key' => null]);

    expect($rules)->toBe([
        'content'      => 'required_without:files|nullable|string|max:2000',
        'files'        => 'required_without:content|array|max:4',
        'files.*.data' => 'required_with:files|string',
        'files.*.type' => 'required_with:files|string|starts_with:image/',
    ])
        ->and((new SendOrderChatMessageRequest())->authorize())->toBeFalse();
});

test('assigning a driver to a storefront order starts its chat', function () {
    $event                      = (new ReflectionClass(Fleetbase\FleetOps\Events\OrderDriverAssigned::class))->newInstanceWithoutConstructor();
    $event->modelUuid           = 'order_active_uuid';
    $event->modelClassNamespace = Order::class;

    (new Fleetbase\Storefront\Listeners\HandleOrderDriverAssigned())->handle($event);

    expect(ChatChannel::query()->count())->toBe(1)
        ->and($this->notifications->sentTo(CHAT_CUSTOMER_UUID, Fleetbase\Storefront\Notifications\StorefrontOrderDriverAssigned::class))->toHaveCount(1);
});

test('a failure to start the chat does not stop the driver assignment notification', function () {
    orderChatDb()->getSchemaBuilder()->drop('chat_channels');
    $reported = [];
    app()->instance(Illuminate\Contracts\Debug\ExceptionHandler::class, new class($reported) implements Illuminate\Contracts\Debug\ExceptionHandler {
        public function __construct(public array &$reported)
        {
        }

        public function report(Throwable $e)
        {
            $this->reported[] = $e;
        }

        public function shouldReport(Throwable $e)
        {
            return true;
        }

        public function render($request, Throwable $e)
        {
            throw $e;
        }

        public function renderForConsole($output, Throwable $e)
        {
        }
    });
    $event                      = (new ReflectionClass(Fleetbase\FleetOps\Events\OrderDriverAssigned::class))->newInstanceWithoutConstructor();
    $event->modelUuid           = 'order_active_uuid';
    $event->modelClassNamespace = Order::class;

    (new Fleetbase\Storefront\Listeners\HandleOrderDriverAssigned())->handle($event);
    app()->offsetUnset(Illuminate\Contracts\Debug\ExceptionHandler::class);

    expect($reported)->toHaveCount(1)
        ->and($this->notifications->sentTo(CHAT_CUSTOMER_UUID, Fleetbase\Storefront\Notifications\StorefrontOrderDriverAssigned::class))->toHaveCount(1);
});
