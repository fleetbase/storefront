<?php

namespace Fleetbase\Storefront\Models;

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Models\Company;
use Fleetbase\Traits\Expirable;
use Fleetbase\Traits\HasApiModelBehavior;
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
    protected $fillable = ['company_uuid', 'user_uuid', 'checkout_uuid', 'customer_id', 'unique_identifier', 'currency', 'discount_code', 'items', 'events', 'expires_at'];

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
        $this->attributes['items'] = json_encode($events);
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
    public function add($product, $quantity = 1, $variants = [], $addons = [], $storeLocationId = null, $scheduledAt = null, $createdAt = null)
    {
        if ($product instanceof Product) {
            return $this->addItem($product, $quantity, $variants, $addons, $storeLocationId, $scheduledAt, $createdAt);
        }

        if (is_string($product)) {
            $product = static::findProduct($product);

            return $this->add($product, $quantity, $variants, $addons, $storeLocationId, $scheduledAt, $createdAt);
        }

        throw new \Exception('Invalid product provided to cart!');
    }

    /**
     * Adds an item to cart.
     *
     * @param \Fleetbase\Models\Storefront\Product $product
     * @param int                                  $quantity
     * @param array                                $variants
     * @param array                                $addons
     * @param string                               $createdAt
     */
    public function addItem(Product $product, $quantity = 1, $variants = [], $addons = [], $storeLocationId = null, $scheduledAt = null, $createdAt = null)
    {
        $id       = Utils::generatePublicId('cart_item');
        $cartItem = new \stdClass();

        // set base price
        $price = Utils::numbersOnly($product->is_on_sale ? $product->sale_price : $product->price);

        // calculate subtotal
        $subtotal = static::calculateProductSubtotal($product, $quantity, $variants, $addons);

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
        ];

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

        // set base price
        $price = Utils::numbersOnly($product->is_on_sale ? $product->sale_price : $product->price);

        // calculate subtotal
        $subtotal = static::calculateProductSubtotal($product, $quantity, $variants, $addons);

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
    public function updateCurrency(string $currencyCode = null, $save = false)
    {
        $this->attributes['currency'] = $currencyCode ?? session('storefront_currency');

        if ($save) {
            $this->save();
        }

        return $this;
    }

    /**
     * Reset the cart currency code to the current session.
     *
     * @return \Fleetbase\Models\Storefront\Cart
     */
    public function resetCurrency()
    {
        return $this->updateCurrency(null, true);
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
            'items'             => [],
            'events'            => [],
        ]);
    }

    /**
     * Retrieve a cart by id or unique id.
     */
    public static function retrieve(string $id, bool $excludeCheckedout = true): Cart
    {
        $query = static::where(function ($q) use ($id) {
            $q->where('public_id', $id);
            $q->orWhere('unique_identifier', $id);
        });

        if ($excludeCheckedout) {
            $query->whereNull('checkout_uuid');
        }

        $cart = $query->first();

        if (!$cart) {
            return static::newCart(!Str::startsWith($id, 'cart_') ? $id : null);
        }

        return $cart;
    }

    /**
     * Calculates the subtotal for a product using quantity vairants and addons.
     *
     * @param \Fleetbase\Models\Storefront\Product $product
     * @param int                                  $quantity
     * @param array                                $variants
     * @param array                                $addons
     */
    public static function calculateProductSubtotal(Product $product, $quantity = 1, $variants = [], $addons = []): int
    {
        $subtotal = $product->is_on_sale ? $product->sale_price : $product->price;

        foreach ($variants as $variant) {
            $subtotal += Utils::get($variant, 'additional_cost');
        }

        foreach ($addons as $addon) {
            $subtotal += Utils::get($addon, 'is_on_sale') ? Utils::get($addon, 'sale_price') : Utils::get($addon, 'price');
        }

        return $subtotal * $quantity;
    }

    /**
     * Finds a product via id.
     */
    public static function findProduct(string $id): ?Product
    {
        return Product::select(['uuid', 'store_uuid', 'public_id', 'name', 'description', 'price', 'sale_price', 'is_on_sale'])->where(['public_id' => $id])->with([])->first();
    }
}
