<?php

namespace Fleetbase\Storefront\Support;

use Fleetbase\FleetOps\Flow\Activity;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Fleetbase\Storefront\Models\Gateway;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\NotificationChannel;
use Fleetbase\Storefront\Models\Product;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Notifications\StorefrontOrderAccepted;
use Fleetbase\Storefront\Notifications\StorefrontOrderCreated;
use Fleetbase\Support\Auth;
use Fleetbase\Support\Utils;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class Storefront
{
    /** @var string */
    private const CONFIG_KEY = 'storefront';
    /** @var string */
    private const CONFIG_NS  = 'system:order-config:storefront';
    /** @var array<string,OrderConfig> In-memory cache keyed by company UUID */
    private static array $configCache = [];

    /**
     * Returns current store or network based on session `storefront_key`
     * with bare minimum columns, but can optionally pass in more columns to receive.
     *
     * @param array $columns
     */
    public static function about($columns = [], $with = []): Store|Network|null
    {
        $key = session('storefront_key');

        if (!$key) {
            return null;
        }

        if (is_array($columns)) {
            $columns = array_merge(['uuid', 'public_id', 'company_uuid', 'backdrop_uuid', 'logo_uuid', 'order_config_uuid', 'name', 'description', 'translations', 'website', 'facebook', 'instagram', 'twitter', 'email', 'phone', 'tags', 'currency', 'timezone', 'pod_method', 'options'], $columns);
        }

        if (Str::startsWith($key, 'store')) {
            $about = Store::select($columns)->where('key', $key)->with($with)->first();
        } else {
            $about = Network::select($columns)->where('key', $key)->with($with)->first();
        }

        $about->is_store   = Str::startsWith($key, 'store');
        $about->is_network = Str::startsWith($key, 'network');

        return $about;
    }

    /**
     * Returns current store or network based ID param passed
     * with bare minimum columns, but can optionally pass in more columns to receive.
     *
     * @param array $columns
     */
    public static function findAbout($id, $columns = [], $with = []): Store|Network|null
    {
        if (is_array($columns)) {
            $columns = array_merge(['uuid', 'public_id', 'company_uuid', 'backdrop_uuid', 'logo_uuid', 'order_config_uuid', 'name', 'description', 'translations', 'website', 'facebook', 'instagram', 'twitter', 'email', 'phone', 'tags', 'currency', 'timezone', 'pod_method', 'options'], $columns);
        }

        if (Str::startsWith($id, 'store')) {
            $about = Store::select($columns)->where('public_id', $id)->with($with)->first();
        } else {
            $about = Network::select($columns)->where('public_id', $id)->with($with)->first();
        }

        if (!$about) {
            return $about;
        }

        $about->is_store   = Str::startsWith($id, 'store');
        $about->is_network = Str::startsWith($id, 'network');

        return $about;
    }

    public static function getStoreFromLocation(string $id, $columns = [], $with = [])
    {
        if (is_array($columns)) {
            $columns = array_merge(['uuid', 'public_id', 'company_uuid', 'backdrop_uuid', 'logo_uuid', 'name', 'description', 'translations', 'website', 'facebook', 'instagram', 'twitter', 'email', 'phone', 'tags', 'currency', 'timezone', 'pod_method', 'options'], $columns);
        }

        return Store::select($columns)->with($with)->whereHas('locations', function ($q) use ($id) {
            $q->where('place_uuid', $id);
            $q->orWhereHas('place', function ($q) use ($id) {
                $q->where('public_id', $id);
            });
        })->first();
    }

    public static function getCustomerFromToken()
    {
        $token = request()->header('Customer-Token');

        if ($token) {
            $accessToken = PersonalAccessToken::findToken($token);

            if ($accessToken && Str::isUuid($accessToken->name)) {
                $customer = Contact::where('uuid', $accessToken->name)->first();

                if ($customer) {
                    return $customer;
                }
            }

            if ($accessToken) {
                return Contact::where('user_uuid', $accessToken->tokenable->uuid)->first();
            }
        }

        return null;
    }

    public static function findGateway(string $code): ?Gateway
    {
        if ($code === 'cash') {
            return Gateway::cash();
        }

        return Gateway::where([
            'code'       => $code,
            'owner_uuid' => session('storefront_store') ?? session('storefront_network'),
        ])->first();
    }

    public static function getFullDescriptionFromCartItem($cartItem)
    {
        $fullDescription = $cartItem->name;

        if (is_array($cartItem->variants) && count($cartItem->variants)) {
            $fullDescription .= ' with Variation: ';
            $fullDescription .= collect($cartItem->variants)->pluck('name')->join(',');
        }

        if (is_array($cartItem->addons) && count($cartItem->addons)) {
            $fullDescription .= ' with Addons: ';
            $fullDescription .= collect($cartItem->addons)->pluck('name')->join(',');
        }

        return $fullDescription;
    }

    public static function destroyCart($cartId)
    {
        $prefix = 'cart:' . session('storefront_store') . ':';

        return Redis::del($prefix . $cartId);
    }

    public static function getProduct($publicId)
    {
        return Product::select(['uuid', 'public_id', 'name', 'description', 'price', 'sale_price', 'is_on_sale'])->where(['public_id' => $publicId])->with([])->first();
    }

    public static function alertNewOrder(Order $order, $sendNow = false)
    {
        $about      = static::about(['alertable']);
        $alertables = [];

        if ($about->is_network) {
            $store = static::findAbout($order->getMeta('storefront_id'), ['alertable']);

            if ($store && $store->public_id !== $about->public_id) {
                $merge      = Utils::get($store, 'alertable.for_new_order', []);
                $alertables = array_merge($alertables, $merge);
            }
        }

        $merge      = Utils::get($about, 'alertable.for_new_order', []);
        $alertables = array_merge($alertables, $merge);
        $users      = collect($alertables)->map(function ($id) {
            return User::where('public_id', $id)->first();
        });

        if ($users->isEmpty()) {
            return;
        }

        if ($sendNow) {
            return Notification::sendNow($users, new StorefrontOrderCreated($order));
        }

        return Notification::send($users, new StorefrontOrderCreated($order));
    }

    public static function createStripeCustomerForContact(Contact &$customer)
    {
        $stripeCustomer = \Stripe\Customer::create([
            'description' => 'Customer created in Fleetbase Storefront',
            'email'       => $customer->email,
            'name'        => $customer->name,
            'phone'       => $customer->phone,
            'metadata'    => [
                'contact_id'    => $customer->public_id,
                'storefront_id' => session('storefront_store') ?? session('storefront_network'),
                'company_id'    => session('company'),
            ],
        ]);

        // set the stripe customer to customer meta
        $customer->updateMeta('stripe_id', $stripeCustomer->id);

        return $stripeCustomer;
    }

    /**
     * Ensure an Order has an effective OrderConfig.
     * - If already set (FK or relation), return it.
     * - Else resolve default for the order's company and patch the FK quietly.
     *
     * @param \Fleetbase\Storefront\Models\Order $order
     *
     * @return \Fleetbase\Storefront\Models\OrderConfig|null
     */
    public static function patchOrderConfig($order): ?OrderConfig
    {
        // FK fast-path
        if (!empty($order->order_config_uuid)) {
            return OrderConfig::where('uuid', $order->order_config_uuid)->first();
        }

        // Relation fast-path (if your Order has relation 'orderConfig')
        $order->loadMissing('orderConfig');
        if ($order->orderConfig instanceof OrderConfig) {
            return $order->orderConfig;
        }

        // Resolve by company
        $companyUuid = $order->company_uuid ?? ($order->company->uuid ?? null);
        $orderConfig = static::getOrderConfig($companyUuid);

        if ($orderConfig) {
            // Quiet write to avoid events, if preferred
            $order->forceFill(['order_config_uuid' => $orderConfig->uuid]);
            method_exists($order, 'saveQuietly') ? $order->saveQuietly() : $order->save();
        }

        return $orderConfig;
    }

    /**
     * Get the current store or networks order config.
     *
     * @return \Fleetbase\Storefront\Models\OrderConfig|null
     */
    public static function getSessionOrderConfig(): ?OrderConfig
    {
        if ($about = static::about()) {
            $about->loadMissing('orderConfig');

            return $about->orderConfig ?? static::getDefaultOrderConfig();
        }

        return static::getDefaultOrderConfig();
    }

    /**
     * Get the default Storefront OrderConfig for the "current" company context.
     *
     * @return \Fleetbase\Storefront\Models\OrderConfig|null
     */
    public static function getDefaultOrderConfig(): ?OrderConfig
    {
        $company = session('company') ?? (method_exists(Auth::class, 'getCompany') ? Auth::getCompany() : null);

        return static::getOrderConfig($company);
    }

    /**
     * Get (or lazily create) the Storefront OrderConfig for a company.
     * Accepts Company model, UUID string, or null (falls back to session company).
     *
     * @return \Fleetbase\Storefront\Models\OrderConfig|null
     */
    public static function getOrderConfig(Company|string|null $company): ?OrderConfig
    {
        $companyUuid = static::resolveCompanyUuid($company);
        if (!$companyUuid) {
            return null;
        }

        // Request-lifetime cache
        if (isset(static::$configCache[$companyUuid])) {
            return static::$configCache[$companyUuid];
        }

        $attrs = [
            'company_uuid' => $companyUuid,
            'key'          => self::CONFIG_KEY,
            'namespace'    => self::CONFIG_NS,
        ];

        $config = OrderConfig::where($attrs)->first();
        if (!$config) {
            // Short transaction to avoid dupes under race
            $config = DB::transaction(function () use ($attrs, $companyUuid) {
                $existing = OrderConfig::where($attrs)->first();
                if ($existing) {
                    return $existing;
                }

                return static::createStorefrontConfig($companyUuid);
            });
        }

        return static::$configCache[$companyUuid] = $config;
    }

    /**
     * Create (or retrieve) the Storefront config for a company.
     * Accepts Company model or UUID string.
     *
     * @return \Fleetbase\Storefront\Models\OrderConfig
     */
    public static function createStorefrontConfig(Company|string $company): OrderConfig
    {
        $companyUuid = $company instanceof Company ? $company->uuid : $company;

        return OrderConfig::firstOrCreate(
            [
                'company_uuid' => $companyUuid,
                'key'          => 'storefront',
                'namespace'    => 'system:order-config:storefront',
            ],
            [
                'name'         => 'Storefront',
                'key'          => 'storefront',
                'namespace'    => 'system:order-config:storefront',
                'description'  => 'Storefront order configuration for hyperlocal delivery and pickup',
                'core_service' => 1,
                'status'       => 'private',
                'version'      => '0.0.1',
                'tags'         => ['storefront', 'ecommerce', 'hyperlocal'],
                'entities'     => [],
                'meta'         => [],
                'flow'         => [
                    'created' => [
                        'key'         => 'created',
                        'code'        => 'created',
                        'color'       => '#1f2937',
                        'logic'       => [],
                        'events'      => [],
                        'status'      => 'Order Created',
                        'actions'     => [],
                        'details'     => 'New order was created.',
                        'options'     => [],
                        'complete'    => false,
                        'entities'    => [],
                        'sequence'    => 0,
                        'activities'  => ['dispatched'],
                        'internalId'  => Str::uuid(),
                        'pod_method'  => 'scan',
                        'require_pod' => false,
                    ],
                    'started' => [
                        'key'         => 'started',
                        'code'        => 'started',
                        'color'       => '#1f2937',
                        'logic'       => [],
                        'events'      => [],
                        'status'      => 'Order Started',
                        'actions'     => [],
                        'details'     => 'Order has been started',
                        'options'     => [],
                        'complete'    => false,
                        'entities'    => [],
                        'sequence'    => 0,
                        'activities'  => ['canceled', 'preparing'],
                        'internalId'  => Str::uuid(),
                        'pod_method'  => 'scan',
                        'require_pod' => false,
                    ],
                    'canceled' => [
                        'key'         => 'canceled',
                        'code'        => 'canceled',
                        'color'       => '#1f2937',
                        'logic'       => [],
                        'events'      => ['order.canceled'],
                        'status'      => 'Order canceled',
                        'actions'     => [],
                        'details'     => 'Order could not be accepted',
                        'options'     => [],
                        'complete'    => false,
                        'entities'    => [],
                        'sequence'    => 0,
                        'activities'  => [],
                        'internalId'  => Str::uuid(),
                        'pod_method'  => 'scan',
                        'require_pod' => false,
                    ],
                    'completed' => [
                        'key'         => 'completed',
                        'code'        => 'completed',
                        'color'       => '#1f2937',
                        'logic'       => [],
                        'events'      => [],
                        'status'      => 'Order completed',
                        'actions'     => [],
                        'details'     => 'Driver has completed the order',
                        'options'     => [],
                        'complete'    => true,
                        'entities'    => [],
                        'sequence'    => 0,
                        'activities'  => [],
                        'internalId'  => Str::uuid(),
                        'pod_method'  => 'scan',
                        'require_pod' => false,
                    ],
                    'picked_up' => [
                        'key'         => 'picked_up',
                        'code'        => 'picked_up',
                        'color'       => '#1f2937',
                        'logic'       => [],
                        'events'      => [],
                        'status'      => 'Order picked up',
                        'actions'     => [],
                        'details'     => 'Order has been picked up by customer',
                        'options'     => [],
                        'complete'    => true,
                        'entities'    => [],
                        'sequence'    => 0,
                        'activities'  => [],
                        'internalId'  => Str::uuid(),
                        'pod_method'  => 'scan',
                        'require_pod' => false,
                    ],
                    'preparing' => [
                        'key'         => 'preparing',
                        'code'        => 'preparing',
                        'color'       => '#1f2937',
                        'logic'       => [],
                        'events'      => [],
                        'status'      => 'Order is being prepared',
                        'actions'     => [],
                        'details'     => 'Order has been received by {storefront.name} and is being prepared',
                        'options'     => [],
                        'complete'    => false,
                        'entities'    => [],
                        'sequence'    => 0,
                        'activities'  => ['driver_enroute_to_store', 'pickup_ready'],
                        'internalId'  => Str::uuid(),
                        'pod_method'  => 'scan',
                        'require_pod' => false,
                    ],
                    'dispatched' => [
                        'key'         => 'dispatched',
                        'code'        => 'dispatched',
                        'color'       => '#1f2937',
                        'logic'       => [],
                        'events'      => [],
                        'status'      => 'Order Dispatched',
                        'actions'     => [],
                        'details'     => 'Order has been dispatched.',
                        'options'     => [],
                        'complete'    => false,
                        'entities'    => [],
                        'sequence'    => 0,
                        'activities'  => ['started'],
                        'internalId'  => Str::uuid(),
                        'pod_method'  => 'scan',
                        'require_pod' => false,
                    ],
                    'pickup_ready' => [
                        'key'   => 'ready',
                        'code'  => 'pickup_ready',
                        'color' => '#1f2937',
                        'logic' => [
                            [
                                'type'       => 'if',
                                'conditions' => [
                                    [
                                        'field'    => 'meta.is_pickup',
                                        'value'    => 'true',
                                        'operator' => 'equal',
                                    ],
                                ],
                            ],
                        ],
                        'events'      => [],
                        'status'      => 'Order is ready for pickup',
                        'actions'     => [],
                        'details'     => 'Order is ready to be picked up by customer',
                        'options'     => [],
                        'complete'    => false,
                        'entities'    => [],
                        'sequence'    => 0,
                        'activities'  => ['picked_up'],
                        'internalId'  => Str::uuid(),
                        'pod_method'  => 'scan',
                        'require_pod' => false,
                    ],
                    'driver_enroute' => [
                        'key'         => 'driver_enroute',
                        'code'        => 'driver_enroute',
                        'color'       => '#1f2937',
                        'logic'       => [],
                        'events'      => [],
                        'status'      => 'Driver en-route',
                        'actions'     => [],
                        'details'     => 'Driver is on the way to the customer',
                        'options'     => [],
                        'complete'    => false,
                        'entities'    => [],
                        'sequence'    => 0,
                        'activities'  => ['completed'],
                        'internalId'  => Str::uuid(),
                        'pod_method'  => 'scan',
                        'require_pod' => false,
                    ],
                    'driver_picked_up' => [
                        'key'         => 'driver_picked_up',
                        'code'        => 'driver_picked_up',
                        'color'       => '#1f2937',
                        'logic'       => [],
                        'events'      => [],
                        'status'      => 'Driver picked up',
                        'actions'     => [],
                        'details'     => 'Driver has picked up order',
                        'options'     => [],
                        'complete'    => false,
                        'entities'    => [],
                        'sequence'    => 0,
                        'activities'  => ['driver_enroute'],
                        'internalId'  => Str::uuid(),
                        'pod_method'  => 'scan',
                        'require_pod' => false,
                    ],
                    'driver_enroute_to_store' => [
                        'key'         => 'driver_enroute',
                        'code'        => 'driver_enroute_to_store',
                        'color'       => '#1f2937',
                        'logic'       => [],
                        'events'      => [],
                        'status'      => 'Driver en-route',
                        'actions'     => [],
                        'details'     => 'Driver en-route to store',
                        'options'     => [],
                        'complete'    => false,
                        'entities'    => [],
                        'sequence'    => 0,
                        'activities'  => ['driver_picked_up'],
                        'internalId'  => Str::uuid(),
                        'pod_method'  => 'scan',
                        'require_pod' => false,
                    ],
                ],
            ]
        );
    }

    /**
     * Normalize a Company|UUID|null to a UUID string (or null if unresolved).
     */
    protected static function resolveCompanyUuid(Company|string|null $company): ?string
    {
        if ($company instanceof Company && !empty($company->uuid)) {
            return (string) $company->uuid;
        }
        if (is_string($company) && $company !== '' && (method_exists(Str::class, 'isUuid') ? Str::isUuid($company) : true)) {
            return $company;
        }
        $sessionCompany = session('company');

        return !empty($sessionCompany) ? (string) $sessionCompany : null;
    }

    public static function createAcceptedActivity(?OrderConfig $orderConfig = null): Activity
    {
        return new Activity([
            'key'         => 'accepted',
            'code'        => 'accepted',
            'color'       => '#1f2937',
            'logic'       => [],
            'events'      => [],
            'status'      => 'Order has been accepted',
            'actions'     => [],
            'details'     => 'Order has been accepted by {storefront.name}',
            'options'     => [],
            'complete'    => false,
            'entities'    => [],
            'sequence'    => 0,
            'activities'  => ['dispatched'],
            'internalId'  => Str::uuid(),
            'pod_method'  => 'scan',
            'require_pod' => false,
        ], $orderConfig ? $orderConfig->activities()->toArray() : []);
    }

    public static function autoAcceptOrder(Order $order)
    {
        // Patch order config
        $orderConfig = static::patchOrderConfig($order);
        $activity    = static::createAcceptedActivity($orderConfig);

        // Dispatch already if order is a pickup
        if ($order->isMeta('is_pickup')) {
            $order->firstDispatchWithActivity();
        }

        // Set order as accepted
        try {
            $order->setStatus($activity->code);
            $order->insertActivity($activity, $order->getLastLocation());
        } catch (\Exception $e) {
            Log::debug('[Storefront] was unable to accept an order.', ['order' => $order, 'activity' => $activity]);

            return response()->error('Unable to accept order.');
        }

        // Notify customer order was accepted
        try {
            $order->customer->notify(new StorefrontOrderAccepted($order));
        } catch (\Exception $e) {
        }

        return $order;
    }

    public static function autoDispatchOrder(Order $order, bool $adhoc = true)
    {
        // Patch order config
        Storefront::patchOrderConfig($order);

        if ($order->isMeta('is_pickup')) {
            $order->updateStatus('pickup_ready');

            return $order;
        }

        // toggle order to adhoc
        if ($adhoc === true) {
            $order->update(['adhoc' => true]);
        } else {
            // Find nearest driver and assign
            $driver = $order->findClosestDrivers()->first();
            if ($driver) {
                $order->assignDriver($driver);
            } else {
                // no driver available to make adhoc
                $order->update(['adhoc' => true]);
            }
        }

        $order->dispatchWithActivity();

        return $order;
    }

    /**
     * Determine whether the given Store or Network has a configured notification channel.
     *
     * Accepts either a model instance (Store|Network) or an identifier string.
     * When a string is provided, this method attempts to resolve it in the following order:
     *   1) UUID        → `where('uuid', $id)`
     *   2) public_id   → `where('public_id', $id)` (if your models use a public_id column)
     *
     * If resolution fails, the method returns false.
     *
     * @param Store|\Fleetbase\FleetOps\Models\Network|string|null $subject
     *                                                                      Store/Network model instance or identifier (uuid/public_id)
     * @param string                                               $channel Channel scheme/key (e.g., "email", "sms", "fcm").
     *
     * @return bool true if a NotificationChannel exists for the subject and scheme; otherwise false
     *
     * @example
     *  YourClass::hasNotificationChannelConfigures($store, 'email');
     *  YourClass::hasNotificationChannelConfigures($networkUuid, 'fcm');
     *  YourClass::hasNotificationChannelConfigures('STO-12345', 'sms'); // public_id example
     *
     * @note The method name appears to have a typo; consider renaming to:
     *       hasNotificationChannelConfigured() and keeping this as a BC alias.
     */
    public static function hasNotificationChannelConfigured(Store|Network|string|null $subject, string $channel): bool
    {
        $model = self::resolveSubjectToModel($subject);

        if (!$model) {
            return false;
        }

        return NotificationChannel::query()
            ->where('owner_uuid', $model->uuid)
            ->where('scheme', $channel)
            ->exists();
    }

    /**
     * Resolve the provided subject into a Store or Network model.
     *
     * Attempts resolution by uuid first, then by public_id (if present).
     * Returns null if no matching model can be found.
     *
     * @param Store|\Fleetbase\FleetOps\Models\Network|string|null $subject
     *
     * @return Store|\Fleetbase\FleetOps\Models\Network|null
     */
    protected static function resolveSubjectToModel(Store|Network|string|null $subject): Store|Network|null
    {
        if ($subject instanceof Store || $subject instanceof Network) {
            return $subject;
        }

        if (!is_string($subject) || $subject === '') {
            return null;
        }

        // Try UUID
        if (Str::isUuid($subject)) {
            if ($found = Store::query()->where('uuid', $subject)->first()) {
                return $found;
            }
            if ($found = Network::query()->where('uuid', $subject)->first()) {
                return $found;
            }
        }

        // Try public_id (optional; remove if you don't use it)
        if (property_exists(Store::class, 'public_id') || Schema::hasColumn((new Store())->getTable(), 'public_id')) {
            if ($found = Store::query()->where('public_id', $subject)->first()) {
                return $found;
            }
        }
        if (property_exists(Network::class, 'public_id') || Schema::hasColumn((new Network())->getTable(), 'public_id')) {
            if ($found = Network::query()->where('public_id', $subject)->first()) {
                return $found;
            }
        }

        return null;
    }

    public static function calculateTipAmount($tip, $subtotal)
    {
        $tipAmount = 0;

        if (is_string($tip) && Str::endsWith($tip, '%')) {
            $tipAmount = Utils::calculatePercentage(Utils::numbersOnly($tip), $subtotal);
        } else {
            $tipAmount = Utils::numbersOnly($tip);
        }

        return $tipAmount;
    }
}
