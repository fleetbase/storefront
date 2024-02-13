<?php

namespace Fleetbase\Storefront\Support;

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Models\User;
use Fleetbase\Storefront\Models\Gateway;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Product;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Notifications\StorefrontOrderCreated;
use Fleetbase\Support\Utils;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class Storefront
{
    /**
     * Returns current store or network based on session `storefront_key`
     * with bare minimum columns, but can optionally pass in more columns to receive.
     *
     * @param array $columns
     *
     * @return \Fleetbase\Models\Storefront\Network|\Fleetbase\Models\Storefront\Store
     */
    public static function about($columns = [], $with = [])
    {
        $key = session('storefront_key');

        if (!$key) {
            return null;
        }

        if (is_array($columns)) {
            $columns = array_merge(['uuid', 'public_id', 'company_uuid', 'backdrop_uuid', 'logo_uuid', 'name', 'description', 'translations', 'website', 'facebook', 'instagram', 'twitter', 'email', 'phone', 'tags', 'currency', 'timezone', 'pod_method', 'options'], $columns);
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

    public static function findAbout($id, $columns = [], $with = [])
    {
        if (is_array($columns)) {
            $columns = array_merge(['uuid', 'public_id', 'company_uuid', 'backdrop_uuid', 'logo_uuid', 'name', 'description', 'translations', 'website', 'facebook', 'instagram', 'twitter', 'email', 'phone', 'tags', 'currency', 'timezone', 'pod_method', 'options'], $columns);
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
}
