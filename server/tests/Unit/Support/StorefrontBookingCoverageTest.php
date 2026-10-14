<?php

use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\Models\Company;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Support\Storefront;
use Illuminate\Database\Eloquent\Model;

/** A booking order: answers its booking and pickup meta, and records what is done to it. */
class StorefrontCoverageBookingOrder extends Fleetbase\FleetOps\Models\Order
{
    public array $calls  = [];
    public bool $pickup  = true;
    public bool $booking = true;

    public function isMeta($key): bool
    {
        return match ($key) {
            'is_pickup'  => $this->pickup,
            'is_booking' => $this->booking,
            default      => false,
        };
    }

    public function updateStatus($code = null)
    {
        $this->calls[] = 'update_status:' . $code;

        return $this;
    }

    public function update(array $attributes = [], array $options = [])
    {
        $this->calls[] = 'update';

        return true;
    }

    public function dispatchWithActivity(): Fleetbase\FleetOps\Models\Order
    {
        $this->calls[] = 'dispatch';

        return $this;
    }
}

function storefrontCoverageDb()
{
    return Model::getConnectionResolver()->connection('mysql');
}

function storefrontCoverageSchema(): void
{
    $schema = storefrontCoverageDb()->getSchemaBuilder();
    foreach (['order_configs', 'places', 'stores', 'store_locations'] as $table) {
        $schema->dropIfExists($table);
    }

    $schema->create('order_configs', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
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
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('places', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('stores', function ($table) {
        $table->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'backdrop_uuid', 'logo_uuid', 'order_config_uuid', 'name', 'description', 'translations', 'website', 'facebook', 'instagram', 'twitter', 'email', 'phone', 'tags', 'currency', 'timezone', 'pod_method', 'options'] as $column) {
            $table->text($column)->nullable();
        }
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('store_locations', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id')->nullable();
        $table->string('store_uuid')->nullable();
        $table->string('place_uuid')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
}

beforeEach(function () {
    storefrontCoverageSchema();
    app()->instance('responsecache', new class {
        public function clear(): void
        {
        }
    });
    Model::setEventDispatcher(new Illuminate\Events\Dispatcher(app()));
    Model::clearBootedModels();
});

afterEach(function () {
    Model::unsetEventDispatcher();
    Model::clearBootedModels();
});

test('a store is found from one of its location places, and an unknown place finds none', function () {
    storefrontCoverageDb()->table('places')->insert(['uuid' => '44444444-4444-4444-8444-444444444444', 'public_id' => 'place_counter']);
    storefrontCoverageDb()->table('stores')->insert(['uuid' => 'store_uuid', 'public_id' => 'store_public', 'name' => 'Corner Store']);
    storefrontCoverageDb()->table('store_locations')->insert(['uuid' => 'location_uuid', 'store_uuid' => 'store_uuid', 'place_uuid' => '44444444-4444-4444-8444-444444444444']);

    expect(Storefront::getStoreFromLocation('place_counter'))->toBeInstanceOf(Store::class)
        ->and(Storefront::getStoreFromLocation('place_counter')->public_id)->toBe('store_public')
        ->and(Storefront::getStoreFromLocation('place_missing'))->toBeNull();
});

test('bookings get a flow of their own per company, created once and then reused', function () {
    $companyUuid = '55555555-5555-4555-8555-555555555555';

    $config = Storefront::getBookingOrderConfig($companyUuid);
    $flow   = $config->flow;

    expect($config)->toBeInstanceOf(OrderConfig::class)
        ->and($config->company_uuid)->toBe($companyUuid)
        ->and($config->namespace)->toBe(Storefront::BOOKING_CONFIG_NS)
        ->and($config->name)->toBe('Storefront Booking')
        ->and(array_keys($flow))->toBe(['created', 'accepted', 'dispatched', 'started', 'provider_arrived', 'in_progress', 'completed', 'canceled'])
        ->and($flow['accepted'])->toMatchArray(['code' => 'accepted', 'status' => 'Booking confirmed', 'activities' => ['dispatched', 'in_progress', 'canceled'], 'complete' => false, 'pod_method' => 'scan'])
        // Only the provider step is conditional: it is taken when the booking is not at the store.
        ->and($flow['dispatched']['logic'][0]['type'])->toBe('not')
        ->and($flow['dispatched']['logic'][0]['conditions'][0])->toBe(['field' => 'meta.is_pickup', 'operator' => 'equal', 'value' => 'true'])
        ->and($flow['in_progress']['logic'])->toBe([])
        ->and($flow['completed']['complete'])->toBeTrue()
        ->and($flow['canceled']['events'])->toBe(['order.canceled'])
        ->and($flow['created']['internalId'])->not->toBe($flow['accepted']['internalId'])
        // The same company is answered from memory, without another config.
        ->and(Storefront::getBookingOrderConfig($companyUuid))->toBe($config)
        ->and(OrderConfig::where('company_uuid', $companyUuid)->count())->toBe(1);
});

test('an existing booking config is found, and creating one for a company returns it', function () {
    $companyUuid   = '66666666-6666-4666-8666-666666666666';
    $company       = new Company();
    $company->uuid = $companyUuid;
    storefrontCoverageDb()->table('order_configs')->insert([
        'uuid'         => 'booking_config_uuid',
        'company_uuid' => $companyUuid,
        'key'          => Storefront::BOOKING_CONFIG_KEY,
        'namespace'    => Storefront::BOOKING_CONFIG_NS,
        'name'         => 'Storefront Booking',
        'flow'         => json_encode(['accepted' => ['code' => 'accepted', 'status' => 'Booked']]),
    ]);

    $found   = Storefront::getBookingOrderConfig($company);
    $again   = Storefront::createBookingConfig($company);
    $byUuid  = Storefront::createBookingConfig($companyUuid);

    expect($found->uuid)->toBe('booking_config_uuid')
        ->and($again->uuid)->toBe('booking_config_uuid')
        ->and($byUuid->uuid)->toBe('booking_config_uuid')
        ->and(OrderConfig::where('company_uuid', $companyUuid)->count())->toBe(1);
});

test('without a company there is no booking config', function () {
    $previous = session('company');
    session(['company' => null]);

    try {
        expect(Storefront::getBookingOrderConfig(null))->toBeNull()
            ->and(Storefront::getBookingOrderConfig('not-a-uuid'))->toBeNull()
            ->and(OrderConfig::query()->count())->toBe(0);
    } finally {
        session(['company' => $previous]);
    }
});

test('a booking is accepted with its own confirmation step', function () {
    $config   = Storefront::getBookingOrderConfig('77777777-7777-4777-8777-777777777777');
    $accepted = Storefront::createAcceptedActivity($config);
    $default  = Storefront::createAcceptedActivity();

    expect($accepted->code)->toBe('accepted')
        ->and($accepted->status)->toBe('Booking confirmed')
        ->and($accepted->details)->toBe('{storefront.name} has confirmed your booking.')
        ->and($default->status)->toBe('Order has been accepted');
});

test('a booking at the store is not dispatched: it starts when the customer comes in', function () {
    $config = Storefront::getBookingOrderConfig('88888888-8888-4888-8888-888888888888');

    $atStore = new StorefrontCoverageBookingOrder();
    $atStore->forceFill(['order_config_uuid' => $config->uuid]);

    $pickup          = new StorefrontCoverageBookingOrder();
    $pickup->booking = false;
    $pickup->forceFill(['order_config_uuid' => $config->uuid]);

    expect(Storefront::autoDispatchOrder($atStore))->toBe($atStore)
        ->and($atStore->calls)->toBe([])
        ->and(Storefront::isBookingOrder($atStore))->toBeTrue()
        // An ordinary pickup is made ready instead.
        ->and(Storefront::autoDispatchOrder($pickup))->toBe($pickup)
        ->and($pickup->calls)->toBe(['update_status:pickup_ready'])
        ->and(Storefront::isBookingOrder(new stdClass()))->toBeFalse();
});
