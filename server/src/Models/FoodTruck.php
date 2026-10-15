<?php

namespace Fleetbase\Storefront\Models;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Zone;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Storefront\Http\Resources\FoodTruck as FoodTruckResource;
use Fleetbase\Support\ApiModelCache;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasApiModelCache;
use Fleetbase\Traits\HasPublicid;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class FoodTruck extends StorefrontModel
{
    use HasUuid;
    use HasPublicid;
    use HasApiModelBehavior;
    use HasApiModelCache;
    use SoftDeletes;

    /**
     * The default database connection to use.
     *
     * @var string
     */
    protected $connection = 'storefront';

    protected $resource = FoodTruckResource::class;

    /**
     * The type of public ID to generate.
     *
     * @var string
     */
    protected $publicIdType = 'food_truck';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'food_trucks';

    /**
     * Tables the cached food truck lists embed, so writes to them invalidate those lists
     * (read by core's ApiModelCache from fleetbase/core-api#301 on): the relations the
     * console and storefront lists eager load (a service area loads its zones), and the
     * store's networks, which decide the trucks a network lists.
     *
     * @var string[]
     */
    protected $apiCacheDependencies = ['store', 'store.networks', 'network', 'vehicle', 'vehicle.driver', 'serviceArea', 'serviceArea.zones', 'zone', 'catalogs'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'uuid',
        'vehicle_uuid',
        'store_uuid',
        'network_uuid',
        'company_uuid',
        'created_by_uuid',
        'service_area_uuid',
        'zone_uuid',
        'status',
    ];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = ['location'];

    /**
     * Get the store that owns this food truck.
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_uuid', 'uuid');
    }

    /**
     * The network that owns this truck, when it is not a store's.
     */
    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class, 'network_uuid', 'uuid');
    }

    /**
     * Whoever runs the truck: its network, otherwise its store.
     */
    public function owner(): Store|Network|null
    {
        return $this->network_uuid ? $this->network : $this->store;
    }

    /**
     * Get the vehicle that is the food truck.
     */
    public function vehicle(): BelongsTo
    {
        return $this->setConnection(config('fleetbase.connection.db'))->belongsTo(Vehicle::class, 'vehicle_uuid', 'uuid')->withTrashed();
    }

    /**
     * Get the service area the food truck is assigned to.
     */
    public function serviceArea(): BelongsTo
    {
        return $this->setConnection(config('fleetbase.connection.db'))->belongsTo(ServiceArea::class, 'service_area_uuid', 'uuid');
    }

    /**
     * Get the zone the food truck is assigned to.
     */
    public function zone(): BelongsTo
    {
        return $this->setConnection(config('fleetbase.connection.db'))->belongsTo(Zone::class, 'zone_uuid', 'uuid');
    }

    /**
     * Polymorphic relationship to the CatalogSubject pivot.
     * This allows you to retrieve all catalogs assigned to this food truck.
     */
    public function catalogAssignments(): MorphMany
    {
        return $this->morphMany(CatalogSubject::class, 'subject', 'subject_type', 'subject_uuid');
    }

    /**
     * The catalogs assigned to this food truck.
     */
    public function catalogs(): MorphToMany
    {
        return $this->morphToMany(
            Catalog::class,
            'subject',
            'catalog_subjects',
            'subject_uuid',
            'catalog_uuid',
        )->using(CatalogSubject::class)
        ->withTimestamps()
        ->wherePivotNull('deleted_at');
    }

    /**
     * Sync the given catalogs to this food truck by creating or deleting pivot rows
     * in catalog_subjects. If pivot rows are polymorphic, also filter by subject_type.
     *
     * @return $this
     */
    public function setCatalogs(array $catalogs = []): FoodTruck
    {
        // Ensure 'catalogs' relationship is loaded
        $this->loadMissing('catalogs');

        // Collect existing catalogs
        $existingCatalogs = $this->catalogs;

        // Extract incoming UUIDs
        $incomingUuids = collect($catalogs)
        ->map(function ($item) {
            // If it's already a Catalog model, use its uuid
            if ($item instanceof Catalog) {
                return $item->uuid;
            }
            // If it's a string UUID, return as is
            if (is_string($item) && Str::isUuid($item)) {
                return $item;
            }

            // If it's an array/object with a 'uuid' key, return that
            return data_get($item, 'uuid');
        })
        ->filter(fn ($uuid) => Str::isUuid($uuid))
        ->unique()
        ->values();

        // Remove pivot rows for catalogs not in incoming list
        $removed = $existingCatalogs->whereNotIn('uuid', $incomingUuids);
        $removed->each(function (Catalog $catalog) {
            CatalogSubject::where([
                'catalog_uuid' => $catalog->uuid,
                'subject_uuid' => $this->uuid,
                'subject_type' => get_class($this),
            ])->delete();
        });

        // Create or restore pivot rows for each incoming
        $added = 0;
        foreach ($incomingUuids as $catalogUuid) {
            $subject = CatalogSubject::firstOrCreate(
                [
                    'catalog_uuid'  => $catalogUuid,
                    'subject_uuid'  => $this->uuid,
                    'subject_type'  => get_class($this),
                ],
                [
                    'company_uuid'    => $this->company_uuid,
                    'created_by_uuid' => session('user'),
                ]
            );
            $added += $subject->wasRecentlyCreated ? 1 : 0;
        }

        // The relation was loaded before the sync; reload it so the saved truck answers with its new catalogs.
        $this->load('catalogs');

        // Only the pivot changed, so the truck fires no `updated` event; clear the cached truck lists here.
        if (($removed->isNotEmpty() || $added > 0) && method_exists($this, 'invalidateApiCache')) {
            $this->invalidateApiCache();
        }

        // Removed assignments were deleted without model events; the catalog lists embed them too.
        if ($removed->isNotEmpty()) {
            ApiModelCache::invalidateModelCache(new CatalogSubject(), $this->company_uuid);
        }

        return $this;
    }

    public function getLocationAttribute(): ?Point
    {
        $this->loadMissing('vehicle');

        return $this->vehicle->location;
    }

    /**
     * What the truck did today in its owner's timezone: orders fulfilled from it (the checkout
     * stamps `meta.food_truck_id`), what they came to, and how long they took to leave the truck.
     */
    public function todayStats(): array
    {
        $owner    = $this->owner();
        $timezone = $owner?->timezone ?: config('app.timezone', 'UTC');
        $currency = $owner?->currency;
        $start    = Carbon::now($timezone)->startOfDay()->setTimezone('UTC');
        $end      = Carbon::now($timezone)->endOfDay()->setTimezone('UTC');

        $row = Order::query()
            ->where('company_uuid', $this->company_uuid)
            ->where('meta->food_truck_id', $this->public_id)
            ->whereBetween('created_at', [$start, $end])
            ->whereNull('deleted_at')
            ->selectRaw("COUNT(*) as orders_count, COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.total')) AS DECIMAL(14,2))), 0) as revenue, AVG(TIMESTAMPDIFF(MINUTE, created_at, COALESCE(dispatched_at, started_at))) as avg_prep_minutes")
            ->first();

        return [
            'orders'           => (int) ($row->orders_count ?? 0),
            'revenue'          => (float) ($row->revenue ?? 0),
            'currency'         => $currency,
            'avg_prep_minutes' => $row && $row->avg_prep_minutes !== null ? (int) round((float) $row->avg_prep_minutes) : null,
        ];
    }

    /**
     * Get the driver assigned via the food truck relation.
     */
    public function getDriverAssigned(): ?Driver
    {
        // Ensure both relations are present without re-querying if already loaded
        $this->loadMissing('vehicle.driver');

        return $this->vehicle?->driver;
    }

    /**
     * What an order list or panel shows of the truck an order was placed at: its public id and the
     * name the console gives it (its vehicle's). A company's trucks are looked up once per request,
     * so a page of orders costs one query.
     *
     * @return array{id: string, name: ?string}
     */
    public static function summaryFor(string $publicId, ?string $companyUuid): array
    {
        $request = request();
        $names   = $request->attributes->get('storefront.food_truck_names', []);
        $key     = (string) $companyUuid;

        if (!array_key_exists($key, $names)) {
            $names[$key] = static::where('company_uuid', $companyUuid)
                ->with('vehicle')
                ->get()
                ->mapWithKeys(fn (FoodTruck $truck) => [$truck->public_id => data_get($truck, 'vehicle.display_name')])
                ->all();
            $request->attributes->set('storefront.food_truck_names', $names);
        }

        return ['id' => $publicId, 'name' => $names[$key][$publicId] ?? null];
    }
}
