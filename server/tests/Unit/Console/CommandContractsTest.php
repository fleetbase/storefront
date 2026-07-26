<?php

use Fleetbase\Storefront\Console\Commands\MigrateStripeSandboxCustomers;
use Fleetbase\Storefront\Console\Commands\NotifyStorefrontOrderNearby;
use Fleetbase\Storefront\Console\Commands\PurgeExpiredCarts;
use Fleetbase\Storefront\Console\Commands\SendOrderNotification;
use Fleetbase\Storefront\Models\Store;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class CommandNotificationStub extends Illuminate\Notifications\Notification
{
    public static array $arguments = [];

    public function __construct(...$arguments)
    {
        static::$arguments = $arguments;
    }

    public function via($notifiable): array
    {
        return [];
    }
}

class FailingCommandNotificationStub extends Illuminate\Notifications\Notification
{
    public function __construct(...$arguments)
    {
        throw new RuntimeException('Notification construction failed');
    }
}

class CommandNotificationCustomerStub
{
    public array $notifications = [];

    public function notify($notification): void
    {
        $this->notifications[] = $notification;
    }
}

class TestableSendOrderNotification extends SendOrderNotification
{
    public Fleetbase\FleetOps\Models\Order $resolvedOrder;
    public string $askedOrderId  = 'order_public';
    public string $selectedEvent = 'created';

    public function __construct()
    {
        parent::__construct();
        $this->eventToNotification['created'] = CommandNotificationStub::class;
        $this->eventToNotification['nearby']  = CommandNotificationStub::class;
        $this->eventToNotification['failing'] = FailingCommandNotificationStub::class;
    }

    protected function findOrder(?string $orderId): ?Fleetbase\FleetOps\Models\Order
    {
        return $this->resolvedOrder;
    }

    protected function getDistanceMatrix($origin, $destination): object
    {
        return (object) ['distance' => 1250, 'time' => 300];
    }

    public function ask($question, $default = null)
    {
        return $this->askedOrderId;
    }

    public function choice($question, array $choices, $default = null, $attempts = null, $multipleSelections = false)
    {
        return $this->selectedEvent;
    }
}

class TestableMigrateStripeSandboxCustomers extends MigrateStripeSandboxCustomers
{
    public array $options = [];

    public function option($key = null)
    {
        return $key === null ? $this->options : ($this->options[$key] ?? null);
    }
}

class NearbyCommandOrderStub extends Fleetbase\FleetOps\Models\Order
{
    public bool $nearbyMarked = false;

    public function missingMeta($key): bool
    {
        return !$this->nearbyMarked;
    }

    public function updateMeta($key, $value = null): Fleetbase\FleetOps\Models\Order
    {
        if ($key === 'storefront_order_nearby') {
            $this->nearbyMarked = (bool) $value;
        }

        return $this;
    }
}

test('purge carts deletes only expired records and reports the affected count', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('carts');
    $schema->create('carts', function ($table) {
        $table->increments('id');
        $table->timestamp('expires_at');
    });

    $connection->table('carts')->insert([
        ['expires_at' => now()->subMinute()],
        ['expires_at' => now()->subDay()],
        ['expires_at' => now()->addHour()],
    ]);

    $buffer  = new BufferedOutput();
    $output  = new OutputStyle(new ArrayInput([]), $buffer);
    $command = new PurgeExpiredCarts();
    $command->setOutput($output);

    expect($command->handle())->toBe(Command::SUCCESS)
        ->and($connection->table('carts')->count())->toBe(1)
        ->and($buffer->fetch())->toContain('Successfully deleted 2 expired carts.');
});

test('purge carts restores foreign key enforcement when deletion fails', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->getSchemaBuilder()->dropIfExists('carts');

    $command = new PurgeExpiredCarts();

    expect(fn () => $command->handle())->toThrow(Illuminate\Database\QueryException::class)
        ->and((int) $connection->selectOne('PRAGMA foreign_keys')->foreign_keys)->toBe(1);
});

