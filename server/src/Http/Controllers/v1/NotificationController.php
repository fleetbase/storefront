<?php

namespace Fleetbase\Storefront\Http\Controllers\v1;

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\Notification;
use Fleetbase\Storefront\Http\Resources\v1\CustomerNotification;
use Fleetbase\Storefront\Models\Customer;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Support\NotificationPreferences;
use Fleetbase\Storefront\Support\Storefront;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The authenticated customer's notification inbox.
 */
class NotificationController extends Controller
{
    /**
     * List the customer's notifications, newest first.
     *
     * Query params: `unread` (bool), `type`, `limit` (default 25, max 100), `offset`.
     */
    public function query(Request $request)
    {
        $customer = $this->customer();
        if (!$customer) {
            return response()->apiError('Not authorized to view customer notifications', 401);
        }

        $limit  = min(max((int) $request->input('limit', 25), 1), 100);
        $offset = max((int) $request->input('offset', 0), 0);

        $notifications = $this->inbox($customer)
            ->when($request->boolean('unread'), fn ($query) => $query->whereNull('read_at'))
            ->when($request->filled('type'), fn ($query) => $query->where('data->type', $request->input('type')))
            ->orderByDesc('created_at')
            ->skip($offset)
            ->take($limit)
            ->get();

        return CustomerNotification::collection($notifications);
    }

    /**
     * Number of unread notifications, for badges.
     */
    public function unreadCount()
    {
        $customer = $this->customer();
        if (!$customer) {
            return response()->apiError('Not authorized to view customer notifications', 401);
        }

        return response()->json(['count' => $this->inbox($customer)->whereNull('read_at')->count()]);
    }

    public function find(string $id)
    {
        $customer = $this->customer();
        if (!$customer) {
            return response()->apiError('Not authorized to view customer notifications', 401);
        }

        $notification = $this->inbox($customer)->where('id', $id)->first();
        if (!$notification) {
            return response()->apiError('Notification not found.', 404);
        }

        return new CustomerNotification($notification);
    }

    public function markAsRead(string $id)
    {
        $customer = $this->customer();
        if (!$customer) {
            return response()->apiError('Not authorized to update customer notifications', 401);
        }

        $notification = $this->inbox($customer)->where('id', $id)->first();
        if (!$notification) {
            return response()->apiError('Notification not found.', 404);
        }

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return new CustomerNotification($notification);
    }

    public function markAllAsRead()
    {
        $customer = $this->customer();
        if (!$customer) {
            return response()->apiError('Not authorized to update customer notifications', 401);
        }

        $updated = $this->inbox($customer)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['status' => 'OK', 'updated' => $updated]);
    }

    public function delete(string $id)
    {
        $customer = $this->customer();
        if (!$customer) {
            return response()->apiError('Not authorized to delete customer notifications', 401);
        }

        $deleted = $this->inbox($customer)->where('id', $id)->delete();
        if (!$deleted) {
            return response()->apiError('Notification not found.', 404);
        }

        return response()->json(['status' => 'OK', 'id' => $id, 'deleted' => true]);
    }

    public function getPreferences()
    {
        $customer = $this->customer();
        if (!$customer) {
            return response()->apiError('Not authorized to view notification preferences', 401);
        }

        return response()->json(NotificationPreferences::for($customer));
    }

    public function updatePreferences(Request $request)
    {
        $customer = $this->customer();
        if (!$customer) {
            return response()->apiError('Not authorized to update notification preferences', 401);
        }

        foreach (array_keys(NotificationPreferences::DEFAULTS) as $key) {
            if ($request->has($key) && filter_var($request->input($key), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === null) {
                return response()->apiError('The ' . $key . ' preference must be true or false.');
            }
        }

        return response()->json(NotificationPreferences::update($customer, $request->only(array_keys(NotificationPreferences::DEFAULTS))));
    }

    protected function customer(): ?Contact
    {
        return Storefront::getCustomerFromToken();
    }

    /**
     * Notifications addressed to the customer, limited to the current storefront app.
     *
     * A notification belongs to the app when it references the app's store, network or one of
     * the network's member stores. Notifications that reference no storefront are shown in every
     * app of the customer's company.
     */
    protected function inbox(Contact $customer): Builder
    {
        $storefrontIds = $this->storefrontPublicIds();

        return Notification::query()
            ->whereIn('notifiable_type', [Contact::class, Customer::class])
            ->where('notifiable_id', $customer->uuid)
            ->when(!empty($storefrontIds), function ($query) use ($storefrontIds) {
                $query->where(function ($query) use ($storefrontIds) {
                    foreach (['store_id', 'network_id', 'storefront_id'] as $key) {
                        $query->orWhereIn('data->' . $key, $storefrontIds);
                    }

                    $query->orWhere(function ($query) {
                        $query->whereNull('data->store_id')->whereNull('data->network_id')->whereNull('data->storefront_id');
                    });
                });
            });
    }

    /**
     * Public ids of the storefront the request was made with (a store, or a network and its member stores).
     *
     * @return string[]
     */
    protected function storefrontPublicIds(): array
    {
        if ($storeUuid = session('storefront_store')) {
            return Store::where('uuid', $storeUuid)->pluck('public_id')->all();
        }

        if ($networkUuid = session('storefront_network')) {
            $network = Network::where('uuid', $networkUuid)->first(['uuid', 'public_id']);
            if (!$network) {
                return [];
            }

            $storeIds = Store::whereHas('networks', fn ($query) => $query->where('network_uuid', $networkUuid))->pluck('public_id')->all();

            return array_values(array_unique(array_merge([$network->public_id], $storeIds)));
        }

        return [];
    }
}
