<?php

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\Storefront\Http\Controllers\v1\NotificationController;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Notifications\Channels\SafeBroadcastChannel;
use Fleetbase\Storefront\Notifications\PromotionalPushNotification;
use Fleetbase\Storefront\Notifications\StorefrontOrderCompleted;
use Fleetbase\Storefront\Push\StorefrontPushChannel;
use Fleetbase\Storefront\Support\CustomerNotificationPresenter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store as SessionStore;

const INBOX_CUSTOMER = '11111111-1111-4111-8111-111111111111';
const INBOX_OTHER    = '22222222-2222-4222-8222-222222222222';

function inboxConnection()
{
    return Model::getConnectionResolver()->connection('mysql');
}

function inboxRequest(?string $secret = 'inbox-secret', array $input = [], string $method = 'GET'): Request
{
    $request = Request::create('/notifications', $method, $input);
    $request->setLaravelSession(new SessionStore('inbox-test', new ArraySessionHandler(120)));
    if ($secret) {
        $request->headers->set('Customer-Token', $secret);
    }
    app()->instance('request', $request);

    return $request;
}

function inboxNotification(string $id, string $notifiable, array $data, array $attributes = []): array
{
    return array_merge([
        'id'              => $id,
        'type'            => StorefrontOrderCompleted::class,
        'notifiable_type' => Contact::class,
        'notifiable_id'   => $notifiable,
        'data'            => json_encode($data),
        'read_at'         => null,
        'created_at'      => '2026-09-01 10:00:00',
        'updated_at'      => '2026-09-01 10:00:00',
    ], $attributes);
}

function inboxIds($collection): array
{
    return collect($collection->resolve())->pluck('id')->all();
}

beforeEach(function () {
    $schema = inboxConnection()->getSchemaBuilder();
    foreach (['contacts', 'personal_access_tokens', 'notifications', 'stores', 'networks', 'network_stores'] as $table) {
        $schema->dropIfExists($table);
    }

    $schema->create('contacts', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('type')->nullable();
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
    $schema->create('notifications', function ($table) {
        $table->string('id')->primary();
        $table->string('type');
        $table->string('notifiable_type');
        $table->string('notifiable_id');
        $table->text('data');
        $table->timestamp('read_at')->nullable();
        $table->timestamps();
    });
    foreach (['stores', 'networks'] as $name) {
        $schema->create($name, function ($table) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('public_id')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
        });
    }
    $schema->create('network_stores', function ($table) {
        $table->increments('id');
        $table->string('network_uuid')->nullable();
        $table->string('store_uuid')->nullable();
        $table->string('status')->default('active');
        $table->string('category_uuid')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });

    $db = inboxConnection();
    $db->table('contacts')->insert([
        ['uuid' => INBOX_CUSTOMER, 'public_id' => 'contact_inbox', 'user_uuid' => 'user_inbox', 'type' => 'customer', 'meta' => '{}'],
        ['uuid' => INBOX_OTHER, 'public_id' => 'contact_other', 'user_uuid' => 'user_other', 'type' => 'customer', 'meta' => '{}'],
    ]);
    $db->table('personal_access_tokens')->insert([
        ['name' => INBOX_CUSTOMER, 'token' => hash('sha256', 'inbox-secret'), 'abilities' => '["*"]'],
        ['name' => INBOX_OTHER, 'token' => hash('sha256', 'other-secret'), 'abilities' => '["*"]'],
    ]);
    $db->table('stores')->insert([
        ['uuid' => 'store_a_uuid', 'public_id' => 'store_a'],
        ['uuid' => 'store_b_uuid', 'public_id' => 'store_b'],
        ['uuid' => 'store_c_uuid', 'public_id' => 'store_c'],
    ]);
    $db->table('networks')->insert(['uuid' => 'network_uuid', 'public_id' => 'network_m']);
    $db->table('network_stores')->insert([
        ['network_uuid' => 'network_uuid', 'store_uuid' => 'store_a_uuid'],
        ['network_uuid' => 'network_uuid', 'store_uuid' => 'store_b_uuid'],
    ]);

    $db->table('notifications')->insert([
        inboxNotification('n_store_a', INBOX_CUSTOMER, [
            'type' => 'order_completed', 'title' => 'Delivered', 'body' => 'Enjoy!', 'order_id' => 'order_a', 'store_id' => 'store_a', 'email' => 'me@example.test',
        ], ['created_at' => '2026-09-05 10:00:00']),
        inboxNotification('n_network', INBOX_CUSTOMER, [
            'type' => 'order_enroute', 'title' => 'On the way', 'body' => 'Picked up', 'store_id' => 'store_b', 'network_id' => 'network_m',
        ], ['created_at' => '2026-09-04 10:00:00']),
        inboxNotification('n_store_c', INBOX_CUSTOMER, [
            'type' => 'promotional', 'title' => 'Sale', 'body' => 'Store C sale', 'store_id' => 'store_c',
        ], ['type' => PromotionalPushNotification::class, 'created_at' => '2026-09-03 10:00:00']),
        inboxNotification('n_generic', INBOX_CUSTOMER, [
            'title' => 'Welcome', 'body' => 'Hello there',
        ], ['created_at' => '2026-09-02 10:00:00', 'read_at' => '2026-09-02 11:00:00']),
        inboxNotification('n_legacy', INBOX_CUSTOMER, [
            'subject' => 'Your order from Store A has been accepted.', 'message' => 'order_accepted', 'storefront_id' => 'store_a', 'id' => 'contact_inbox',
        ], ['type' => 'Fleetbase\\Storefront\\Notifications\\StorefrontOrderAccepted', 'created_at' => '2026-09-01 10:00:00']),
        inboxNotification('n_other_customer', INBOX_OTHER, [
            'type' => 'order_completed', 'title' => 'Not yours', 'body' => 'Private', 'store_id' => 'store_a',
        ]),
    ]);

    session(['storefront_store' => 'store_a_uuid', 'storefront_network' => null]);
});