test('nearby order command reports an empty candidate set', function () {
    $buffer  = new BufferedOutput();
    $command = new class extends NotifyStorefrontOrderNearby {
        public function getActiveStorefrontOrders(): Illuminate\Database\Eloquent\Collection
        {
            return new Illuminate\Database\Eloquent\Collection();
        }
    };
    $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

    expect($command->handle())->toBeNull()
        ->and($buffer->fetch())->toContain('Found (0) Storefront Orders which are Enroute.');
});

test('nearby order command skips candidates without a usable distance matrix', function () {
    config(['fleetops.distance_matrix.provider' => 'calculate']);
    $point = new Fleetbase\LaravelMysqlSpatial\Types\Point(47.918, 106.917);
    $order = (object) [
        'public_id' => 'order_public',
        'payload'   => new class($point) {
            public function __construct(private object $point)
            {
            }

            public function getPickupOrFirstWaypoint(): object
            {
                return $this->point;
            }

            public function getDropoffOrLastWaypoint(): object
            {
                return $this->point;
            }
        },
    ];
    $buffer  = new BufferedOutput();
    $command = new class($order) extends NotifyStorefrontOrderNearby {
        public function __construct(private object $candidate)
        {
            parent::__construct();
        }

        public function getActiveStorefrontOrders(): Illuminate\Database\Eloquent\Collection
        {
            return new Illuminate\Database\Eloquent\Collection([$this->candidate]);
        }
    };
    $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

    expect($command->handle())->toBeNull()
        ->and($buffer->fetch())->toContain('Found (1) Storefront Orders which are Enroute.')
        ->not->toContain('is nearby');
});

test('nearby order command notifies an eligible customer once and records the marker', function () {
    $schema = Illuminate\Database\Capsule\Manager::schema('mysql');
    $schema->dropIfExists('stores');
    $schema->create('stores', function (Illuminate\Database\Schema\Blueprint $table) {
        $table->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'backdrop_uuid', 'logo_uuid', 'order_config_uuid', 'name', 'description', 'translations', 'website', 'facebook', 'instagram', 'twitter', 'email', 'phone', 'tags', 'currency', 'timezone', 'pod_method', 'options'] as $column) {
            $table->text($column)->nullable();
        }
        $table->timestamp('deleted_at')->nullable();
    });
    Illuminate\Database\Capsule\Manager::connection('mysql')->table('stores')->insert([
        'uuid'      => 'store_uuid',
        'public_id' => 'store_public',
        'name'      => 'Central Store',
    ]);
    $point    = new Fleetbase\LaravelMysqlSpatial\Types\Point(47.918, 106.917);
    $payload  = new class($point) {
        public function __construct(private object $point)
        {
        }

        public function getPickupOrFirstWaypoint(): object
        {
            return $this->point;
        }

        public function getDropoffOrLastWaypoint(): object
        {
            return $this->point;
        }
    };
    $customer = new class {
        public array $notifications = [];

        public function notify($notification): void
        {
            $this->notifications[] = $notification;
        }
    };
    $order = new NearbyCommandOrderStub();
    $order->forceFill([
        'public_id' => 'order_public',
        'meta'      => ['storefront_id' => 'store_public'],
    ]);
    $order->setRelation('payload', $payload);
    $order->setRelation('customer', $customer);
    $buffer  = new BufferedOutput();
    $command = new class($order) extends NotifyStorefrontOrderNearby {
        public function __construct(private NearbyCommandOrderStub $candidate)
        {
            parent::__construct();
        }

        public function getActiveStorefrontOrders(): Illuminate\Database\Eloquent\Collection
        {
            return new Illuminate\Database\Eloquent\Collection([$this->candidate]);
        }

        protected function getDistanceMatrix($origin, $destination): object
        {
            return (object) ['distance' => 1200, 'time' => 300];
        }
    };
    $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

    expect($command->handle())->toBeNull()
        ->and($customer->notifications)->toHaveCount(1)
        ->and($customer->notifications[0])->toBeInstanceOf(
            Fleetbase\Storefront\Notifications\StorefrontOrderNearby::class
        )->and($order->nearbyMarked)->toBeTrue()
        ->and($buffer->fetch())->toContain('is nearby');

    config(['fleetops.distance_matrix.provider' => 'calculate']);
    $method = new ReflectionMethod(NotifyStorefrontOrderNearby::class, 'getDistanceMatrix');
    $matrix = $method->invoke(new NotifyStorefrontOrderNearby(), $point, $point);

    expect($matrix->distance)->toBe(0.0)
        ->and($matrix->time)->toBe(0.0);
});

