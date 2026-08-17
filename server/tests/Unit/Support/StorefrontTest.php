<?php

use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Support\Storefront;
use Illuminate\Database\Eloquent\Model;

class StorefrontOrderStub extends Fleetbase\FleetOps\Models\Order
{
    public array $calls     = [];
    public bool $pickup     = false;
    public bool $failStatus = false;
    public array $drivers   = [];

    public function isMeta($key): bool
    {
        return $key === 'is_pickup' && $this->pickup;
    }

    public function firstDispatchWithActivity(): Fleetbase\FleetOps\Models\Order
    {
        $this->calls[] = 'first_dispatch';

        return $this;
    }

    public function setStatus(?string $status, $andSave = true)
    {
        if ($this->failStatus) {
            throw new RuntimeException('status failed');
        }
        $this->status  = $status;
        $this->calls[] = 'status:' . $status;

        return $this;
    }

    public function insertActivity(Fleetbase\FleetOps\Flow\Activity $activity, $location = [], $proof = null): string
    {
        $this->calls[] = 'activity:' . $activity->code;

        return 'tracking_status_uuid';
    }

    public function getLastLocation()
    {
        return ['lat' => 47.9, 'lng' => 106.9];
    }

    public function updateStatus($code = null)
    {
        $this->status  = $code;
        $this->calls[] = 'update_status:' . $code;

        return $this;
    }

    public function update(array $attributes = [], array $options = [])
    {
        $this->forceFill($attributes);
        $this->calls[] = 'update';

        return true;
    }

    public function saveQuietly(array $options = [])
    {
        $this->calls[] = 'save_quietly';

        return true;
    }

    public function findClosestDrivers(int $distance = 6000): Illuminate\Support\Collection
    {
        return collect($this->drivers);
    }

    public function assignDriver($driver, $silent = false)
    {
        $this->calls[] = 'driver:' . $driver;

        return $this;
    }

    public function dispatchWithActivity(): Fleetbase\FleetOps\Models\Order
    {
        $this->calls[] = 'dispatch';

        return $this;
    }
}

class StorefrontCustomerNotificationStub extends Model
{
    public bool $notified = false;
    public bool $fail     = false;

    public function notify($notification): void
    {
        if ($this->fail) {
            throw new RuntimeException('notification failed');
        }
        $this->notified = true;
    }
}

class StorefrontSupportProbe extends Storefront
{
    public static function companyUuid(Fleetbase\Models\Company|string|null $company): ?string
    {
        return parent::resolveCompanyUuid($company);
    }
}