test('inbox endpoints require an authenticated customer', function () {
    $controller = new NotificationController();
    inboxRequest(null);

    foreach ([
        $controller->query(request()),
        $controller->unreadCount(),
        $controller->find('n_store_a'),
        $controller->markAsRead('n_store_a'),
        $controller->markAllAsRead(),
        $controller->delete('n_store_a'),
        $controller->getPreferences(),
        $controller->updatePreferences(request()),
    ] as $response) {
        expect($response->getStatusCode())->toBe(401);
    }
});

test('a store app lists only the customer notifications for that store, newest first', function () {
    $items = (new NotificationController())->query(inboxRequest())->resolve();

    expect(collect($items)->pluck('id')->all())->toBe(['n_store_a', 'n_generic', 'n_legacy'])
        ->and($items[0])->toMatchArray([
            'type'    => 'order_completed',
            'title'   => 'Delivered',
            'body'    => 'Enjoy!',
            'is_read' => false,
            'data'    => ['order_id' => 'order_a', 'store_id' => 'store_a'],
        ])
        ->and($items[1]['is_read'])->toBeTrue()
        ->and($items[2])->toMatchArray([
            'type'  => 'order_accepted',
            'title' => 'Your order from Store A has been accepted.',
            'body'  => 'Your order from Store A has been accepted.',
            'data'  => ['storefront_id' => 'store_a'],
        ]);
});

test('a network app lists notifications for the network and its member stores', function () {
    session(['storefront_store' => null, 'storefront_network' => 'network_uuid']);

    expect(inboxIds((new NotificationController())->query(inboxRequest())))->toBe(['n_store_a', 'n_network', 'n_generic', 'n_legacy']);
});

test('the inbox supports unread, type and pagination filters', function () {
    $controller = new NotificationController();

    expect(inboxIds($controller->query(inboxRequest('inbox-secret', ['unread' => '1']))))->toBe(['n_store_a', 'n_legacy'])
        ->and(inboxIds($controller->query(inboxRequest('inbox-secret', ['type' => 'order_completed']))))->toBe(['n_store_a'])
        ->and(inboxIds($controller->query(inboxRequest('inbox-secret', ['limit' => 1, 'offset' => 1]))))->toBe(['n_generic']);
});