test('manual notification command rejects an unknown order without dispatching', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('orders');
    $schema->create('orders', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id');
        $table->string('customer_uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });

    $buffer  = new BufferedOutput();
    $command = new SendOrderNotification();
    $command->setInput(new ArrayInput([
        '--id'    => 'order_missing',
        '--event' => 'created',
    ], $command->getDefinition()));
    $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

    expect($command->handle())->toBe(1)
        ->and($buffer->fetch())->toContain('Order not found!');
});

test('manual notification command rejects an order without a customer', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('orders');
    $schema->dropIfExists('contacts');
    $schema->create('orders', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id');
        $table->string('customer_uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('contacts', function ($table) {
        $table->string('uuid')->primary();
        $table->timestamp('deleted_at')->nullable();
    });
    $connection->table('orders')->insert([
        'uuid'          => 'order_uuid',
        'public_id'     => 'order_without_customer',
        'customer_uuid' => null,
    ]);

    $buffer  = new BufferedOutput();
    $command = new SendOrderNotification();
    $command->setInput(new ArrayInput([
        '--id'    => 'order_without_customer',
        '--event' => 'created',
    ], $command->getDefinition()));
    $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

    expect($command->handle())->toBe(1)
        ->and($buffer->fetch())->toContain('Order does not have an associated customer!');
});