function createStorefrontSupportSchema(): void
{
    $schema = Model::getConnectionResolver()->connection('mysql')->getSchemaBuilder();
    foreach (['personal_access_tokens', 'contacts', 'users', 'order_configs', 'notification_channels', 'products', 'networks', 'stores'] as $table) {
        $schema->dropIfExists($table);
    }
    foreach (['stores', 'networks'] as $tableName) {
        $schema->create($tableName, function ($table) {
            $table->increments('id');
            foreach ([
                'uuid', 'public_id', 'company_uuid', 'backdrop_uuid', 'logo_uuid', 'order_config_uuid',
                'key', 'name', 'description', 'translations', 'website', 'facebook', 'instagram',
                'twitter', 'email', 'phone', 'tags', 'currency', 'timezone', 'pod_method', 'options', 'alertable',
            ] as $column) {
                $table->text($column)->nullable();
            }
            $table->timestamps();
            $table->softDeletes();
        });
    }
    $schema->create('products', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('name')->nullable();
        $table->text('description')->nullable();
        $table->decimal('price', 12, 2)->nullable();
        $table->decimal('sale_price', 12, 2)->nullable();
        $table->boolean('is_on_sale')->default(false);
        $table->softDeletes();
    });
    $schema->create('notification_channels', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->string('scheme')->nullable();
        $table->softDeletes();
    });
    $schema->create('order_configs', function ($table) {
        $table->increments('id');
        $table->string('uuid')->default('generated_config_uuid');
        $table->string('company_uuid')->nullable();
        $table->string('key')->nullable();
        $table->string('namespace')->nullable();
        $table->string('name')->nullable();
        $table->text('description')->nullable();
        $table->boolean('core_service')->default(false);
        $table->string('status')->nullable();
        $table->string('version')->nullable();
        $table->text('tags')->nullable();
        $table->text('entities')->nullable();
        $table->text('meta')->nullable();
        $table->text('flow')->nullable();
        $table->text('activities')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('users', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('name')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('contacts', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('type')->nullable();
        $table->softDeletes();
    });
    $schema->create('personal_access_tokens', function ($table) {
        $table->increments('id');
        $table->string('tokenable_type');
        $table->string('tokenable_id');
        $table->string('name');
        $table->string('token', 64);
        $table->text('abilities')->nullable();
        $table->timestamp('last_used_at')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
    });
}

test('storefront resolves store and network identities products and cart item descriptions', function () {
    createStorefrontSupportSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('stores')->insert([
        'uuid'      => '11111111-1111-4111-8111-111111111111',
        'public_id' => 'store_public',
        'key'       => 'store_key',
        'name'      => 'Corner Store',
    ]);
    $connection->table('networks')->insert([
        'uuid'      => '22222222-2222-4222-8222-222222222222',
        'public_id' => 'network_public',
        'key'       => 'network_key',
        'name'      => 'Market Network',
    ]);
    $connection->table('products')->insert([
        'uuid'       => 'product_uuid',
        'public_id'  => 'product_public',
        'name'       => 'Coffee',
        'price'      => 1200,
        'sale_price' => 1000,
        'is_on_sale' => true,
    ]);
    $connection->table('notification_channels')->insert([
        'uuid'       => 'channel_uuid',
        'owner_uuid' => '11111111-1111-4111-8111-111111111111',
        'scheme'     => 'email',
    ]);
    $connection->table('order_configs')->insert([
        'uuid'       => 'order_config_uuid',
        'flow'       => '[]',
        'activities' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    session(['storefront_key' => 'network_key']);
    $about       = Storefront::about();
    $network     = Storefront::findAbout('network_public');
    $product     = Storefront::getProduct('product_public');
    $description = Storefront::getFullDescriptionFromCartItem((object) [
        'name'     => 'Coffee',
        'variants' => [['name' => 'Large'], ['name' => 'Hot']],
        'addons'   => [['name' => 'Oat Milk']],
    ]);

    expect($about)->toBeInstanceOf(Network::class)
        ->and($about->is_network)->toBeTrue()
        ->and($network)->toBeInstanceOf(Network::class)
        ->and($network->is_network)->toBeTrue()
        ->and($product->name)->toBe('Coffee')
        ->and($description)->toBe('Coffee with Variation: Large,Hot with Addons: Oat Milk')
        ->and(Storefront::hasNotificationChannelConfigured(
            '11111111-1111-4111-8111-111111111111',
            'email'
        ))->toBeTrue()
        ->and(Storefront::hasNotificationChannelConfigured('store_public', 'sms'))->toBeFalse()
        ->and(Storefront::hasNotificationChannelConfigured('network_public', 'email'))->toBeFalse()
        ->and(Storefront::hasNotificationChannelConfigured(
            '22222222-2222-4222-8222-222222222222',
            'email'
        ))->toBeFalse()
        ->and(Storefront::hasNotificationChannelConfigured('unknown_public', 'email'))->toBeFalse()
        ->and(Storefront::hasNotificationChannelConfigured(null, 'email'))->toBeFalse()
        ->and(Storefront::hasNotificationChannelConfigured('', 'email'))->toBeFalse()
        ->and(Storefront::findAbout('network_missing'))->toBeNull();

    $store = Store::where('public_id', 'store_public')->first();
    expect(Storefront::hasNotificationChannelConfigured($store, 'email'))->toBeTrue();
});

test('storefront auto acceptance and dispatch preserve pickup adhoc and driver transitions', function () {
    createStorefrontSupportSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('stores')->insert([
        'uuid'      => 'store_uuid',
        'public_id' => 'store_public',
        'key'       => 'store_key',
        'name'      => 'Corner Store',
    ]);
    $connection->table('order_configs')->insert([
        'uuid'       => 'order_config_uuid',
        'flow'       => '[]',
        'activities' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $customer         = new StorefrontCustomerNotificationStub();
    $accepted         = new StorefrontOrderStub();
    $accepted->pickup = true;
    $accepted->forceFill([
        'order_config_uuid' => 'order_config_uuid',
        'meta'              => ['storefront_id' => 'store_public'],
    ]);
    $accepted->setRelation('customer', $customer);
    $acceptedResult = Storefront::autoAcceptOrder($accepted);

    $failure             = new StorefrontOrderStub();
    $failure->failStatus = true;
    $failure->forceFill(['order_config_uuid' => 'order_config_uuid']);
    $failureResult = Storefront::autoAcceptOrder($failure);

    $notificationFailure   = new StorefrontOrderStub();
    $failingCustomer       = new StorefrontCustomerNotificationStub();
    $failingCustomer->fail = true;
    $notificationFailure->forceFill([
        'order_config_uuid' => 'order_config_uuid',
        'meta'              => ['storefront_id' => 'store_public'],
    ]);
    $notificationFailure->setRelation('customer', $failingCustomer);
    $notificationFailureResult = Storefront::autoAcceptOrder($notificationFailure);

    $pickup         = new StorefrontOrderStub();
    $pickup->pickup = true;
    $pickup->forceFill(['order_config_uuid' => 'order_config_uuid']);
    Storefront::autoDispatchOrder($pickup);

    $adhoc = new StorefrontOrderStub();
    $adhoc->forceFill(['order_config_uuid' => 'order_config_uuid']);
    Storefront::autoDispatchOrder($adhoc);

    $assigned          = new StorefrontOrderStub();
    $assigned->drivers = ['driver_uuid'];
    $assigned->forceFill(['order_config_uuid' => 'order_config_uuid']);
    Storefront::autoDispatchOrder($assigned, false);

    $unassigned = new StorefrontOrderStub();
    $unassigned->forceFill(['order_config_uuid' => 'order_config_uuid']);
    Storefront::autoDispatchOrder($unassigned, false);

    expect($acceptedResult)->toBe($accepted)
        ->and($accepted->calls)->toContain('first_dispatch', 'status:accepted', 'activity:accepted')
        ->and($customer->notified)->toBeTrue()
        ->and($failureResult->getData(true))->toBe(['error' => 'Unable to accept order.'])
        ->and($notificationFailureResult)->toBe($notificationFailure)
        ->and($pickup->calls)->toContain('update_status:pickup_ready')
        ->and($adhoc->calls)->toContain('update', 'dispatch')
        ->and($adhoc->adhoc)->toBeTrue()
        ->and($assigned->calls)->toContain('driver:driver_uuid', 'dispatch')
        ->and($unassigned->calls)->toContain('update', 'dispatch')
        ->and($unassigned->adhoc)->toBeTrue();
});

test('storefront resolves cached session and related order configuration contracts', function () {
    createStorefrontSupportSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('stores')->insert([
        'uuid'              => 'store_uuid',
        'public_id'         => 'store_public',
        'company_uuid'      => '11111111-1111-4111-8111-111111111111',
        'order_config_uuid' => 'order_config_uuid',
        'key'               => 'store_key',
        'name'              => 'Corner Store',
    ]);
    $connection->table('order_configs')->insert([
        'uuid'          => 'order_config_uuid',
        'company_uuid'  => '11111111-1111-4111-8111-111111111111',
        'key'           => 'storefront-config',
        'namespace'     => 'storefront',
        'flow'          => '[]',
        'activities'    => '[]',
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);
    session([
        'company'        => '11111111-1111-4111-8111-111111111111',
        'storefront_key' => 'store_key',
    ]);

    $config        = Fleetbase\FleetOps\Models\OrderConfig::where('uuid', 'order_config_uuid')->first();
    $relatedOrder  = new StorefrontOrderStub();
    $relatedOrder->setRelation('orderConfig', $config);
    $patched       = Storefront::patchOrderConfig($relatedOrder);
    $sessionConfig = Storefront::getSessionOrderConfig();
    $firstLookup   = Storefront::getOrderConfig('11111111-1111-4111-8111-111111111111');
    $cachedLookup  = Storefront::getOrderConfig('11111111-1111-4111-8111-111111111111');
    $company       = new Fleetbase\Models\Company();
    $company->uuid = '22222222-2222-4222-8222-222222222222';
    $connection->table('order_configs')->insert([
        'uuid'          => 'patch_config_uuid',
        'company_uuid'  => '33333333-3333-4333-8333-333333333333',
        'key'           => 'storefront',
        'namespace'     => 'system:order-config:storefront',
        'flow'          => '[]',
        'activities'    => '[]',
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);
    $companyOrder = new StorefrontOrderStub();
    $companyOrder->forceFill(['company_uuid' => '33333333-3333-4333-8333-333333333333']);
    $companyPatched = Storefront::patchOrderConfig($companyOrder);

    expect($patched->uuid)->toBe('order_config_uuid')
        ->and($sessionConfig->uuid)->toBe('order_config_uuid')
        ->and($firstLookup)->toBeInstanceOf(Fleetbase\FleetOps\Models\OrderConfig::class)
        ->and($cachedLookup)->toBe($firstLookup)
        ->and(StorefrontSupportProbe::companyUuid($company))->toBe('22222222-2222-4222-8222-222222222222')
        ->and(StorefrontSupportProbe::companyUuid(null))->toBe('11111111-1111-4111-8111-111111111111')
        ->and($companyPatched->uuid)->toBe('patch_config_uuid')
        ->and($companyOrder->order_config_uuid)->toBe('patch_config_uuid')
        ->and($companyOrder->calls)->toContain('save_quietly');

    $redis = new class {
        public array $keys = [];

        public function del(string $key): int
        {
            $this->keys[] = $key;

            return 1;
        }
    };
    app()->instance('redis', $redis);
    Illuminate\Support\Facades\Facade::clearResolvedInstance('redis');
    session(['storefront_store' => 'store_public']);

    expect(Storefront::destroyCart('cart_uuid'))->toBe(1)
        ->and($redis->keys)->toBe(['cart:store_public:cart_uuid']);
});

test('storefront resolves legacy customer tokens and sends immediate and queued order alerts', function () {
    createStorefrontSupportSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('stores')->insert([
        'uuid'       => 'store_uuid',
        'public_id'  => 'store_public',
        'key'        => 'store_key',
        'name'       => 'Corner Store',
        'alertable'  => json_encode(['for_new_order' => ['user_public']]),
    ]);
    $connection->table('users')->insert([
        'id'         => 1,
        'uuid'       => 'user_uuid',
        'public_id'  => 'user_public',
        'name'       => 'Store Operator',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $connection->table('contacts')->insert([
        'uuid'      => 'contact_uuid',
        'user_uuid' => 'user_uuid',
        'type'      => 'customer',
    ]);
    $connection->table('personal_access_tokens')->insert([
        'tokenable_type' => Fleetbase\Models\User::class,
        'tokenable_id'   => 'user_uuid',
        'name'           => 'legacy-customer-token',
        'token'          => hash('sha256', 'legacy-secret'),
        'abilities'      => '["*"]',
        'created_at'     => now(),
        'updated_at'     => now(),
    ]);
    $request = Illuminate\Http\Request::create('/storefront');
    $request->headers->set('Customer-Token', 'legacy-secret');
    $request->setLaravelSession(new Illuminate\Session\Store(
        'storefront-support-token',
        new Illuminate\Session\ArraySessionHandler(120)
    ));
    app()->instance('request', $request);
    session(['storefront_key' => 'store_key']);

    $dispatcher = new class implements Illuminate\Contracts\Notifications\Dispatcher {
        public array $calls = [];

        public function send($notifiables, $notification)
        {
            $this->calls[] = 'send';
        }

        public function sendNow($notifiables, $notification)
        {
            $this->calls[] = 'send_now';
        }
    };
    app()->instance(Illuminate\Notifications\ChannelManager::class, $dispatcher);
    Illuminate\Support\Facades\Facade::clearResolvedInstance(Illuminate\Notifications\ChannelManager::class);
    $order = new StorefrontOrderStub();
    $order->forceFill(['meta' => ['storefront_id' => 'store_public']]);

    $customer = Storefront::getCustomerFromToken();
    Storefront::alertNewOrder($order);
    Storefront::alertNewOrder($order, true);

    expect($customer->uuid)->toBe('contact_uuid')
        ->and($dispatcher->calls)->toBe(['send', 'send_now']);
});
