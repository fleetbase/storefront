<?php

use Fleetbase\Storefront\Http\Controllers\v1\ServiceQuoteController;
use Fleetbase\Storefront\Http\Requests\GetServiceQuoteFromCart;
use Illuminate\Database\Eloquent\Model;

class ServiceQuoteProviderControllerStub extends ServiceQuoteController
{
    public static ?Fleetbase\FleetOps\Models\ServiceQuote $quote = null;
    public static ?Throwable $failure                            = null;
    public static array $places                                  = [];
    public static array $serviceRates                            = [];

    protected function getIntegratedVendorQuote(
        Fleetbase\FleetOps\Models\IntegratedVendor $integratedVendor,
        string $requestId,
        array $places,
        ?string $serviceType,
        $scheduledAt,
        bool $isRouteOptimized,
    ): Fleetbase\FleetOps\Models\ServiceQuote {
        static::$places = $places;

        if (static::$failure) {
            throw static::$failure;
        }

        return static::$quote;
    }

    protected function getDrivingMatrix(Fleetbase\FleetOps\Models\Place $origin, Fleetbase\FleetOps\Models\Place $destination): object
    {
        return (object) ['distance' => 1200, 'time' => 300];
    }

    protected function getNetworkDistanceMatrix($origins, Fleetbase\FleetOps\Models\Place $destination): object
    {
        return (object) ['distance' => 2400, 'time' => 600];
    }

    protected function getServiceRates(Fleetbase\FleetOps\Models\Place $destination, string $orderConfigKey, ?string $currency)
    {
        return static::$serviceRates;
    }
}

class IntegratedVendorQuoteApiStub
{
    public ?string $requestId = null;
    public array $arguments   = [];
    public Fleetbase\FleetOps\Models\ServiceQuote $quote;

    public function setRequestId(string $requestId): static
    {
        $this->requestId = $requestId;

        return $this;
    }

    public function getQuoteFromPreliminaryPayload(...$arguments): Fleetbase\FleetOps\Models\ServiceQuote
    {
        $this->arguments = $arguments;

        return $this->quote;
    }
}

class IntegratedVendorQuoteModelStub extends Fleetbase\FleetOps\Models\IntegratedVendor
{
    public IntegratedVendorQuoteApiStub $apiStub;

    public function api()
    {
        return $this->apiStub;
    }
}

class ServiceRateQuoteStub extends Fleetbase\FleetOps\Models\ServiceRate
{
    public int $quotedAmount      = 0;
    public array $quotedEntities  = [];
    public array $quotedWaypoints = [];

    public function quoteFromPreliminaryData($entities = [], $waypoints = [], ?int $totalDistance = 0, ?int $totalTime = 0, ?bool $isCashOnDelivery = false, ?int $endpointCount = null)
    {
        $this->quotedEntities  = collect($entities)->all();
        $this->quotedWaypoints = $waypoints;

        return [
            $this->quotedAmount,
            collect([
                [
                    'amount'   => $this->quotedAmount,
                    'currency' => $this->currency,
                    'details'  => 'Delivery charge',
                    'code'     => 'delivery_fee',
                ],
            ]),
        ];
    }
}

test('service quote controller delegates integrated vendor quotes with the complete preliminary contract', function () {
    $quote = new Fleetbase\FleetOps\Models\ServiceQuote();
    $quote->forceFill(['uuid' => 'quote_uuid']);
    $api             = new IntegratedVendorQuoteApiStub();
    $api->quote      = $quote;
    $vendor          = new IntegratedVendorQuoteModelStub();
    $vendor->apiStub = $api;
    $method          = new ReflectionMethod(ServiceQuoteController::class, 'getIntegratedVendorQuote');

    $result = $method->invoke(
        new ServiceQuoteController(),
        $vendor,
        'request_abcdefgh',
        [['id' => 'place_origin'], ['id' => 'place_destination']],
        'express',
        '2026-07-28 09:00:00',
        true
    );

    expect($result)->toBe($quote)
        ->and($api->requestId)->toBe('request_abcdefgh')
        ->and($api->arguments)->toBe([
            [['id' => 'place_origin'], ['id' => 'place_destination']],
            [],
            'express',
            '2026-07-28 09:00:00',
            true,
        ]);
});

