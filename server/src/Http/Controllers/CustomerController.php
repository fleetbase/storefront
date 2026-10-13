<?php

namespace Fleetbase\Storefront\Http\Controllers;

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Storefront\Models\CustomerSegment;
use Fleetbase\Storefront\Promotions\SegmentResolver;
use Illuminate\Http\Request;

class CustomerController extends StorefrontController
{
    public $resource = 'customer';

    /**
     * Console-created customers need a name; without this the internal create accepted an empty contact.
     *
     * @var string
     */
    public $createRequest = \Fleetbase\Storefront\Http\Requests\Internal\CreateCustomerRequest::class;

    /**
     * Lifetime numbers for one customer, scoped to the store or network in the request:
     * orders, spend, average, first and last order, and the stores ordered from.
     *
     * @return \Illuminate\Http\Response
     */
    public function insights(string $id, Request $request)
    {
        $customer = Contact::where('company_uuid', session('company'))
            ->where(fn ($query) => $query->where('uuid', $id)->orWhere('public_id', $id)->orWhere('public_id', str_replace('customer_', 'contact_', $id)))
            ->first();

        if (!$customer) {
            return response()->error('Customer not found.', 404);
        }

        // Same scope as the storefront order list: an order belongs to Storefront when it carries a store id.
        $orders = Order::where(['company_uuid' => session('company'), 'customer_uuid' => $customer->uuid])
            ->whereNotNull('meta->storefront_id')
            ->whereNull('deleted_at');

        if ($request->filled('network')) {
            $orders->where('meta->storefront_network_id', $request->input('network'));
        } elseif ($request->filled('storefront')) {
            $orders->where('meta->storefront_id', $request->input('storefront'));
        }

        $orders       = $orders->orderBy('created_at', 'desc')->get();
        $canceled     = ['canceled', 'order_canceled'];
        $live         = $orders->whereNotIn('status', $canceled);
        $spend        = round($live->sum(fn ($order) => (float) (data_get($order, 'meta.total') ?? 0)), 2);
        $count        = $live->count();
        $first        = $orders->last();
        $last         = $orders->first();
        $pickupCount  = $live->filter(fn ($order) => (bool) data_get($order, 'meta.is_pickup'))->count();
        $stores       = $live->groupBy(fn ($order) => data_get($order, 'meta.storefront_id'))->map(fn ($group, $storeId) => [
            'public_id' => $storeId,
            'name'      => data_get($group->first(), 'meta.storefront'),
            'orders'    => $group->count(),
        ])->values();

        // The segments (of the store or network asked for, else every segment of the
        // company) whose rules this customer matches right now.
        $segments = CustomerSegment::query()
            ->where('company_uuid', session('company'))
            ->when($request->filled('network'), fn ($query) => $query->whereHas('owner', fn ($owner) => $owner->where('public_id', $request->input('network'))->orWhere('uuid', $request->input('network'))))
            ->when(!$request->filled('network') && $request->filled('storefront'), fn ($query) => $query->whereHas('owner', fn ($owner) => $owner->where('public_id', $request->input('storefront'))->orWhere('uuid', $request->input('storefront'))))
            ->with('owner')
            ->get()
            ->filter(fn (CustomerSegment $segment) => $segment->owner && app(SegmentResolver::class)->query($segment->owner, (array) ($segment->rules ?? []))->where('uuid', $customer->uuid)->exists())
            ->map(fn (CustomerSegment $segment) => ['id' => $segment->uuid, 'public_id' => $segment->public_id, 'name' => $segment->name])
            ->values();

        return response()->json([
            'orders'                => $count,
            'segments'              => $segments,
            'canceled'              => $orders->count() - $count,
            'spend'                 => $spend,
            'average'               => $count > 0 ? round($spend / $count, 2) : 0,
            'currency'              => data_get($last, 'meta.currency'),
            'first_order_at'        => $first?->created_at,
            'last_order_at'         => $last?->created_at,
            'last_order_public_id'  => $last?->public_id,
            'preferred_fulfillment' => $count > 0 ? ($pickupCount > $count / 2 ? 'pickup' : 'delivery') : null,
            'stores'                => $stores,
            'since'                 => $customer->created_at,
        ]);
    }
}
