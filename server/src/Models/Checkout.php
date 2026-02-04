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
    protected $fillable = ['company_uuid', 'order_uuid', 'network_uuid', 'store_uuid', 'cart_uuid', 'gateway_uuid', 'service_quote_uuid', 'owner_uuid', 'owner_type', 'amount', 'currency', 'is_cod', 'is_pickup', 'options', 'token', 'cart_state', 'captured'];

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
    public function order()
    {
        return $this->setConnection(config('fleetbase.connection.db'))->belongsTo(Order::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function owner()
    {
        return $this->setConnection(config('fleetbase.connection.db'))->morphTo(__FUNCTION__, 'owner_type', 'owner_uuid')->withoutGlobalScopes();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function serviceQuote()
    {
        return $this->setConnection(config('fleetbase.connection.db'))->belongsTo(ServiceQuote::class);
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
            $this->cart->update(['checkout_uuid' => $this->uuid]);
        }
    }
}