test('manual notification command rejects unsupported event names', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('orders');
    $schema->dropIfExists('contacts');
    $schema->create('orders', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id');
        $table->string('customer_uuid');
        $table->string('customer_type');
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('contacts', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id');
        $table->string('name')->nullable();
        $table->string('type')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $connection->table('contacts')->insert([
        'uuid'      => 'contact_uuid',
        'public_id' => 'contact_public',
        'name'      => 'Ada Buyer',
        'type'      => 'customer',
    ]);
    $connection->table('orders')->insert([
        'uuid'          => 'order_uuid',
        'public_id'     => 'order_public',
        'customer_uuid' => 'contact_uuid',
        'customer_type' => Fleetbase\Models\Contact::class,
    ]);

    $buffer  = new BufferedOutput();
    $command = new SendOrderNotification();
    $command->setInput(new ArrayInput([
        '--id'    => 'order_public',
        '--event' => 'unsupported',
    ], $command->getDefinition()));
    $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

    expect($command->handle())->toBe(1)
        ->and($buffer->fetch())->toContain('Invalid event selected!');
});

test('manual notification command sends ordinary and nearby notifications with resolved context', function () {
    $customer = new CommandNotificationCustomerStub();
    $payload  = new class {
        public function getPickupOrFirstWaypoint(): object
        {
            return (object) ['public_id' => 'place_pickup'];
        }

        public function getDropoffOrLastWaypoint(): object
        {
            return (object) ['public_id' => 'place_dropoff'];
        }
    };
    $order = new Fleetbase\FleetOps\Models\Order();
    $order->forceFill(['uuid' => 'order_uuid', 'public_id' => 'order_public']);
    $order->setRelation('customer', $customer);
    $order->setRelation('payload', $payload);

    $createdBuffer          = new BufferedOutput();
    $created                = new TestableSendOrderNotification();
    $created->resolvedOrder = $order;
    $created->setInput(new ArrayInput([
        '--id'    => 'order_public',
        '--event' => 'created',
    ], $created->getDefinition()));
    $created->setOutput(new OutputStyle(new ArrayInput([]), $createdBuffer));

    expect($created->handle())->toBe(0)
        ->and(CommandNotificationStub::$arguments)->toBe([$order])
        ->and($createdBuffer->fetch())->toContain("Notification 'created' has been triggered");

    $nearbyBuffer          = new BufferedOutput();
    $nearby                = new TestableSendOrderNotification();
    $nearby->resolvedOrder = $order;
    $nearby->selectedEvent = 'nearby';
    $nearby->setInput(new ArrayInput([], $nearby->getDefinition()));
    $nearby->setOutput(new OutputStyle(new ArrayInput([]), $nearbyBuffer));

    expect($nearby->handle())->toBe(0)
        ->and(CommandNotificationStub::$arguments)->toBe([$order, 1250, 300])
        ->and($nearbyBuffer->fetch())->toContain("Notification 'nearby' has been triggered")
        ->and($customer->notifications)->toHaveCount(2);
});

test('manual notification command reports notification construction failures without crashing', function () {
    $order = new Fleetbase\FleetOps\Models\Order();
    $order->forceFill(['uuid' => 'order_uuid', 'public_id' => 'order_public']);
    $order->setRelation('customer', new CommandNotificationCustomerStub());

    $buffer                 = new BufferedOutput();
    $command                = new TestableSendOrderNotification();
    $command->resolvedOrder = $order;
    $command->setInput(new ArrayInput([
        '--id'    => 'order_public',
        '--event' => 'failing',
    ], $command->getDefinition()));
    $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

    expect($command->handle())->toBe(0)
        ->and($buffer->fetch())->toContain('Notification construction failed');
});

test('manual notification command resolves local distance matrices without external providers', function () {
    config(['fleetops.distance_matrix.provider' => 'calculate']);
    $point    = new Fleetbase\LaravelMysqlSpatial\Types\Point(47.918, 106.917);
    $resolver = new ReflectionMethod(SendOrderNotification::class, 'getDistanceMatrix');
    $matrix   = $resolver->invoke(new SendOrderNotification(), $point, $point);

    expect($matrix)->toBeObject()
        ->and($matrix->distance)->toBe(0.0)
        ->and($matrix->time)->toBe(0.0);
});

test('stripe migration command reports an unknown explicitly selected store', function () {
    $schema = Model::getConnectionResolver()->connection('mysql')->getSchemaBuilder();
    $schema->dropIfExists('stores');
    $schema->create('stores', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('name')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Illuminate\Container\Container::getInstance()->instance('app', Illuminate\Container\Container::getInstance());
    Illuminate\Container\Container::getInstance()->forgetInstance('request');
    Illuminate\Container\Container::getInstance()->instance(
        'session',
        new Illuminate\Session\Store('storefront-tests', new Illuminate\Session\NullSessionHandler())
    );
    $buffer            = new BufferedOutput();
    $command           = new TestableMigrateStripeSandboxCustomers();
    $command->options  = ['store' => 'store_missing', 'dry-run' => true];
    $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));
    $status = $command->handle();

    expect($status)->toBe(Command::FAILURE)
        ->and($buffer->fetch())->toContain("Store 'store_missing' not found.");
});