test('customers can read, count, mark and delete only their own notifications', function () {
    $controller = new NotificationController();
    inboxRequest();

    expect($controller->unreadCount()->getData(true))->toBe(['count' => 2])
        ->and($controller->find('n_other_customer')->getStatusCode())->toBe(404)
        ->and($controller->find('n_store_c')->getStatusCode())->toBe(404)
        ->and($controller->markAsRead('n_other_customer')->getStatusCode())->toBe(404)
        ->and($controller->delete('n_other_customer')->getStatusCode())->toBe(404);

    $read = $controller->markAsRead('n_store_a')->resolve();

    expect($read['is_read'])->toBeTrue()
        ->and($controller->unreadCount()->getData(true))->toBe(['count' => 1])
        ->and($controller->markAllAsRead()->getData(true))->toBe(['status' => 'OK', 'updated' => 1])
        ->and($controller->unreadCount()->getData(true))->toBe(['count' => 0])
        ->and(inboxConnection()->table('notifications')->where('id', 'n_store_c')->value('read_at'))->toBeNull()
        ->and(inboxConnection()->table('notifications')->where('id', 'n_other_customer')->value('read_at'))->toBeNull()
        ->and($controller->delete('n_generic')->getData(true))->toBe(['status' => 'OK', 'id' => 'n_generic', 'deleted' => true])
        ->and(inboxConnection()->table('notifications')->where('id', 'n_generic')->exists())->toBeFalse();
});

test('customers can read and update their notification preferences', function () {
    $controller = new NotificationController();
    inboxRequest();

    expect($controller->getPreferences()->getData(true))->toBe(['order_updates' => true, 'promotions' => true]);

    $invalid = $controller->updatePreferences(inboxRequest('inbox-secret', ['promotions' => 'sometimes'], 'PUT'));
    $updated = $controller->updatePreferences(inboxRequest('inbox-secret', ['promotions' => false, 'ignored' => true], 'PUT'));

    expect($invalid->getStatusCode())->toBe(400)
        ->and($updated->getData(true))->toBe(['order_updates' => true, 'promotions' => false])
        ->and(json_decode(inboxConnection()->table('contacts')->where('uuid', INBOX_CUSTOMER)->value('meta'), true))
        ->toBe(['notification_preferences' => ['order_updates' => true, 'promotions' => false]]);
});

test('notification preferences control promotional delivery and order update pushes', function () {
    $store = tap(new Store())->forceFill(['uuid' => 'store_a_uuid', 'public_id' => 'store_a', 'name' => 'Store A']);
    $store->setRelation('networks', collect());
    $promotion = new PromotionalPushNotification('Sale', 'Save now', $store);

    $optedOut = new Contact();
    $optedOut->forceFill(['meta' => ['notification_preferences' => ['promotions' => false, 'order_updates' => false]]]);
    $defaults = new Contact();
    $defaults->forceFill(['meta' => []]);

    $order = new Fleetbase\FleetOps\Models\Order();
    $order->forceFill(['meta' => []]);
    $orderNotification = (new ReflectionClass(StorefrontOrderCompleted::class))->newInstanceWithoutConstructor();

    expect($promotion->via($optedOut))->toBe([])
        ->and($promotion->via($defaults))->toBe([StorefrontPushChannel::class, 'database', SafeBroadcastChannel::class])
        ->and($orderNotification->via($optedOut))->toBe(['database', 'mail', SafeBroadcastChannel::class])
        ->and($orderNotification->via($defaults))->toBe([StorefrontPushChannel::class, 'database', 'mail', SafeBroadcastChannel::class]);
});

test('realtime payloads mirror inbox items without recipient details', function () {
    $store     = tap(new Store())->forceFill(['uuid' => 'store_a_uuid', 'public_id' => 'store_a', 'name' => 'Store A']);
    $promotion = new PromotionalPushNotification('Sale', 'Save now', $store);

    expect($promotion->toBroadcast(null)->data)->toBe([
        'type'  => 'promotional',
        'title' => 'Sale',
        'body'  => 'Save now',
        'image' => null,
        'data'  => ['store_id' => 'store_a'],
    ])->and($promotion->broadcastType())->toBe('promotional')
        ->and(CustomerNotificationPresenter::present([], 'Fleetbase\\Storefront\\Notifications\\SomethingElse'))->toMatchArray(['type' => 'something_else', 'title' => null]);
});

test('broadcast failures never fail the notification send', function () {
    $events = new class implements Illuminate\Contracts\Events\Dispatcher {
        public function listen($events, $listener = null)
        {
        }

        public function hasListeners($eventName)
        {
            return false;
        }

        public function subscribe($subscriber)
        {
        }

        public function until($event, $payload = [])
        {
        }

        public function dispatch($event, $payload = [], $halt = false)
        {
            throw new RuntimeException('socket unavailable');
        }

        public function push($event, $payload = [])
        {
        }

        public function flush($event)
        {
        }

        public function forget($event)
        {
        }

        public function forgetPushed()
        {
        }
    };
    $store      = tap(new Store())->forceFill(['uuid' => 'store_a_uuid', 'public_id' => 'store_a', 'name' => 'Store A']);
    $notifiable = new Contact();
    $notifiable->forceFill(['uuid' => INBOX_CUSTOMER]);

    expect((new SafeBroadcastChannel($events))->send($notifiable, new PromotionalPushNotification('Sale', 'Save now', $store)))->toBeNull();
});

