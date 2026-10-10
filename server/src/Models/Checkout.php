<?php

namespace Fleetbase\Storefront\Models;

use Fleetbase\Casts\Json;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\Models\Company;
use Fleetbase\Support\Utils;
use Fleetbase\Traits\HasOptionsAttributes;
use Fleetbase\Traits\HasPublicid;
use Fleetbase\Traits\HasUuid;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class Checkout extends StorefrontModel
{
    use HasUuid;
    use HasPublicid;
    use HasOptionsAttributes;

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'chkt';

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'checkouts';

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
    protected $fillable = ['company_uuid', 'order_uuid', 'network_uuid', 'store_uuid', 'cart_uuid', 'gateway_uuid', 'service_quote_uuid', 'owner_uuid', 'owner_type', 'amount', 'currency', 'is_cod', 'is_pickup', 'options', 'token', 'cart_state', 'captured', 'stripe_payment_intent_id'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'cart_state' => Json::class,
        'options'    => Json::class,
        'captured'   => 'boolean',
        'is_cod'     => 'boolean',
        'is_pickup'  => 'boolean',
    ];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = [];

    /** on boot generate token */
    public static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->token = 'checkout_' . md5(Str::random(14) . time());
        });
    }

    /*
     * The related models (Company, Order, Contact, ServiceQuote) choose the Fleetbase
     * database themselves (Fleetbase\Models\Model::getConnectionName()). These relations
     * used to call $this->setConnection(...), which switched the checkout itself to that
     * database, so any later refresh or save of the checkout failed (checkouts lives in
     * the storefront database) — e.g. after a QPay payment, before the order was created.
     */

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function owner()
    {
        return $this->morphTo(__FUNCTION__, 'owner_type', 'owner_uuid')->withoutGlobalScopes();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function serviceQuote()
    {
        return $this->belongsTo(ServiceQuote::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function network()
    {
        return $this->belongsTo(Network::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function gateway()
    {
        return $this->belongsTo(Gateway::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function cart()
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * Sets the owner type.
     *
     * @return void
     */
    public function setOwnerTypeAttribute($type)
    {
        $this->attributes['owner_type'] = Utils::getMutationType($type);
    }

    /**
     * Update the cart as checkout.
     *
     * @return void
     */
    public function checkedout()
    {
        if (!isset($this->cart)) {
            $this->load(['cart']);
        }

        $cart = $this->cart;

        if ($cart) {
            $this->cart->update(['checkout_uuid' => $this->uuid, 'status' => Cart::STATUS_CHECKED_OUT]);
        }
    }

    /**
     * The cart as it was when this checkout was created (cart_state): the items and prices
     * that were priced and charged. Orders are created from it, so a cart changed or
     * cleared between payment and order creation can't change the order. Null for older
     * checkouts saved without it. The returned cart is a read-only copy, never saved.
     */
    public function cartAtCheckout(): ?Cart
    {
        $state = json_decode(json_encode($this->cart_state ?? null), true);
        if (!is_array($state) || !array_key_exists('items', $state) || !is_array($state['items'])) {
            return null;
        }

        $attributes           = Arr::only($state, ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'checkout_uuid', 'status', 'customer_id', 'unique_identifier', 'currency', 'discount_code', 'expires_at', 'created_at', 'updated_at']);
        $attributes['uuid']   = $attributes['uuid'] ?? $this->cart_uuid;
        $attributes['items']  = json_encode($state['items']);
        $attributes['events'] = json_encode($state['events'] ?? []);

        $cart = new Cart();
        $cart->setRawAttributes($attributes, true);

        return $cart;
    }
}