test('stripe migration command scans all stores and an explicitly selected store', function () {
    $schema = Model::getConnectionResolver()->connection('mysql')->getSchemaBuilder();
    $schema->dropIfExists('gateways');
    $schema->dropIfExists('stores');
    $schema->create('stores', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('name')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('gateways', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('code')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Model::getConnectionResolver()->connection('mysql')->table('stores')->insert([
        'uuid'      => 'store_uuid',
        'public_id' => 'store_abcdefgh',
        'name'      => 'Corner Store',
    ]);
    Illuminate\Container\Container::getInstance()->instance('app', Illuminate\Container\Container::getInstance());
    Illuminate\Container\Container::getInstance()->instance(
        'session',
        new Illuminate\Session\Store('storefront-tests', new Illuminate\Session\NullSessionHandler())
    );
    $buffer           = new BufferedOutput();
    $command          = new TestableMigrateStripeSandboxCustomers();
    $command->options = ['store' => null, 'dry-run' => false];
    $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));
    $status                   = $command->handle();
    $display                  = $buffer->fetch();
    $selectedBuffer           = new BufferedOutput();
    $selected                 = new TestableMigrateStripeSandboxCustomers();
    $selected->options        = ['store' => 'store_abcdefgh', 'dry-run' => true];
    $selected->setOutput(new OutputStyle(new ArrayInput([]), $selectedBuffer));
    $selectedStatus  = $selected->handle();
    $selectedDisplay = $selectedBuffer->fetch();

    expect($status)->toBe(Command::SUCCESS)
        ->and($display)->toContain('Starting Stripe customer migration...')
        ->and($display)->toContain('no Stripe gateway configured')
        ->and($display)->toContain('Stripe customer migration complete.')
        ->and($selectedStatus)->toBe(Command::SUCCESS)
        ->and($selectedDisplay)->toContain('no Stripe gateway configured')
        ->and($selectedDisplay)->toContain('Stripe customer migration complete.');
});

test('stripe migration command skips stores without a configured gateway', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('gateways');
    $schema->create('gateways', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('code')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $store = new Store();
    $store->forceFill([
        'uuid'         => 'store_uuid',
        'public_id'    => 'store_public',
        'company_uuid' => 'company_uuid',
        'name'         => 'Corner Store',
    ]);
    $buffer  = new BufferedOutput();
    $command = new MigrateStripeSandboxCustomers();
    $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

    expect($command->migrateCustomers($store, false))->toBe(Command::SUCCESS)
        ->and($buffer->fetch())->toContain('no Stripe gateway configured');
});

test('stripe migration command refuses to migrate customers through a sandbox gateway', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('gateways');
    $schema->create('gateways', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('code')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->string('name')->nullable();
        $table->boolean('sandbox')->default(false);
        $table->text('config')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $connection->table('gateways')->insert([
        'uuid'       => 'gateway_uuid',
        'code'       => 'stripe',
        'owner_uuid' => 'store_uuid',
        'name'       => 'Stripe',
        'sandbox'    => true,
        'config'     => json_encode(['secret_key' => 'test-key']),
    ]);
    $store = new Store();
    $store->forceFill([
        'uuid'         => 'store_uuid',
        'public_id'    => 'store_public',
        'company_uuid' => 'company_uuid',
        'name'         => 'Corner Store',
    ]);
    $buffer  = new BufferedOutput();
    $command = new MigrateStripeSandboxCustomers();
    $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

    expect($command->migrateCustomers($store, false))->toBe(Command::SUCCESS)
        ->and($buffer->fetch())->toContain('using a sandbox gateway');
});