function createServiceQuoteLookupSchema(): void
{
    Fleetbase\FleetOps\Models\Entity::expand(
        'fromStorefrontProduct',
        Fleetbase\Storefront\Expansions\EntityExpansion::fromStorefrontProduct()
    );

    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();

    foreach (['network_stores', 'networks', 'places', 'store_locations', 'stores', 'vehicles', 'food_trucks', 'products', 'files', 'carts', 'service_quote_items', 'service_quotes', 'service_rates', 'integrated_vendors'] as $table) {
        $schema->dropIfExists($table);
    }

    $schema->create('places', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->text('location')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('store_locations', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('store_uuid')->nullable();
        $table->string('place_uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('stores', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->text('options')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('networks', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('network_stores', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('network_uuid')->nullable();
        $table->string('store_uuid')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('vehicles', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('food_trucks', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('vehicle_uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('products', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('primary_image_uuid')->nullable();
        $table->string('name')->nullable();
        $table->text('description')->nullable();
        $table->string('currency')->nullable();
        $table->string('sku')->nullable();
        $table->integer('price')->nullable();
        $table->integer('sale_price')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('files', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('carts', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('checkout_uuid')->nullable();
        $table->string('customer_id')->nullable();
        $table->string('unique_identifier')->nullable();
        $table->string('currency')->nullable();
        $table->string('discount_code')->nullable();
        $table->text('items')->nullable();
        $table->text('events')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('service_quotes', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('request_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('service_rate_uuid')->nullable();
        $table->string('payload_uuid')->nullable();
        $table->integer('amount')->nullable();
        $table->string('currency')->nullable();
        $table->text('meta')->nullable();
        $table->timestamp('expired_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('service_quote_items', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('service_quote_uuid')->nullable();
        $table->integer('amount')->nullable();
        $table->string('currency')->nullable();
        $table->string('details')->nullable();
        $table->string('code')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('service_rates', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('service_type')->nullable();
        $table->string('currency')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('integrated_vendors', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('provider')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
}

function seedServiceQuoteMarketplace(string $storeUuid = 'store_uuid', string $storePublicId = 'store_public'): void
{
    $connection = Model::getConnectionResolver()->connection('mysql');

    $connection->table('stores')->updateOrInsert(
        ['uuid' => $storeUuid],
        ['public_id' => $storePublicId, 'name' => 'Marketplace Store', 'options' => '{}']
    );
    $connection->table('networks')->updateOrInsert(
        ['uuid' => 'network_uuid'],
        ['public_id' => 'network_public', 'name' => 'Marketplace Network']
    );
    $connection->table('network_stores')->updateOrInsert(
        ['network_uuid' => 'network_uuid', 'store_uuid' => $storeUuid],
        ['uuid' => 'network_store_' . $storeUuid, 'created_at' => now(), 'updated_at' => now()]
    );

    session(['storefront_network' => 'network_uuid']);
}

test('service quote place lookup resolves tenant places and rejects missing typed resources', function () {
    createServiceQuoteLookupSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('places')->insert([
        'uuid'         => 'place_uuid',
        'public_id'    => 'place_public',
        'company_uuid' => 'company_uuid',
    ]);
    $connection->table('store_locations')->insert([
        'uuid'       => 'location_uuid',
        'public_id'  => 'store_location_public',
        'store_uuid' => 'store_uuid',
        'place_uuid' => 'place_uuid',
    ]);
    session([
        'company'          => 'company_uuid',
        'storefront_store' => 'store_uuid',
    ]);
    app()->instance('geocoder', new class {
        public function reverse(float $latitude, float $longitude): self
        {
            return $this;
        }

        public function get(): Illuminate\Support\Collection
        {
            return collect();
        }
    });
    Geocoder\Laravel\Facades\Geocoder::clearResolvedInstance('geocoder');
    $connection->getPdo()->sqliteCreateFunction('ST_GeomFromText', fn (string $wkt) => $wkt, 3);
    $controller  = new ServiceQuoteController();
    $coordinates = $controller->getPlaceFromId('47.918,106.917');

    expect($controller->getPlaceFromId('place_public')?->uuid)->toBe('place_uuid')
        ->and($controller->getPlaceFromId(['place_public'])?->uuid)->toBe('place_uuid')
        ->and($controller->getPlaceFromId('store_location_public')?->uuid)->toBe('place_uuid')
        ->and($controller->getPlaceFromId('store_location_missing'))->toBeNull()
        ->and($controller->getPlaceFromId('vehicle_missing'))->toBeNull()
        ->and($controller->getPlaceFromId('food_truck_missing'))->toBeNull()
        ->and($coordinates)->toBeInstanceOf(Fleetbase\FleetOps\Models\Place::class)
        ->and($coordinates->company_uuid)->toBe('company_uuid')
        ->and($controller->getPlaceFromId('unknown_place'))->toBeNull();
});

test('service quote from cart validates delivery endpoints before rate resolution', function () {
    createServiceQuoteLookupSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('places')->insert([
        'uuid'         => 'origin_uuid',
        'public_id'    => 'place_origin',
        'company_uuid' => 'company_uuid',
    ]);
    session([
        'company'             => 'company_uuid',
        'storefront_key'      => 'store_public',
        'storefront_currency' => 'USD',
    ]);
    $controller = new ServiceQuoteController();

    $missingOrigin = $controller->fromCart(GetServiceQuoteFromCart::create('/quote', 'POST', [
        'origin'      => 'place_missing',
        'destination' => 'place_missing',
        'cart'        => 'browser-cart',
    ]));
    $missingDestination = $controller->fromCart(GetServiceQuoteFromCart::create('/quote', 'POST', [
        'origin'      => 'place_origin',
        'destination' => 'place_missing',
        'cart'        => 'browser-cart',
    ]));

    expect($missingOrigin->getData(true))->toBe(['error' => 'No delivery origin!'])
        ->and($missingDestination->getData(true))->toBe(['error' => 'No delivery destination!']);
});

test('service quote rejects missing integrated facilitators without runtime errors', function () {
    createServiceQuoteLookupSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('places')->insert([
        ['uuid' => 'origin_uuid', 'public_id' => 'place_origin', 'company_uuid' => 'company_uuid'],
        ['uuid' => 'destination_uuid', 'public_id' => 'place_destination', 'company_uuid' => 'company_uuid'],
    ]);
    $connection->table('carts')->insert([
        'uuid'              => 'cart_uuid',
        'public_id'         => 'cart_public',
        'unique_identifier' => 'browser-cart',
        'currency'          => 'USD',
        'items'             => '[]',
        'events'            => '[]',
        'expires_at'        => now()->addHour(),
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);
    session([
        'company'        => 'company_uuid',
        'storefront_key' => null,
    ]);

    $controller         = new ServiceQuoteController();
    $missingFacilitator = $controller->fromCart(GetServiceQuoteFromCart::create('/quote', 'POST', [
        'origin'      => 'place_origin',
        'destination' => 'place_destination',
        'cart'        => 'browser-cart',
        'facilitator' => 'integrated_vendor_missing',
    ]));

    expect($missingFacilitator->getData(true))->toBe(['error' => 'Integrated vendor not found!']);
});

test('service quote persists integrated facilitator route metadata and contains provider failures', function () {
    createServiceQuoteLookupSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('places')->insert([
        ['uuid' => 'origin_uuid', 'public_id' => 'place_origin', 'company_uuid' => 'company_uuid'],
        ['uuid' => 'destination_uuid', 'public_id' => 'place_destination', 'company_uuid' => 'company_uuid'],
    ]);
    $connection->table('carts')->insert([
        'uuid'              => 'cart_uuid',
        'public_id'         => 'cart_public',
        'unique_identifier' => 'browser-cart',
        'currency'          => 'USD',
        'items'             => '[]',
        'events'            => '[]',
        'expires_at'        => now()->addHour(),
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);
    $connection->table('integrated_vendors')->insert([
        'uuid'         => 'vendor_uuid',
        'public_id'    => 'integrated_vendor_public',
        'company_uuid' => 'company_uuid',
        'provider'     => 'provider_public',
    ]);
    $connection->table('service_quotes')->insert([
        'uuid'         => 'quote_uuid',
        'public_id'    => 'service_quote_public',
        'company_uuid' => 'company_uuid',
        'amount'       => 1200,
        'currency'     => 'USD',
        'meta'         => '{}',
    ]);
    session([
        'company'        => 'company_uuid',
        'storefront_key' => 'store_public',
    ]);

    ServiceQuoteProviderControllerStub::$quote   = Fleetbase\FleetOps\Models\ServiceQuote::where('uuid', 'quote_uuid')->firstOrFail();
    ServiceQuoteProviderControllerStub::$failure = null;
    $controller                                  = new ServiceQuoteProviderControllerStub();
    $requestData                                 = [
        'origin'             => 'place_origin',
        'destination'        => 'place_destination',
        'cart'               => 'browser-cart',
        'facilitator'        => 'integrated_vendor_public',
        'service_type'       => 'delivery',
        'scheduled_at'       => '2026-08-01 10:00:00',
        'is_route_optimized' => false,
    ];

    $resource  = $controller->fromCart(GetServiceQuoteFromCart::create('/quote', 'POST', $requestData));
    $quoteMeta = Fleetbase\FleetOps\Models\ServiceQuote::where('uuid', 'quote_uuid')->firstOrFail()->meta;

    ServiceQuoteProviderControllerStub::$failure = new RuntimeException('Provider unavailable');
    $failure                                     = $controller->fromCart(GetServiceQuoteFromCart::create('/quote', 'POST', $requestData));
    ServiceQuoteProviderControllerStub::$failure = null;

    expect($resource)->toBeInstanceOf(Fleetbase\FleetOps\Http\Resources\v1\ServiceQuote::class)
        ->and($quoteMeta['origin'])->toBe('place_origin')
        ->and($quoteMeta['destination'])->toBe('place_destination')
        ->and(ServiceQuoteProviderControllerStub::$places)->toHaveCount(2)
        ->and($failure->getData(true))->toBe(['error' => 'Provider unavailable']);
});

test('network service quote requires a delivery destination', function () {
    createServiceQuoteLookupSchema();
    session([
        'company'             => 'company_uuid',
        'storefront_key'      => 'network_public',
        'storefront_currency' => 'USD',
    ]);

    $controller = new ServiceQuoteController();
    $response   = $controller->fromCartForNetwork(
        GetServiceQuoteFromCart::create('/quote', 'POST', [
            'destination' => 'place_missing',
            'cart'        => 'network-cart',
        ])
    );
    $routedResponse = $controller->fromCart(
        GetServiceQuoteFromCart::create('/quote', 'POST', [
            'destination' => 'place_missing',
            'cart'        => 'network-cart',
        ])
    );

    expect($response->getData(true))->toBe(['error' => 'No delivery destination!'])
        ->and($routedResponse->getData(true))->toBe(['error' => 'No delivery destination!']);
});

test('network service quote rejects missing integrated facilitators safely', function () {
    createServiceQuoteLookupSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    seedServiceQuoteMarketplace();
    $connection->table('places')->insert([
        'uuid'      => 'origin_uuid',
        'public_id' => 'place_origin',
    ]);
    $connection->table('store_locations')->insert([
        'uuid'       => 'location_uuid',
        'public_id'  => 'store_location_public',
        'store_uuid' => 'store_uuid',
        'place_uuid' => 'origin_uuid',
    ]);
    $connection->table('carts')->insert([
        'uuid'              => 'cart_uuid',
        'public_id'         => 'cart_public',
        'unique_identifier' => 'network-cart',
        'currency'          => 'USD',
        'items'             => '[]',
        'events'            => '[]',
        'expires_at'        => now()->addHour(),
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);
    session([
        'company'        => null,
        'storefront_key' => null,
    ]);
    $controller = new class extends ServiceQuoteController {
        public function getPlaceFromId(string|array $id): ?Fleetbase\FleetOps\Models\Place
        {
            $place = new Fleetbase\FleetOps\Models\Place();
            $place->forceFill(['uuid' => 'destination_uuid', 'public_id' => 'place_destination']);

            return $place;
        }
    };

    $missingFacilitator = $controller->fromCartForNetwork(GetServiceQuoteFromCart::create('/quote', 'POST', [
        'destination' => 'place_destination',
        'cart'        => 'network-cart',
        'origin'      => 'store_location_public',
        'facilitator' => 'integrated_vendor_missing',
    ]));

    expect($missingFacilitator->getData(true))->toBe(['error' => 'Integrated vendor not found!']);
});

test('network service quote derives origins from explicit and default store locations', function () {
    createServiceQuoteLookupSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('places')->insert([
        'uuid'      => 'origin_uuid',
        'public_id' => 'place_origin',
    ]);
    $connection->table('stores')->insert([
        'uuid'      => 'store_uuid',
        'public_id' => 'store_public',
        'name'      => 'Corner Store',
        'options'   => '{}',
    ]);
    seedServiceQuoteMarketplace();
    $connection->table('store_locations')->insert([
        'uuid'       => 'location_uuid',
        'public_id'  => 'store_location_public',
        'store_uuid' => 'store_uuid',
        'place_uuid' => 'origin_uuid',
    ]);
    $connection->table('carts')->insert([
        [
            'uuid'              => 'explicit_cart_uuid',
            'public_id'         => 'explicit_cart_public',
            'company_uuid'      => 'company_uuid',
            'unique_identifier' => 'explicit-cart',
            'currency'          => 'USD',
            'items'             => json_encode([
                [
                    'store_id'          => 'store_public',
                    'store_location_id' => 'store_location_public',
                ],
            ]),
            'events'     => '[]',
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'uuid'              => 'default_cart_uuid',
            'public_id'         => 'default_cart_public',
            'company_uuid'      => 'company_uuid',
            'unique_identifier' => 'default-cart',
            'currency'          => 'USD',
            'items'             => json_encode([
                [
                    'store_id'          => 'store_public',
                    'store_location_id' => null,
                ],
            ]),
            'events'     => '[]',
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'uuid'              => 'missing_location_cart_uuid',
            'public_id'         => 'missing_location_cart_public',
            'company_uuid'      => 'company_uuid',
            'unique_identifier' => 'missing-location-cart',
            'currency'          => 'USD',
            'items'             => json_encode([
                [
                    'store_id'          => 'store_public',
                    'store_location_id' => 'store_location_missing',
                ],
            ]),
            'events'     => '[]',
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'uuid'              => 'mismatched_location_cart_uuid',
            'public_id'         => 'mismatched_location_cart_public',
            'company_uuid'      => 'company_uuid',
            'unique_identifier' => 'mismatched-location-cart',
            'currency'          => 'USD',
            'items'             => json_encode([
                [
                    'store_id'          => 'store_different',
                    'store_location_id' => 'store_location_public',
                ],
            ]),
            'events'     => '[]',
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);
    session([
        'company'        => 'company_uuid',
        'storefront_key' => 'network_public',
    ]);
    $scopedLocation = Fleetbase\Storefront\Models\StoreLocation::where('public_id', 'store_location_public')
        ->whereHas('store.networks', fn ($query) => $query->where('network_uuid', session('storefront_network')))
        ->with('store')
        ->first();
    $scopedStore = Fleetbase\Storefront\Models\Store::where('public_id', 'store_public')
        ->whereHas('networks', fn ($query) => $query->where('network_uuid', session('storefront_network')))
        ->with('locations')
        ->first();
    expect(session('storefront_network'))->toBe('network_uuid')
        ->and($scopedLocation?->store?->public_id)->toBe('store_public')
        ->and(data_get($scopedStore, 'locations.0.public_id'))->toBe('store_location_public');
    $controller = new class extends ServiceQuoteController {
        public function getPlaceFromId(string|array $id): ?Fleetbase\FleetOps\Models\Place
        {
            $place = new Fleetbase\FleetOps\Models\Place();
            $place->forceFill(['uuid' => 'destination_uuid', 'public_id' => 'place_destination']);

            return $place;
        }
    };

    $explicit = $controller->fromCartForNetwork(GetServiceQuoteFromCart::create('/quote', 'POST', [
        'destination' => 'place_destination',
        'cart'        => 'explicit-cart',
        'facilitator' => 'integrated_vendor_missing',
    ]));
    $default = $controller->fromCartForNetwork(GetServiceQuoteFromCart::create('/quote', 'POST', [
        'destination' => 'place_destination',
        'cart'        => 'default-cart',
        'facilitator' => 'integrated_vendor_missing',
    ]));
    $missingLocation = $controller->fromCartForNetwork(GetServiceQuoteFromCart::create('/quote', 'POST', [
        'destination' => 'place_destination',
        'cart'        => 'missing-location-cart',
    ]));
    $mismatchedLocation = $controller->fromCartForNetwork(GetServiceQuoteFromCart::create('/quote', 'POST', [
        'destination' => 'place_destination',
        'cart'        => 'mismatched-location-cart',
    ]));

    expect($default->getData(true))->toBe(['error' => 'Integrated vendor not found!'])
        ->and($explicit->getData(true))->toBe(['error' => 'Integrated vendor not found!'])
        ->and($missingLocation->getStatusCode())->toBe(422)
        ->and($missingLocation->getData(true))->toBe([
            'error' => 'One or more store locations are unavailable for this marketplace.',
        ])
        ->and($mismatchedLocation->getStatusCode())->toBe(422)
        ->and($mismatchedLocation->getData(true))->toBe([
            'error' => 'One or more store locations are unavailable for this marketplace.',
        ]);
});

test('network service quote persists integrated facilitator origin metadata and provider errors', function () {
    createServiceQuoteLookupSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    seedServiceQuoteMarketplace();
    $connection->table('places')->insert([
        'uuid'      => 'origin_uuid',
        'public_id' => 'place_origin',
    ]);
    $connection->table('store_locations')->insert([
        'uuid'       => 'location_uuid',
        'public_id'  => 'store_location_public',
        'store_uuid' => 'store_uuid',
        'place_uuid' => 'origin_uuid',
    ]);
    $connection->table('carts')->insert([
        'uuid'              => 'cart_uuid',
        'public_id'         => 'cart_public',
        'company_uuid'      => 'company_uuid',
        'unique_identifier' => 'network-cart',
        'currency'          => 'USD',
        'items'             => json_encode([
            ['store_location_id' => 'store_location_public'],
        ]),
        'events'     => '[]',
        'expires_at' => now()->addHour(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $connection->table('integrated_vendors')->insert([
        'uuid'         => 'vendor_uuid',
        'public_id'    => 'integrated_vendor_public',
        'company_uuid' => 'company_uuid',
        'provider'     => 'provider_public',
    ]);
    $connection->table('service_quotes')->insert([
        'uuid'         => 'quote_uuid',
        'public_id'    => 'service_quote_public',
        'company_uuid' => 'company_uuid',
        'amount'       => 1800,
        'currency'     => 'USD',
        'meta'         => '{}',
    ]);
    session([
        'company'        => 'company_uuid',
        'storefront_key' => 'network_public',
    ]);

    ServiceQuoteProviderControllerStub::$quote   = Fleetbase\FleetOps\Models\ServiceQuote::where('uuid', 'quote_uuid')->firstOrFail();
    ServiceQuoteProviderControllerStub::$failure = null;
    $controller                                  = new ServiceQuoteProviderControllerStub();
    $requestData                                 = [
        'destination' => 'place_destination',
        'cart'        => 'network-cart',
        'facilitator' => 'provider_public',
    ];
    $controllerWithDestination = new class extends ServiceQuoteProviderControllerStub {
        public function getPlaceFromId(string|array $id): ?Fleetbase\FleetOps\Models\Place
        {
            $place = new Fleetbase\FleetOps\Models\Place();
            $place->forceFill(['uuid' => 'destination_uuid', 'public_id' => 'place_destination']);

            return $place;
        }
    };

    $resource  = $controllerWithDestination->fromCartForNetwork(GetServiceQuoteFromCart::create('/quote', 'POST', $requestData));
    $quoteMeta = Fleetbase\FleetOps\Models\ServiceQuote::where('uuid', 'quote_uuid')->firstOrFail()->meta;

    ServiceQuoteProviderControllerStub::$failure = new RuntimeException('Network provider unavailable');
    $failure                                     = $controllerWithDestination->fromCartForNetwork(GetServiceQuoteFromCart::create('/quote', 'POST', $requestData));
    ServiceQuoteProviderControllerStub::$failure = null;

    expect($resource)->toBeInstanceOf(Fleetbase\FleetOps\Http\Resources\v1\ServiceQuote::class)
        ->and($quoteMeta['origin'])->toBe(['place_origin'])
        ->and($quoteMeta['destination'])->toBe('place_destination')
        ->and(ServiceQuoteProviderControllerStub::$places)->toHaveCount(2)
        ->and($failure->getData(true))->toBe(['error' => 'Network provider unavailable']);
});

test('service quote returns a stable error when no rates or integrated providers serve the route', function () {
    createServiceQuoteLookupSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('carts')->insert([
        'uuid'              => 'cart_uuid',
        'public_id'         => 'cart_public',
        'unique_identifier' => 'browser-cart',
        'currency'          => 'USD',
        'items'             => json_encode([]),
        'events'            => json_encode([]),
        'expires_at'        => now()->addHour(),
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);
    config(['fleetops.distance_matrix.provider' => 'calculate']);
    session([
        'company'        => null,
        'storefront_key' => null,
    ]);
    $controller = new class extends ServiceQuoteController {
        public function getPlaceFromId(string|array $id): ?Fleetbase\FleetOps\Models\Place
        {
            $place = new Fleetbase\FleetOps\Models\Place();
            $place->forceFill([
                'uuid'      => is_array($id) ? 'place_origin' : (string) $id,
                'public_id' => is_array($id) ? 'place_origin' : (string) $id,
                'location'  => new Fleetbase\LaravelMysqlSpatial\Types\Point(47.918, 106.917),
            ]);

            return $place;
        }
    };

    $response = $controller->fromCart(GetServiceQuoteFromCart::create('/quote', 'POST', [
        'origin'      => 'place_origin',
        'destination' => 'place_destination',
        'cart'        => 'browser-cart',
    ]));

    expect($response->getData(true))->toBe(['error' => 'No service rates available!']);
});

test('service quote persists local rate lines and selects all matching and fallback currencies', function () {
    createServiceQuoteLookupSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('products')->insert([
        'uuid'         => 'product_uuid',
        'public_id'    => 'product_public',
        'company_uuid' => 'company_uuid',
        'name'         => 'Coffee',
        'description'  => 'Fresh coffee',
        'currency'     => 'USD',
        'sku'          => 'COFFEE-1',
        'price'        => 900,
        'sale_price'   => 800,
    ]);
    $connection->table('carts')->insert([
        'uuid'              => 'cart_uuid',
        'public_id'         => 'cart_public',
        'unique_identifier' => 'browser-cart',
        'currency'          => 'USD',
        'items'             => json_encode([
            ['product_id' => 'product_public'],
        ]),
        'events'            => '[]',
        'expires_at'        => now()->addHour(),
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);
    session([
        'company'        => null,
        'storefront_key' => null,
    ]);
    $controller = new class extends ServiceQuoteProviderControllerStub {
        public function getPlaceFromId(string|array $id): ?Fleetbase\FleetOps\Models\Place
        {
            $place = new Fleetbase\FleetOps\Models\Place();
            $place->forceFill(['uuid' => (string) $id, 'public_id' => (string) $id]);

            return $place;
        }
    };
    $rate = function (string $uuid, string $currency, int $amount): ServiceRateQuoteStub {
        $rate = new ServiceRateQuoteStub();
        $rate->forceFill([
            'uuid'         => $uuid,
            'company_uuid' => 'rate_company_uuid',
            'currency'     => $currency,
        ]);
        $rate->quotedAmount = $amount;

        return $rate;
    };
    ServiceQuoteProviderControllerStub::$serviceRates = [
        $rate('rate_usd_high', 'USD', 1500),
        $rate('rate_usd_low', 'USD', 1000),
        $rate('rate_eur', 'EUR', 500),
    ];
    $requestData = [
        'origin'      => 'place_origin',
        'destination' => 'place_destination',
        'cart'        => 'browser-cart',
    ];

    $all = $controller->fromCart(GetServiceQuoteFromCart::create('/quote', 'POST', [
        ...$requestData,
        'all' => true,
    ]));
    $matching = $controller->fromCart(GetServiceQuoteFromCart::create('/quote', 'POST', $requestData));
    $connection->table('carts')->where('uuid', 'cart_uuid')->update(['currency' => 'JPY']);
    $fallback                                         = $controller->fromCart(GetServiceQuoteFromCart::create('/quote', 'POST', $requestData));
    $capturedRate                                     = ServiceQuoteProviderControllerStub::$serviceRates[1];
    ServiceQuoteProviderControllerStub::$serviceRates = [];

    expect($all)->toBeInstanceOf(Fleetbase\Http\Resources\FleetbaseResourceCollection::class)
        ->and($all->collection)->toHaveCount(3)
        ->and($matching)->toBeInstanceOf(Fleetbase\FleetOps\Http\Resources\v1\ServiceQuote::class)
        ->and($matching->resource->amount)->toBe(1000)
        ->and($fallback->resource->amount)->toBe(500)
        ->and($capturedRate->quotedEntities[0]->name)->toBe('Coffee')
        ->and($capturedRate->quotedWaypoints)->toHaveCount(2)
        ->and($connection->table('service_quotes')->count())->toBe(9)
        ->and($connection->table('service_quote_items')->count())->toBe(9);
});

test('service quote falls back to an integrated provider when local rates are unavailable', function () {
    createServiceQuoteLookupSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('carts')->insert([
        'uuid'              => 'cart_uuid',
        'public_id'         => 'cart_public',
        'unique_identifier' => 'browser-cart',
        'currency'          => 'USD',
        'items'             => '[]',
        'events'            => '[]',
        'expires_at'        => now()->addHour(),
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);
    $connection->table('integrated_vendors')->insert([
        'uuid'         => 'vendor_uuid',
        'public_id'    => 'integrated_vendor_public',
        'company_uuid' => null,
        'provider'     => 'provider_public',
    ]);
    $connection->table('service_quotes')->insert([
        'uuid'         => 'quote_uuid',
        'public_id'    => 'service_quote_public',
        'company_uuid' => 'company_uuid',
        'amount'       => 1200,
        'currency'     => 'USD',
        'meta'         => '{}',
    ]);
    config(['fleetops.distance_matrix.provider' => 'calculate']);
    session([
        'company'        => null,
        'storefront_key' => null,
    ]);
    $controller = new class extends ServiceQuoteProviderControllerStub {
        public function getPlaceFromId(string|array $id): ?Fleetbase\FleetOps\Models\Place
        {
            $place = new Fleetbase\FleetOps\Models\Place();
            $place->forceFill([
                'uuid'      => (string) $id,
                'public_id' => (string) $id,
                'location'  => new Fleetbase\LaravelMysqlSpatial\Types\Point(47.918, 106.917),
            ]);

            return $place;
        }
    };
    ServiceQuoteProviderControllerStub::$quote   = Fleetbase\FleetOps\Models\ServiceQuote::where('uuid', 'quote_uuid')->firstOrFail();
    ServiceQuoteProviderControllerStub::$failure = null;
    $requestData                                 = [
        'origin'      => 'place_origin',
        'destination' => 'place_destination',
        'cart'        => 'browser-cart',
    ];

    $resource  = $controller->fromCart(GetServiceQuoteFromCart::create('/quote', 'POST', $requestData));
    $quoteMeta = Fleetbase\FleetOps\Models\ServiceQuote::where('uuid', 'quote_uuid')->firstOrFail()->meta;

    ServiceQuoteProviderControllerStub::$failure = new RuntimeException('Fallback provider unavailable');
    $failure                                     = $controller->fromCart(GetServiceQuoteFromCart::create('/quote', 'POST', $requestData));
    ServiceQuoteProviderControllerStub::$failure = null;

    expect($resource)->toBeInstanceOf(Fleetbase\FleetOps\Http\Resources\v1\ServiceQuote::class)
        ->and($quoteMeta['origin'])->toBe('place_origin')
        ->and($quoteMeta['destination'])->toBe('place_destination')
        ->and($failure->getData(true))->toBe(['error' => 'Fallback provider unavailable']);
});

test('network service quote resolves comma separated fallback origins before reporting no rates', function () {
    createServiceQuoteLookupSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    seedServiceQuoteMarketplace();
    $connection->table('places')->insert([
        ['uuid' => 'place_one_uuid', 'public_id' => 'place_one'],
        ['uuid' => 'place_two_uuid', 'public_id' => 'place_two'],
    ]);
    $connection->table('store_locations')->insert([
        [
            'uuid'       => 'location_one_uuid',
            'public_id'  => 'store_location_one',
            'store_uuid' => 'store_uuid',
            'place_uuid' => 'place_one_uuid',
        ],
        [
            'uuid'       => 'location_two_uuid',
            'public_id'  => 'store_location_two',
            'store_uuid' => 'store_uuid',
            'place_uuid' => 'place_two_uuid',
        ],
    ]);
    $connection->table('carts')->insert([
        'uuid'              => 'cart_uuid',
        'public_id'         => 'cart_public',
        'unique_identifier' => 'network-cart',
        'currency'          => 'USD',
        'items'             => json_encode([]),
        'events'            => json_encode([]),
        'expires_at'        => now()->addHour(),
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);
    config(['fleetops.distance_matrix.provider' => 'calculate']);
    session(['company' => null, 'storefront_key' => null]);
    $controller = new class extends ServiceQuoteController {
        public function getPlaceFromId(string|array $id): ?Fleetbase\FleetOps\Models\Place
        {
            $place = new Fleetbase\FleetOps\Models\Place();
            $place->forceFill([
                'uuid'      => 'destination_uuid',
                'public_id' => 'place_destination',
                'location'  => new Fleetbase\LaravelMysqlSpatial\Types\Point(47.918, 106.917),
            ]);

            return $place;
        }
    };

    $response = $controller->fromCartForNetwork(GetServiceQuoteFromCart::create('/quote', 'POST', [
        'origin'      => 'store_location_one,store_location_two',
        'destination' => 'place_destination',
        'cart'        => 'network-cart',
    ]));

    expect($response->getData(true))->toBe(['error' => 'No service rates available!']);
});

test('network service quote persists local rate lines and selects the lowest matching quote', function () {
    createServiceQuoteLookupSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    seedServiceQuoteMarketplace();
    $connection->table('products')->insert([
        'uuid'         => 'product_uuid',
        'public_id'    => 'product_public',
        'company_uuid' => 'company_uuid',
        'name'         => 'Coffee',
        'description'  => 'Fresh coffee',
        'currency'     => 'USD',
        'sku'          => 'COFFEE-1',
        'price'        => 900,
        'sale_price'   => 800,
    ]);
    $connection->table('places')->insert([
        'uuid'      => 'origin_uuid',
        'public_id' => 'place_origin',
    ]);
    $connection->table('store_locations')->insert([
        'uuid'       => 'location_uuid',
        'public_id'  => 'store_location_origin',
        'store_uuid' => 'store_uuid',
        'place_uuid' => 'origin_uuid',
    ]);
    $connection->table('carts')->insert([
        'uuid'              => 'cart_uuid',
        'public_id'         => 'cart_public',
        'unique_identifier' => 'network-cart',
        'currency'          => 'USD',
        'items'             => json_encode([
            ['product_id' => 'product_public'],
        ]),
        'events'            => '[]',
        'expires_at'        => now()->addHour(),
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);
    session([
        'company'        => null,
        'storefront_key' => null,
    ]);
    $controller = new class extends ServiceQuoteProviderControllerStub {
        public function getPlaceFromId(string|array $id): ?Fleetbase\FleetOps\Models\Place
        {
            $place = new Fleetbase\FleetOps\Models\Place();
            $place->forceFill(['uuid' => 'destination_uuid', 'public_id' => 'place_destination']);

            return $place;
        }
    };
    $high = new ServiceRateQuoteStub();
    $high->forceFill(['uuid' => 'rate_high', 'company_uuid' => 'company_uuid', 'currency' => 'USD']);
    $high->quotedAmount = 2200;
    $low                = new ServiceRateQuoteStub();
    $low->forceFill(['uuid' => 'rate_low', 'company_uuid' => 'company_uuid', 'currency' => 'USD']);
    $low->quotedAmount                                = 1700;
    ServiceQuoteProviderControllerStub::$serviceRates = [$high, $low];
    $requestData                                      = [
        'origin'      => 'store_location_origin',
        'destination' => 'place_destination',
        'cart'        => 'network-cart',
    ];

    $matching = $controller->fromCartForNetwork(GetServiceQuoteFromCart::create('/quote', 'POST', $requestData));
    $all      = $controller->fromCartForNetwork(GetServiceQuoteFromCart::create('/quote', 'POST', [
        ...$requestData,
        'all' => true,
    ]));
    $connection->table('carts')->where('uuid', 'cart_uuid')->update(['currency' => 'JPY']);
    $fallback                                         = $controller->fromCartForNetwork(GetServiceQuoteFromCart::create('/quote', 'POST', $requestData));
    ServiceQuoteProviderControllerStub::$serviceRates = [];

    expect($matching)->toBeInstanceOf(Fleetbase\FleetOps\Http\Resources\v1\ServiceQuote::class)
        ->and($matching->resource->amount)->toBe(1700)
        ->and($all)->toBeInstanceOf(Fleetbase\Http\Resources\FleetbaseResourceCollection::class)
        ->and($all->collection)->toHaveCount(2)
        ->and($fallback->resource->amount)->toBe(1700)
        ->and($low->quotedEntities[0]->name)->toBe('Coffee')
        ->and($low->quotedWaypoints)->toHaveCount(2)
        ->and($connection->table('service_quotes')->count())->toBe(6)
        ->and($connection->table('service_quote_items')->count())->toBe(6);
});

test('network service quote falls back to an integrated provider when local rates are unavailable', function () {
    createServiceQuoteLookupSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    seedServiceQuoteMarketplace();
    $connection->table('places')->insert([
        'uuid'      => 'origin_uuid',
        'public_id' => 'place_origin',
    ]);
    $connection->table('store_locations')->insert([
        'uuid'       => 'location_uuid',
        'public_id'  => 'store_location_origin',
        'store_uuid' => 'store_uuid',
        'place_uuid' => 'origin_uuid',
    ]);
    $connection->table('carts')->insert([
        'uuid'              => 'cart_uuid',
        'public_id'         => 'cart_public',
        'unique_identifier' => 'network-cart',
        'currency'          => 'USD',
        'items'             => '[]',
        'events'            => '[]',
        'expires_at'        => now()->addHour(),
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);
    $connection->table('integrated_vendors')->insert([
        'uuid'         => 'vendor_uuid',
        'public_id'    => 'integrated_vendor_public',
        'company_uuid' => null,
        'provider'     => 'provider_public',
    ]);
    $connection->table('service_quotes')->insert([
        'uuid'         => 'quote_uuid',
        'public_id'    => 'service_quote_public',
        'company_uuid' => 'company_uuid',
        'amount'       => 1800,
        'currency'     => 'USD',
        'meta'         => '{}',
    ]);
    config(['fleetops.distance_matrix.provider' => 'calculate']);
    session([
        'company'        => null,
        'storefront_key' => null,
    ]);
    $controller = new class extends ServiceQuoteProviderControllerStub {
        public function getPlaceFromId(string|array $id): ?Fleetbase\FleetOps\Models\Place
        {
            $place = new Fleetbase\FleetOps\Models\Place();
            $place->forceFill([
                'uuid'      => 'destination_uuid',
                'public_id' => 'place_destination',
                'location'  => new Fleetbase\LaravelMysqlSpatial\Types\Point(47.918, 106.917),
            ]);

            return $place;
        }
    };
    ServiceQuoteProviderControllerStub::$quote   = Fleetbase\FleetOps\Models\ServiceQuote::where('uuid', 'quote_uuid')->firstOrFail();
    ServiceQuoteProviderControllerStub::$failure = null;
    $requestData                                 = [
        'origin'      => 'store_location_origin',
        'destination' => 'place_destination',
        'cart'        => 'network-cart',
    ];

    $resource  = $controller->fromCartForNetwork(GetServiceQuoteFromCart::create('/quote', 'POST', $requestData));
    $quoteMeta = Fleetbase\FleetOps\Models\ServiceQuote::where('uuid', 'quote_uuid')->firstOrFail()->meta;

    ServiceQuoteProviderControllerStub::$failure = new RuntimeException('Network fallback unavailable');
    $failure                                     = $controller->fromCartForNetwork(GetServiceQuoteFromCart::create('/quote', 'POST', $requestData));
    ServiceQuoteProviderControllerStub::$failure = null;

    expect($resource)->toBeInstanceOf(Fleetbase\FleetOps\Http\Resources\v1\ServiceQuote::class)
        ->and($quoteMeta['origin'])->toBe(['place_origin'])
        ->and($quoteMeta['destination'])->toBe('place_destination')
        ->and($failure->getData(true))->toBe(['error' => 'Network fallback unavailable']);
});