test('a single notification can be fetched by id', function () {
    inboxRequest();

    expect((new NotificationController())->find('n_store_a')->resolve())->toMatchArray([
        'id'    => 'n_store_a',
        'type'  => 'order_completed',
        'title' => 'Delivered',
    ]);
});

test('an unknown network app shows nothing but storefront-less notifications, and no storefront context shows everything', function () {
    session(['storefront_store' => null, 'storefront_network' => 'missing_network']);
    $controller = new NotificationController();

    expect(inboxIds($controller->query(inboxRequest())))->toBe(['n_store_a', 'n_network', 'n_store_c', 'n_generic', 'n_legacy']);

    session(['storefront_store' => null, 'storefront_network' => null]);

    expect(inboxIds($controller->query(inboxRequest())))->toBe(['n_store_a', 'n_network', 'n_store_c', 'n_generic', 'n_legacy']);
});

test('order notifications broadcast their inbox item', function () {
    $order = new Fleetbase\FleetOps\Models\Order();
    $order->forceFill(['uuid' => 'order_uuid', 'public_id' => 'order_public', 'meta' => ['storefront_id' => 'store_a']]);
    $order->setRelation('customer', null);
    $order->setRelation('company', null);
    $notification                 = (new ReflectionClass(StorefrontOrderCompleted::class))->newInstanceWithoutConstructor();
    $notification->order          = $order;
    $notification->storefront     = tap(new Store())->forceFill(['public_id' => 'store_a', 'name' => 'Store A']);
    $notification->sentAt         = '2026-09-01 10:00:00';
    $notification->notificationId = 'notification_test';
    $notification->subject        = 'Delivered';
    $notification->body           = 'Enjoy!';
    $notification->status         = 'order_completed';

    expect($notification->toBroadcast(null)->data)->toBe([
        'type'  => 'order_completed',
        'title' => 'Delivered',
        'body'  => 'Enjoy!',
        'image' => null,
        'data'  => ['order_id' => 'order_public', 'storefront_id' => 'store_a', 'store_id' => 'store_a'],
    ])->and($notification->broadcastType())->toBe('order_completed');
});

test('successful broadcasts are delegated to the framework broadcast channel', function () {
    $dispatched = [];
    $events     = new class($dispatched) extends Illuminate\Events\Dispatcher {
        public function __construct(public array &$dispatched)
        {
        }

        public function dispatch($event, $payload = [], $halt = false)
        {
            $this->dispatched[] = $event;

            return [];
        }
    };
    $store      = tap(new Store())->forceFill(['uuid' => 'store_a_uuid', 'public_id' => 'store_a', 'name' => 'Store A']);
    $notifiable = new Contact();
    $notifiable->forceFill(['uuid' => INBOX_CUSTOMER]);

    (new SafeBroadcastChannel($events))->send($notifiable, new PromotionalPushNotification('Sale', 'Save now', $store));

    expect($dispatched)->toHaveCount(1)
        ->and($dispatched[0])->toBeInstanceOf(Illuminate\Notifications\Events\BroadcastNotificationCreated::class);
});

test('broadcast failures are swallowed even when logging fails', function () {
    $events = new class extends Illuminate\Events\Dispatcher {
        public function dispatch($event, $payload = [], $halt = false)
        {
            throw new RuntimeException('socket unavailable');
        }
    };
    $app      = Illuminate\Container\Container::getInstance();
    $original = $app->make('log');
    $app->instance('log', new class {
        public function __call($method, $arguments)
        {
            throw new RuntimeException('logger unavailable');
        }
    });
    Illuminate\Support\Facades\Log::clearResolvedInstance('log');
    $store = tap(new Store())->forceFill(['uuid' => 'store_a_uuid', 'public_id' => 'store_a', 'name' => 'Store A']);

    try {
        $result = (new SafeBroadcastChannel($events))->send(new Contact(), new PromotionalPushNotification('Sale', 'Save now', $store));
    } finally {
        $app->instance('log', $original);
        Illuminate\Support\Facades\Log::clearResolvedInstance('log');
    }

    expect($result)->toBeNull();
});
