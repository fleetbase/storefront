<?php

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\Models\ChatChannel;
use Fleetbase\Models\ChatMessage;
use Fleetbase\Models\ChatParticipant;
use Fleetbase\Storefront\Support\OrderChat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/*
 * Shared fixtures for the order operations tests (order chat, insights, timeline): an
 * in-memory copy of the tables they read, a company with a store, a customer, two drivers
 * and two staff members, and a recorder for notifications.
 */
if (!function_exists('orderOpsCoverageDb')) {
    /** The customer's contact uuid (a real uuid: customer tokens are named after it). */
    function orderOpsCoverageCustomerUuid(): string
    {
        return '44444444-4444-4444-8444-444444444444';
    }

    function orderOpsCoverageDb(): Illuminate\Database\Connection
    {
        return Model::getConnectionResolver()->connection('mysql');
    }

    /**
     * Create the tables, with or without the `meta` column core-api 1.6.69 adds to chat
     * participants and messages, and the fixture rows.
     */
    function orderOpsCoverageSchema(bool $chatMeta = true): void
    {
        $schema = orderOpsCoverageDb()->getSchemaBuilder();
        foreach (['stores', 'networks', 'contacts', 'personal_access_tokens', 'users', 'drivers', 'orders', 'places', 'tracking_statuses', 'chat_channels', 'chat_participants', 'chat_messages', 'chat_attachments', 'chat_receipts', 'chat_logs', 'files'] as $table) {
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
                $table->string('currency')->nullable();
                $table->string('timezone')->nullable();
                $table->text('options')->nullable();
                $table->text('translations')->nullable();
                $table->text('tags')->nullable();
                $table->timestamps();
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
            $table->string('internal_id')->nullable();
            $table->string('company_uuid')->nullable();
            $table->string('customer_uuid')->nullable();
            $table->string('customer_type')->nullable();
            $table->string('driver_assigned_uuid')->nullable();
            $table->string('payload_uuid')->nullable();
            $table->string('transaction_uuid')->nullable();
            $table->string('tracking_number_uuid')->nullable();
            $table->string('status')->nullable();
            $table->integer('time')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->text('meta')->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('places', function ($table) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('public_id')->nullable();
            $table->string('company_uuid')->nullable();
            $table->string('owner_uuid')->nullable();
            $table->string('owner_type')->nullable();
            $table->string('name')->nullable();
            $table->string('street1')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('tracking_statuses', function ($table) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('tracking_number_uuid')->nullable();
            $table->string('code')->nullable();
            $table->string('status')->nullable();
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
        $schema->create('chat_participants', function ($table) use ($chatMeta) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('public_id')->nullable();
            $table->string('company_uuid')->nullable();
            $table->string('chat_channel_uuid')->nullable();
            $table->string('user_uuid')->nullable();
            if ($chatMeta) {
                $table->text('meta')->nullable();
            }
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('chat_messages', function ($table) use ($chatMeta) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('public_id')->nullable();
            $table->string('company_uuid')->nullable();
            $table->string('chat_channel_uuid')->nullable();
            $table->string('sender_uuid')->nullable();
            $table->text('content')->nullable();
            if ($chatMeta) {
                $table->text('meta')->nullable();
            }
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

        $db = orderOpsCoverageDb();
        $db->table('stores')->insert(['uuid' => 'store_uuid', 'public_id' => 'store_bloom', 'company_uuid' => 'company_uuid', 'key' => 'store_key', 'name' => 'Bloom', 'options' => '{}']);
        $db->table('networks')->insert(['uuid' => 'network_uuid', 'public_id' => 'network_market', 'company_uuid' => 'company_uuid', 'key' => 'network_key', 'name' => 'Market', 'options' => '{}']);
        $db->table('users')->insert([
            ['uuid' => 'customer_user_uuid', 'public_id' => 'user_customer', 'company_uuid' => 'company_uuid', 'name' => 'Mei Tan', 'type' => 'customer'],
            ['uuid' => 'driver_user_uuid', 'public_id' => 'user_driver', 'company_uuid' => 'company_uuid', 'name' => 'Ravi K.', 'type' => 'driver'],
            ['uuid' => 'second_driver_user_uuid', 'public_id' => 'user_driver_2', 'company_uuid' => 'company_uuid', 'name' => 'Arun S.', 'type' => 'driver'],
            ['uuid' => 'staff_user_uuid', 'public_id' => 'user_staff', 'company_uuid' => 'company_uuid', 'name' => 'Sam Store', 'type' => 'user'],
            ['uuid' => 'second_staff_user_uuid', 'public_id' => 'user_staff_2', 'company_uuid' => 'company_uuid', 'name' => 'Kim Store', 'type' => 'user'],
            ['uuid' => 'outsider_user_uuid', 'public_id' => 'user_outsider', 'company_uuid' => 'other_company_uuid', 'name' => 'Out Sider', 'type' => 'user'],
        ]);
        $db->table('contacts')->insert([
            ['uuid' => orderOpsCoverageCustomerUuid(), 'public_id' => 'contact_mei', 'company_uuid' => 'company_uuid', 'user_uuid' => 'customer_user_uuid', 'type' => 'customer', 'name' => 'Mei Tan', 'created_at' => '2025-01-15 09:00:00'],
            ['uuid' => 'guest_contact_uuid', 'public_id' => 'contact_guest', 'company_uuid' => 'company_uuid', 'user_uuid' => null, 'type' => 'customer', 'name' => 'Guest', 'created_at' => '2025-02-01 09:00:00'],
        ]);
        $db->table('drivers')->insert([
            ['uuid' => 'driver_uuid', 'public_id' => 'driver_ravi', 'company_uuid' => 'company_uuid', 'user_uuid' => 'driver_user_uuid'],
            ['uuid' => 'second_driver_uuid', 'public_id' => 'driver_arun', 'company_uuid' => 'company_uuid', 'user_uuid' => 'second_driver_user_uuid'],
        ]);
        $db->table('personal_access_tokens')->insert([
            'name'       => orderOpsCoverageCustomerUuid(),
            'token'      => hash('sha256', 'ops-secret'),
            'abilities'  => '["*"]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Insert an order of the company, by default the customer's dispatched storefront order,
     * and load it (null once deleted).
     */
    function orderOpsCoverageOrder(string $publicId, array $overrides = [], array $meta = ['storefront_id' => 'store_bloom']): ?Fleetbase\FleetOps\Models\Order
    {
        orderOpsCoverageDb()->table('orders')->insert(array_merge([
            'uuid'                 => $publicId . '_uuid',
            'public_id'            => $publicId,
            'company_uuid'         => 'company_uuid',
            'customer_uuid'        => orderOpsCoverageCustomerUuid(),
            'customer_type'        => Contact::class,
            'driver_assigned_uuid' => 'driver_uuid',
            'status'               => 'dispatched',
            'meta'                 => json_encode($meta),
        ], $overrides));

        return Fleetbase\FleetOps\Models\Order::where('public_id', $publicId)->with(['customer', 'driverAssigned'])->first();
    }

    /**
     * Model events (uuids, public ids, ChatChannel's creator hook), a recorded notification
     * dispatcher, and no cached knowledge of the chat tables' columns.
     */
    function orderOpsCoverageBoot(): object
    {
        Model::setEventDispatcher(new Illuminate\Events\Dispatcher(app()));
        Model::clearBootedModels();
        orderOpsCoverageForgetMetaColumns();
        app()->instance('responsecache', new class {
            public function clear(): void
            {
            }
        });
        Spatie\ResponseCache\Facades\ResponseCache::clearResolvedInstance('responsecache');
        app()->instance(Spatie\Activitylog\ActivityLogStatus::class, new Spatie\Activitylog\ActivityLogStatus(new Illuminate\Config\Repository(['activitylog' => ['enabled' => false]])));
        if (!Illuminate\Support\Str::hasMacro('humanize')) {
            Illuminate\Support\Str::macro('humanize', (new Fleetbase\Expansions\Str())->humanize());
        }
        session(['company' => 'company_uuid', 'user' => null]);

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

            public function sentTo(string $uuid, ?string $class = null): array
            {
                return array_values(array_map(fn ($pair) => $pair[1], array_filter($this->sent, fn ($pair) => data_get($pair[0], 'uuid') === $uuid && ($class === null || $pair[1] instanceof $class))));
            }
        };
        app()->instance(Illuminate\Contracts\Notifications\Dispatcher::class, $dispatcher);
        Illuminate\Support\Facades\Notification::clearResolvedInstance(Illuminate\Contracts\Notifications\Dispatcher::class);

        return $dispatcher;
    }

    function orderOpsCoverageShutdown(): void
    {
        Model::unsetEventDispatcher();
        Model::clearBootedModels();
        orderOpsCoverageForgetMetaColumns();
        app()->offsetUnset(Illuminate\Contracts\Notifications\Dispatcher::class);
        Illuminate\Support\Facades\Notification::clearResolvedInstance(Illuminate\Contracts\Notifications\Dispatcher::class);
        session(['user' => null]);
        Illuminate\Support\Carbon::setTestNow();
    }

    /** OrderChat remembers whether the chat tables have `meta`; each test builds its own tables. */
    function orderOpsCoverageForgetMetaColumns(): void
    {
        (new ReflectionProperty(OrderChat::class, 'metaColumns'))->setValue(null, []);
    }

    /**
     * A message written by a participant of the channel at the given time.
     */
    function orderOpsCoverageSay(ChatChannel $channel, string $userUuid, string $content, string $at): ChatMessage
    {
        $sender  = ChatParticipant::where('chat_channel_uuid', $channel->uuid)->where('user_uuid', $userUuid)->first();
        $message = ChatMessage::create(['company_uuid' => $channel->company_uuid, 'chat_channel_uuid' => $channel->uuid, 'sender_uuid' => $sender->uuid, 'content' => $content]);
        orderOpsCoverageDb()->table('chat_messages')->where('uuid', $message->uuid)->update(['created_at' => $at, 'updated_at' => $at]);

        return $message->fresh();
    }

    /**
     * A request the controller can validate, as the framework's request macro would.
     */
    function orderOpsCoverageRequest(array $input = [], string $method = 'POST', ?string $customerToken = null): Request
    {
        $request = OrderOpsCoverageValidatingRequest::create('/orders', $method, $input);
        if ($customerToken) {
            $request->headers->set('Customer-Token', $customerToken);
        }
        $request->setLaravelSession(new Illuminate\Session\Store('order-ops', new Illuminate\Session\ArraySessionHandler(120)));
        app()->instance('request', $request);

        return $request;
    }

    class OrderOpsCoverageValidatingRequest extends Request
    {
        public function validate(array $rules, ...$params): array
        {
            return app('validator')->make($this->all(), $rules)->validate();
        }
    }
}
