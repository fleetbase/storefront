<?php

namespace Fleetbase\Storefront\Models;

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Fleetbase\Traits\Expirable;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasApiModelCache;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Cart extends StorefrontModel
{
    use HasUuid;
    use HasPublicId;
    use HasApiModelBehavior;
    use HasApiModelCache;
    use Expirable;

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'cart';

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'carts';

    /**
     * These attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = [];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['company_uuid', 'user_uuid', 'checkout_uuid', 'status', 'customer_id', 'unique_identifier', 'currency', 'discount_code', 'items', 'events', 'expires_at'];

    /**
     * Cart statuses. Only an open cart is shopped; a checked out or cleared cart keeps its
     * items as the record of what it held, and the device continues with a new open cart.
     */
    public const STATUS_OPEN        = 'open';
    public const STATUS_CHECKED_OUT = 'checked_out';
    public const STATUS_CLEARED     = 'cleared';

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'expires_at' => 'datetime',
    ];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = ['total_items', 'total_unique_items', 'subtotal'];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function company()
    {
        return $this->setConnection(config('fleetbase.connection.db'))->belongsTo(Company::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->setConnection(config('fleetbase.connection.db'))->belongsTo(User::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function customer()
    {
        return $this->setConnection(config('fleetbase.connection.db'))->belongsTo(Contact::class, 'public_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function checkout()
    {
        return $this->belongsTo(Checkout::class);
    }

    /**
     * Set cart items.
     *
     * @return void
     */
    public function setItemsAttribute($items)
    {
        $this->attributes['items'] = json_encode($items);
    }

    /**
     * Set cart events.
     *
     * @return void
     */
    public function setEventsAttribute($events)
    {
        $this->attributes['events'] = json_encode($events);
    }

    /**
     * Get cart items.
     *
     * @return array
     */
    public function getItemsAttribute($items)
    {
        if (is_array($items)) {
            return $items;
        }

        return (array) json_decode($items, false);
    }

    /**
     * Get cart events.
     *
     * @return array
     */
    public function getEventsAttribute($events)
    {
        if (is_array($events)) {
            return $events;
        }

        return (array) json_decode($events, false);
    }

    /**
     * Promotion codes applied to the cart, stored comma separated in `discount_code`.
     *
     * @return string[]
     */
    public function getPromotionCodes(): array
    {
        return array_values(array_filter(array_map(
            [PromotionCode::class, 'normalize'],
            explode(',', (string) $this->getAttribute('discount_code'))
        )));
    }

    /**
     * @param string[] $codes
     */
    public function setPromotionCodes(array $codes): self
    {
        $codes = array_values(array_unique(array_filter(array_map([PromotionCode::class, 'normalize'], $codes))));
        $this->setAttribute('discount_code', $codes ? implode(',', $codes) : null);

        return $this;
    }

    /**
     * Computes subtotal of cart.
     *
     * @return int
     */
    public function getSubtotalAttribute()
    {
        $items    = $this->getAttribute('items') ?? [];
        $subtotal = 0;

        foreach ($items as $item) {
            $subtotal += Utils::numbersOnly($item->subtotal);
        }

        return $subtotal;
    }

    /**
     * Computes total items in cart.
     *
     * @return int
     */
    public function getTotalItemsAttribute()
    {
        $items = $this->getAttribute('items') ?? [];
        $total = 0;

        foreach ($items as $item) {
            $total += Utils::numbersOnly($item->quantity);
        }

        return $total;
    }

    /**
     * Computes total unique items in cart.
     *
     * @return int
     */
    public function getTotalUniqueItemsAttribute()
    {
        return count($this->getAttribute('items'));
    }

    /**
     * The last cart event.
     *
     * @return \stdClass|null
     */
    public function getLastEventAttribute()
    {
        $events = $this->getAttribute('events') ?? [];

        return Arr::last($events);
    }

    /**
     * If the cart is a multi cart.
     *
     * @return bool
     */
    public function getIsMultiCartAttribute()
    {
        return collect($this->items)->pluck('store_id')->unique()->count() > 1;
    }

    /**
     * Returns the checkout store id.
     *
     * @return string
     */
    public function getCheckoutStoreIdAttribute()
    {
        return collect($this->items)->pluck('store_id')->unique()->first();
    }

    /**
     * Returns the checkout store ids for all stores being checked out from.
     *
     * @return array
     */
    public function getCheckoutStoreIdsAttribute()
    {
        return collect($this->items)->pluck('store_id')->unique()->toArray();
    }

    /**
     * Returns cart items for a specific store only.
     *
     * @return array
     */
    public function getItemsForStore($id)
    {
        if ($id instanceof Store) {
            $id = $id->public_id;
        }

        return collect($this->items)->filter(function ($cartItem) use ($id) {
            return $cartItem->store_id === $id;
        })->toArray();
    }

    /**
     * Computes subtotal of specific cart items of a store.
     *
     * @return int
     */
    public function getSubtotalForStore($id)
    {
        $items    = $this->getItemsForStore($id);
        $subtotal = 0;

        foreach ($items as $item) {
            $subtotal += Utils::numbersOnly($item->subtotal);
        }

        return $subtotal;
    }

    /**
     * Adds item to cart.
     *
     * @param Product|string $product
     * @param int            $quantity
     * @param array          $variants
     * @param array          $addons
     * @param string|null    $createdAt
     *
     * @return \stdClass
     *
     * @throws \Exception
     */
    public function add($product, $quantity = 1, $variants = [], $addons = [], $storeLocationId = null, $scheduledAt = null, $createdAt = null, ?string $catalogId = null)
    {
        if ($product instanceof Product) {
            return $this->addItem($product, $quantity, $variants, $addons, $storeLocationId, $scheduledAt, $createdAt, $catalogId);
        }

        if (is_string($product)) {
            $product = static::findProduct($product);

            return $this->add($product, $quantity, $variants, $addons, $storeLocationId, $scheduledAt, $createdAt, $catalogId);
        }

        throw new \Exception('Invalid product provided to cart!');
    }

    /**
     * Adds an item to cart.
     *
     * @param int    $quantity
     * @param array  $variants
     * @param array  $addons
     * @param string $createdAt
     */
    public function addItem(Product $product, $quantity = 1, $variants = [], $addons = [], $storeLocationId = null, $scheduledAt = null, $createdAt = null, ?string $catalogId = null)
    {
        $id       = Utils::generatePublicId('cart_item');
        $cartItem = new \stdClass();

        // When the item comes from a catalog, that catalog's price and availability apply.
        $override = static::catalogOverride($product, $catalogId);
        if ($override && $override['is_available'] === false) {
            throw new \Exception('This product is not available in the selected catalog.');
        }

        if ($storeLocationId && !Str::startsWith($storeLocationId, 'food_truck_')) {
            $locationBelongsToStore = StoreLocation::where('store_uuid', $product->store_uuid)
                ->where(function ($query) use ($storeLocationId) {
                    $query->where('public_id', $storeLocationId)->orWhere('uuid', $storeLocationId);
                })
                ->exists();

            if (!$locationBelongsToStore) {
                throw new \Exception('The selected store location is not available for this product.');
            }
        }

        // set base price
        $price = $override['price'] ?? Utils::numbersOnly($product->is_on_sale ? $product->sale_price : $product->price);

        // calculate subtotal
        $subtotal = static::calculateProductSubtotal($product, $quantity, $variants, $addons, $override['price'] ?? null);

        // if no store location id, default to first store location
        if (empty($storeLocationId)) {
            $storeLocationId = Utils::get($product, 'store.locations.0.public_id');
        }

        $properties = [
            'id'                => $id,
            'store_id'          => $product->store_id,
            'store_location_id' => $storeLocationId,
            'product_id'        => $product->public_id,
            'product_image_url' => $product->primary_image_url,
            'name'              => $product->name,
            'description'       => $product->description,
            'scheduled_at'      => $scheduledAt,
            'created_at'        => $createdAt ?? time(),
            'updated_at'        => time(),
            'quantity'          => $quantity,
            'price'             => $price,
            'subtotal'          => $subtotal,
            'variants'          => $variants,
            'addons'            => $addons,
            'meta'              => $product->meta ?? [],
            'catalog_id'        => $catalogId,
        ];

        // If item was added from a food truck
        if ($storeLocationId && Str::startsWith($storeLocationId, 'food_truck_')) {
            $properties['food_truck_id']     = $storeLocationId;
            $properties['store_location_id'] = Utils::get($product, 'store.locations.0.public_id');
        }

        $this->updateCurrency($product->currency, false);

        foreach ($properties as $prop => $value) {
            $cartItem->{$prop} = $value;
        }

        $items = $this->getAttribute('items');

        $items[] = $cartItem;

        $this->createEvent('cart.item_added', $cartItem->id, false);
        $this->attributes['items'] = $items;
        $this->save();

        return $cartItem;
    }

    /**
     * Adds item to cart.
     *
     * @param \stdClass|string $cartItem
     * @param int              $quantity
     * @param array            $variants
     * @param array            $addons
     *
     * @return \stdClass
     *
     * @throws \Exception
     */
    public function updateItem($cartItem, $quantity = 1, $variants = [], $addons = [], $scheduledAt = null)
    {
        if ($cartItem instanceof \stdClass) {
            return $this->updateCartItem($cartItem, $quantity, $variants, $addons, $scheduledAt);
        }

        if (is_string($cartItem)) {
            $cartItem = $this->findCartItem($cartItem);

            return $this->updateItem($cartItem, $quantity, $variants, $addons, $scheduledAt);
        }

        throw new \Exception('Invalid cart item provided to cart!');
    }

    /**
     * Updates an item in cart.
     *
     * @param [type] $cartItem
     * @param int   $quantity
     * @param array $variants
     * @param array $addons
     *
     * @return \stdClass
     */
    public function updateCartItem($cartItem, $quantity = 1, $variants = [], $addons = [], $scheduledAt = null)
    {
        // get the line item product
        $productId = $cartItem->product_id;
        $product   = static::findProduct($productId);

        // the line keeps the catalog it was added from, so its price stays the catalog price
        $override = static::catalogOverride($product, $cartItem->catalog_id ?? null);

        // set base price
        $price = $override['price'] ?? Utils::numbersOnly($product->is_on_sale ? $product->sale_price : $product->price);

        // calculate subtotal
        $subtotal = static::calculateProductSubtotal($product, $quantity, $variants, $addons, $override['price'] ?? null);

        // get cart items
        $items = $this->getAttribute('items');

        // find the item from cart
        $index = $this->findCartItemIndex($cartItem->id);

        $existingCartItem = $items[$index] ?? new \stdClass();

        $properties = [
            'id'                => $cartItem->id,
            'store_id'          => $product->store_id,
            'product_id'        => $product->public_id,
            'product_image_url' => $product->primary_image_url,
            'name'              => $product->name,
            'description'       => $product->description,
            'scheduled_at'      => $scheduledAt,
            'created_at'        => $cartItem->created_at,
            'updated_at'        => time(),
            'quantity'          => $quantity ?? $cartItem->quantity,
            'price'             => $price,
            'subtotal'          => $subtotal,
            'variants'          => $variants ?? $cartItem->variants,
            'addons'            => $addons ?? $cartItem->addons,
        ];

        foreach ($properties as $prop => $value) {
            $existingCartItem->{$prop} = $value;
        }

        $items[$index] = $existingCartItem;

        $this->createEvent('cart.item_updated', $cartItem->id, false);
        $this->attributes['items'] = $items;
        $this->save();

        return $existingCartItem;
    }

    /**
     * Updates a cart item by id.
     *
     * @param int   $quantity
     * @param array $variants
     * @param array $addons
     *
     * @return \stdClass
     */
    public function updateCartItemById(string $id, $quantity = 1, $variants = [], $addons = [], $scheduledAt = null)
    {
        $cartItem = $this->findCartItem($id);

        return $this->updateCartItem($cartItem, $quantity, $variants, $addons, $scheduledAt);
    }

    /**
     * Remove item from cart.
     *
     * @param \stdClass|string $cartItem
     *
     * @return \stdClass
     *
     * @throws \Exception
     */
    public function remove($cartItem)
    {
        if ($cartItem instanceof \stdClass) {
            return $this->removeItem($cartItem);
        }

        if (is_string($cartItem)) {
            $cartItem = $this->findCartItem($cartItem);

            return $this->remove($cartItem);
        }

        throw new \Exception('Invalid cart item provided to cart!');
    }

    /**
     * Removes an item from cart.
     *
     * @param \stdClass $cartItem
     *
     * @return \Fleetbase\Models\Storefront\Cart
     */
    public function removeItem($cartItem)
    {
        $items = $this->getAttribute('items');
        $index = $this->findCartItemIndex($cartItem->id);

        unset($items[$index]);

        $this->createEvent('cart.item_removed', $cartItem->id, false);
        $this->attributes['items'] = $items;
        $this->save();

        return $this;
    }

    /**
     * Removes a cart item by id.
     *
     * @return \Fleetbase\Models\Storefront\Cart
     */
    public function removeItemById(string $id)
    {
        $cartItem = $this->findCartItem($id);

        return $this->removeItem($cartItem);
    }

    /**
     * Empties the cart.
     *
     * @return \Fleetbase\Models\Storefront\Cart
     */
    public function empty()
    {
        $this->createEvent('cart.emptied', null, false);
        $this->attributes['items'] = [];
        $this->updateCurrency(null, false);
        $this->save();

        return $this;
    }

    /**
     * Finds an item in cart by id.
     */
    public function findCartItem(string $id): ?\stdClass
    {
        $items = $this->getAttribute('items');

        $foundCartItem = collect($items)->first(function ($item) use ($id) {
            return $item->id === $id;
        });

        return $foundCartItem;
    }

    /**
     * Finds index of a cart item.
     */
    public function findCartItemIndex(string $id): ?int
    {
        $items = $this->getAttribute('items');

        foreach ($items as $index => $item) {
            if ($item->id === $id) {
                return $index;
            }
        }

        return -1;
    }

    /**
     * Create a new cart event.
     *
     * @param string|null $cartItemId
     *
     * @return \Fleetbase\Models\Storefront\Cart
     */
    public function createEvent(string $eventName, $cartItemId = null, $save = true)
    {
        $events = $this->getAttribute('events') ?? [];

        $events[] = Utils::createObject([
            'event'        => $eventName,
            'cart_item_id' => $cartItemId,
            'time'         => time(),
        ]);

        $this->attributes['events'] = $events;

        if ($save) {
            $this->save();
        }

        return $this;
    }

    /**
     * Update the cart session currency code.
     *
     * @param bool $save
     *
     * @return \Fleetbase\Models\Storefront\Cart
     */
    public function updateCurrency(?string $currencyCode = null, $save = false)
    {
        $this->attributes['currency'] = $currencyCode ?? session('storefront_currency');

        if ($save) {
            $this->save();
        }

        return $this;
    }

    /**
     * Creates a new cart.
     *
     * @param string|null $uniqueId
     *
     * @return \Fleetbase\Models\Storefront\Cart
     */
    public static function newCart($uniqueId = null): Cart
    {
        return Cart::create([
            'unique_identifier' => $uniqueId,
            'company_uuid'      => session('company'),
            'expires_at'        => Carbon::now()->addDays(7),
            'currency'          => session('storefront_currency'),
            'customer_id'       => session('customer_id'),
            'status'            => static::STATUS_OPEN,
            'items'             => [],
            'events'            => [],
        ]);
    }

    /**
     * Carts still being shopped: not checked out, not cleared.
     */
    public function scopeOpen($query)
    {
        return $query->whereNull('checkout_uuid')->where(function ($query) {
            $query->whereNull('status')->orWhere('status', static::STATUS_OPEN);
        });
    }

    public function isOpen(): bool
    {
        return $this->checkout_uuid === null && in_array($this->status, [null, static::STATUS_OPEN], true);
    }

    /**
     * Close this cart as cleared, keeping its items, and return the open cart the device
     * continues with. An empty open cart is simply kept; a closed cart is left as it is.
     */
    public function clear(): Cart
    {
        if ($this->isOpen()) {
            if (count($this->getAttribute('items') ?? []) === 0) {
                return $this;
            }

            $this->createEvent('cart.cleared', null, false);
            $this->status = static::STATUS_CLEARED;
            $this->save();
        }

        return static::openCartFor($this->unique_identifier);
    }

    /**
     * The open cart of a device (its unique identifier), or a new one for it.
     */
    public static function openCartFor(?string $uniqueId): Cart
    {
        if ($uniqueId) {
            $query = static::where('unique_identifier', $uniqueId)->open();
            if (session('company')) {
                $query->where('company_uuid', session('company'));
            }

            $open = $query->latest()->first();
            if ($open) {
                return $open;
            }
        }

        return static::newCart($uniqueId);
    }

    /**
     * Retrieve a cart by id or unique id.
     */
    public static function retrieve(?string $id, bool $excludeCheckedout = true): Cart
    {
        if (is_null($id)) {
            return static::newCart();
        }

        $query = static::where(function ($q) use ($id) {
            $q->where('public_id', $id);
            $q->orWhere('unique_identifier', $id);
        });

        if (session('company')) {
            $query->where('company_uuid', session('company'));
        }

        if (!$excludeCheckedout) {
            return $query->first() ?? static::newCart(!Str::startsWith($id, 'cart_') ? $id : null);
        }

        $cart = (clone $query)->open()->first();
        if ($cart) {
            return $cart;
        }

        // The id may name a cart that has been checked out or cleared (an app can still
        // hold it): continue with the open cart of the same device rather than starting an
        // anonymous cart that no device will find again.
        $closed = (clone $query)->latest()->first();
        if ($closed && $closed->unique_identifier) {
            return static::openCartFor($closed->unique_identifier);
        }

        return static::newCart(!Str::startsWith($id, 'cart_') ? $id : null);
    }

    /**
     * Calculates the subtotal for a product using quantity vairants and addons.
     *
     * @param \Fleetbase\Models\Storefront\Product $product
     * @param int                                  $quantity
     * @param array                                $variants
     * @param array                                $addons
     */
    public static function calculateProductSubtotal(Product $product, $quantity = 1, $variants = [], $addons = [], ?int $basePrice = null): int
    {
        $subtotal = $basePrice ?? ($product->is_on_sale ? $product->sale_price : $product->price);

        foreach ($variants as $variant) {
            $subtotal += Utils::get($variant, 'additional_cost');
        }

        foreach ($addons as $addon) {
            $subtotal += Utils::get($addon, 'is_on_sale') ? Utils::get($addon, 'sale_price') : Utils::get($addon, 'price');
        }

        return $subtotal * $quantity;
    }

    /**
     * The per-catalog price and availability of a product, when it is sold through a catalog.
     *
     * @return array{price: int|null, is_available: bool|null}|null null when there is no catalog or the product is not in it
     */
    public static function catalogOverride(?Product $product, ?string $catalogId): ?array
    {
        if (!$product || !$catalogId) {
            return null;
        }

        $catalogUuid = Str::isUuid($catalogId) ? $catalogId : Catalog::where('public_id', $catalogId)->value('uuid');
        if (!$catalogUuid) {
            return null;
        }

        $pivot = CatalogProduct::query()
            ->where('product_uuid', $product->uuid)
            ->whereNull('deleted_at')
            ->whereIn('catalog_category_uuid', CatalogCategory::query()->where('owner_uuid', $catalogUuid)->select('uuid'))
            ->first();

        if (!$pivot) {
            return null;
        }

        return [
            'price'        => $pivot->price === null ? null : (int) $pivot->price,
            'is_available' => $pivot->is_available === null ? null : (bool) $pivot->is_available,
        ];
    }

    /**
     * Finds a product via id.
     */
    public static function findProduct(string $id): ?Product
    {
        return Product::select(['uuid', 'store_uuid', 'public_id', 'primary_image_uuid', 'name', 'description', 'price', 'currency', 'sale_price', 'is_on_sale', 'is_available', 'status', 'meta'])
            ->where('public_id', $id)
            ->when(session('storefront_store'), fn ($query) => $query->where('store_uuid', session('storefront_store')))
            ->when(session('storefront_network'), function ($query) {
                $query->whereHas('store.networks', fn ($networkQuery) => $networkQuery->where('network_uuid', session('storefront_network')));
                $query->where('is_available', 1);
                $query->where('status', 'published');
            })
            ->with(['store.locations'])
            ->first();
    }

    public function getCurrency(?string $fallbackCurrency = null): ?string
    {
        return $this->currency ?? session('storefront_currency', $fallbackCurrency);
    }
}