test('stripe migration command safely completes live-store scans when no customers require migration', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('gateways');
    $schema->dropIfExists('contacts');
    $schema->create('gateways', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('code')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->boolean('sandbox')->default(false);
        $table->text('config')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('contacts', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('type')->nullable();
        $table->string('name')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $connection->table('gateways')->insert([
        'uuid'       => 'gateway_uuid',
        'code'       => 'stripe',
        'owner_uuid' => 'store_uuid',
        'sandbox'    => false,
        'config'     => json_encode(['secret_key' => 'live-key']),
    ]);
    $connection->table('contacts')->insert([
        'uuid'         => 'customer_uuid',
        'public_id'    => 'contact_public',
        'company_uuid' => 'company_uuid',
        'type'         => 'customer',
        'name'         => 'No Stripe Customer',
        'meta'         => json_encode([]),
    ]);
    $store = new Store();
    $store->forceFill([
        'uuid'         => 'store_uuid',
        'public_id'    => 'store_public',
        'company_uuid' => 'company_uuid',
        'name'         => 'Corner Store',
    ]);
    $buffer  = new BufferedOutput();
    $command = new MigrateStripeSandboxCustomers();
    $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

    expect($command->migrateCustomers($store, false))->toBe(Command::SUCCESS)
        ->and($buffer->fetch())->toContain('Will have sandbox customers migrated');
});

test('stripe migration command distinguishes test customers from already-live customers without writes', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('gateways');
    $schema->dropIfExists('contacts');
    $schema->create('gateways', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('code')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->boolean('sandbox')->default(false);
        $table->text('config')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('contacts', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('type')->nullable();
        $table->string('name')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $connection->table('gateways')->insert([
        'uuid'       => 'gateway_uuid',
        'code'       => 'stripe',
        'owner_uuid' => 'store_uuid',
        'sandbox'    => false,
        'config'     => json_encode(['secret_key' => 'sk_live_store']),
    ]);
    $connection->table('contacts')->insert([
        'uuid'         => 'customer_uuid',
        'public_id'    => 'contact_public',
        'company_uuid' => 'company_uuid',
        'type'         => 'customer',
        'name'         => 'Ada Buyer',
        'email'        => 'ada@example.test',
        'meta'         => json_encode(['stripe_id' => 'cus_test']),
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
    $store = new Store();
    $store->forceFill([
        'uuid'         => 'store_uuid',
        'public_id'    => 'store_public',
        'company_uuid' => 'company_uuid',
        'name'         => 'Corner Store',
    ]);
    Stripe\ApiRequestor::setHttpClient(new class implements Stripe\HttpClient\ClientInterface {
        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            return [json_encode(['error' => [
                'message' => 'No such customer',
                'type'    => 'invalid_request_error',
            ]]), 404, []];
        }
    });
    $dryRunBuffer = new BufferedOutput();
    $dryRun       = new MigrateStripeSandboxCustomers();
    $dryRun->setOutput(new OutputStyle(new ArrayInput([]), $dryRunBuffer));

    expect($dryRun->migrateCustomers($store, true))->toBe(Command::SUCCESS)
        ->and($dryRunBuffer->fetch())->toContain('Would migrate test Stripe ID cus_test to live');

    Stripe\ApiRequestor::setHttpClient(new class implements Stripe\HttpClient\ClientInterface {
        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            return [json_encode([
                'id'       => 'cus_test',
                'object'   => 'customer',
                'livemode' => true,
            ]), 200, []];
        }
    });
    $liveBuffer = new BufferedOutput();
    $live       = new MigrateStripeSandboxCustomers();
    $live->setOutput(new OutputStyle(new ArrayInput([]), $liveBuffer));

    expect($live->migrateCustomers($store, false))->toBe(Command::SUCCESS)
        ->and($liveBuffer->fetch())->toContain('is already a live customer');

    Stripe\ApiRequestor::setHttpClient(new class implements Stripe\HttpClient\ClientInterface {
        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            if ($method === 'post') {
                return [json_encode([
                    'id'       => 'cus_live_new',
                    'object'   => 'customer',
                    'livemode' => true,
                ]), 200, []];
            }

            return [json_encode(['error' => [
                'message' => 'No such customer',
                'type'    => 'invalid_request_error',
            ]]), 404, []];
        }
    });
    $migrationBuffer = new BufferedOutput();
    $migration       = new MigrateStripeSandboxCustomers();
    $migration->setOutput(new OutputStyle(new ArrayInput([]), $migrationBuffer));

    expect($migration->migrateCustomers($store, false))->toBe(Command::SUCCESS)
        ->and($migrationBuffer->fetch())->toContain('Migrated test ID cus_test to live Stripe ID cus_live_new');

    $meta = json_decode($connection->table('contacts')->where('uuid', 'customer_uuid')->value('meta'), true);

    expect($meta)->toMatchArray([
        'stripe_id_sandbox' => 'cus_test',
        'stripe_id'         => 'cus_live_new',
    ]);

    Stripe\ApiRequestor::setHttpClient(new Stripe\HttpClient\CurlClient());
});
