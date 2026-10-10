<?php

namespace Fleetbase\Storefront\Http\Controllers;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Models\Category;
use Fleetbase\Models\Invite;
use Fleetbase\Storefront\Http\Requests\AddStoreToNetworkCategory;
use Fleetbase\Storefront\Http\Requests\NetworkActionRequest;
use Fleetbase\Storefront\Mail\StorefrontNetworkInvite;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\NetworkStore;
use Fleetbase\Storefront\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\ResponseCache\Facades\ResponseCache;

class NetworkController extends StorefrontController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'networks';

    /**
     * Find network by public_id or invitation code.
     *
     * @return \Illuminate\Http\Response
     */
    public function findNetwork(string $id)
    {
        $id         = trim($id);
        $isPublicId = Str::startsWith($id, ['storefront_network_', 'network_']);
        $network    = null;

        if ($isPublicId) {
            $network = Network::where('public_id', $id)->first();
        } else {
            $invite = Invite::where(['uri' => $id, 'reason' => 'join_storefront_network'])->with(['subject'])->first();

            if ($invite) {
                $network = $invite->subject;
            }
        }

        return response()->json($network);
    }

    /**
     * Add stores to a network.
     *
     * @return \Illuminate\Http\Response
     */
    public function sendInvites(string $id, NetworkActionRequest $request)
    {
        $network       = Network::find($id);
        $recipients    = collect($request->array('recipients'))->filter(fn ($email) => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL))->map(fn ($email) => strtolower(trim($email)))->unique()->values();
        $expiresInDays = $request->input('expires_in_days');
        $meta          = array_filter([
            'status'           => 'pending',
            'category_uuid'    => $request->input('category_uuid'),
            'message'          => $request->input('message'),
            'require_approval' => (bool) $request->input('require_approval', false),
        ], fn ($value) => $value !== null && $value !== '');

        // One invitation per recipient so each can be resent, revoked or declined on its own.
        $invitations = [];
        foreach ($recipients as $email) {
            $invitation = Invite::create([
                'company_uuid'    => session('company'),
                'created_by_uuid' => session('user'),
                'subject_uuid'    => $network->uuid,
                'subject_type'    => Utils::getMutationType($network),
                'protocol'        => 'email',
                'recipients'      => [$email],
                'reason'          => 'join_storefront_network',
                'meta'            => $meta,
            ]);

            if (is_numeric($expiresInDays) && (int) $expiresInDays > 0) {
                $invitation->expires_at = Carbon::now()->addDays((int) $expiresInDays);
                $invitation->save();
            }

            // make sure subject is set
            $invitation->setRelation('subject', $network);
            $invitation->setRelation('createdBy', $request->user());

            // send invite
            Mail::send(new StorefrontNetworkInvite($invitation));

            $invitations[] = static::serializeInvitation($invitation);
        }

        return response()->json(['status' => 'ok', 'invitations' => $invitations]);
    }

    /**
     * List the invitations a network has sent, newest first, with a derived state.
     *
     * @return \Illuminate\Http\Response
     */
    public function invitations(string $id)
    {
        $network = Network::where('uuid', $id)->orWhere('public_id', $id)->first();

        if (!$network) {
            return response()->error('Network not found.', 404);
        }

        $invitations = Invite::where(['subject_uuid' => $network->uuid, 'reason' => 'join_storefront_network'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn (Invite $invitation) => static::serializeInvitation($invitation))
            ->values();

        return response()->json($invitations);
    }

    /**
     * Resend a pending or expired invitation with a fresh expiry.
     *
     * @return \Illuminate\Http\Response
     */
    public function resendInvitation(string $id, string $invitationId, Request $request)
    {
        $network    = Network::find($id);
        $invitation = Invite::where(['subject_uuid' => $network->uuid, 'reason' => 'join_storefront_network'])->where(fn ($query) => $query->where('uuid', $invitationId)->orWhere('public_id', $invitationId))->first();

        if (!$invitation) {
            return response()->error('Invitation not found.', 404);
        }

        $meta              = $invitation->meta ?? [];
        $meta['status']    = 'pending';
        $meta['resent_at'] = Carbon::now()->toIso8601String();
        $invitation->meta  = $meta;

        $expiresInDays = $request->input('expires_in_days');
        if (is_numeric($expiresInDays) && (int) $expiresInDays > 0) {
            $invitation->expires_at = Carbon::now()->addDays((int) $expiresInDays);
        } elseif ($invitation->expires_at && Carbon::parse($invitation->expires_at)->isPast()) {
            $invitation->expires_at = Carbon::now()->addDays(14);
        }

        $invitation->save();
        $invitation->setRelation('subject', $network);
        $invitation->setRelation('createdBy', $request->user());

        Mail::send(new StorefrontNetworkInvite($invitation));

        return response()->json(['status' => 'ok', 'invitation' => static::serializeInvitation($invitation)]);
    }

    /**
     * Revoke an invitation so its link stops working.
     *
     * @return \Illuminate\Http\Response
     */
    public function revokeInvitation(string $id, string $invitationId)
    {
        $network    = Network::find($id);
        $invitation = Invite::where(['subject_uuid' => $network->uuid, 'reason' => 'join_storefront_network'])->where(fn ($query) => $query->where('uuid', $invitationId)->orWhere('public_id', $invitationId))->first();

        if (!$invitation) {
            return response()->error('Invitation not found.', 404);
        }

        $invitation->delete();

        return response()->json(['status' => 'ok']);
    }

    /**
     * The invitation behind a join link, with the network it is for. Revoked links are gone;
     * expired, declined and accepted ones say so.
     *
     * @return \Illuminate\Http\Response
     */
    public function lookupInvitation(string $uri)
    {
        $invitation = Invite::where(['uri' => $uri, 'reason' => 'join_storefront_network'])->with(['subject', 'createdBy'])->first();

        if (!$invitation || !$invitation->subject) {
            return response()->error('This invitation is no longer available.', 404);
        }

        $network  = $invitation->subject;
        $category = data_get($invitation->meta, 'category_uuid') ? Category::where('uuid', data_get($invitation->meta, 'category_uuid'))->first() : null;

        return response()->json([
            'invitation' => static::serializeInvitation($invitation),
            'sender'     => $invitation->createdBy ? ['name' => $invitation->createdBy->name, 'company' => data_get($invitation->createdBy, 'company.name')] : null,
            'category'   => $category ? ['id' => $category->uuid, 'name' => $category->name] : null,
            'network'    => [
                'id'           => $network->uuid,
                'public_id'    => $network->public_id,
                'name'         => $network->name,
                'description'  => $network->description,
                'logo_url'     => $network->logo_url,
                'currency'     => $network->currency,
                'timezone'     => $network->timezone,
                'stores_count' => $network->stores()->count(),
                'online'       => (bool) $network->online,
                'website'      => $network->website,
            ],
        ]);
    }

    /**
     * Accept an invitation with one of the current company's stores: the store becomes a
     * member (in the invited category when one was set) and the invitation is closed.
     *
     * @return \Illuminate\Http\Response
     */
    public function acceptInvitation(string $uri, Request $request)
    {
        $invitation = Invite::where(['uri' => $uri, 'reason' => 'join_storefront_network'])->with(['subject'])->first();

        if (!$invitation || !$invitation->subject) {
            return response()->error('This invitation is no longer available.', 404);
        }

        $status    = data_get($invitation->meta, 'status', 'pending');
        $expiresAt = $invitation->expires_at ? Carbon::parse($invitation->expires_at) : null;

        if ($status !== 'pending' || ($expiresAt && $expiresAt->isPast())) {
            return response()->error('This invitation can no longer be accepted.', 422);
        }

        $store = Store::where('company_uuid', session('company'))->where(fn ($query) => $query->where('uuid', $request->input('store'))->orWhere('public_id', $request->input('store')))->first();

        if (!$store) {
            return response()->error('Pick one of your stores to join with.', 422);
        }

        $network         = $invitation->subject;
        $requireApproval = (bool) data_get($invitation->meta, 'require_approval', false);

        NetworkStore::firstOrCreate(
            ['network_uuid' => $network->uuid, 'store_uuid' => $store->uuid],
            ['network_uuid' => $network->uuid, 'store_uuid' => $store->uuid, 'category_uuid' => data_get($invitation->meta, 'category_uuid')]
        );

        $meta                   = $invitation->meta ?? [];
        $meta['status']         = $requireApproval ? 'awaiting_approval' : 'accepted';
        $meta['accepted_at']    = Carbon::now()->toIso8601String();
        $meta['accepted_store'] = $store->uuid;
        $invitation->meta       = $meta;
        $invitation->save();

        return response()->json([
            'status'            => 'ok',
            'network'           => ['id' => $network->uuid, 'public_id' => $network->public_id, 'name' => $network->name],
            'store'             => ['id' => $store->uuid, 'public_id' => $store->public_id, 'name' => $store->name],
            'awaiting_approval' => $requireApproval,
        ]);
    }

    /**
     * Decline an invitation; the network sees it as declined and can invite again.
     *
     * @return \Illuminate\Http\Response
     */
    public function declineInvitation(string $uri)
    {
        $invitation = Invite::where(['uri' => $uri, 'reason' => 'join_storefront_network'])->first();

        if (!$invitation) {
            return response()->error('This invitation is no longer available.', 404);
        }

        $meta                = $invitation->meta ?? [];
        $meta['status']      = 'declined';
        $meta['declined_at'] = Carbon::now()->toIso8601String();
        $invitation->meta    = $meta;
        $invitation->save();

        return response()->json(['status' => 'ok']);
    }

    /**
     * Open invitations addressed to a store (matched by its email), for the store's dashboard banner.
     *
     * @return \Illuminate\Http\Response
     */
    public function pendingInvitationsForStore(Request $request)
    {
        $storeId = $request->input('storefront') ?? $request->input('store');
        $store   = $storeId ? Store::where('company_uuid', session('company'))->where(fn ($query) => $query->where('uuid', $storeId)->orWhere('public_id', $storeId))->first() : null;

        if (!$store || !$store->email) {
            return response()->json([]);
        }

        $invitations = Invite::where('reason', 'join_storefront_network')
            ->whereJsonContains('recipients', strtolower($store->email))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now()))
            ->with(['subject'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->filter(fn (Invite $invitation) => data_get($invitation->meta, 'status', 'pending') === 'pending' && $invitation->subject)
            ->map(fn (Invite $invitation) => array_merge(static::serializeInvitation($invitation), [
                'network' => [
                    'id'        => $invitation->subject->uuid,
                    'public_id' => $invitation->subject->public_id,
                    'name'      => $invitation->subject->name,
                    'logo_url'  => $invitation->subject->logo_url,
                    'currency'  => $invitation->subject->currency,
                ],
            ]))
            ->values();

        return response()->json($invitations);
    }

    /**
     * Numbers the network overview shows: members, invitations, the last seven days of
     * orders and revenue, customers, each member's share, and the latest orders.
     *
     * @return \Illuminate\Http\Response
     */
    public function overview(string $id)
    {
        $network = Network::where('uuid', $id)->orWhere('public_id', $id)->with(['stores'])->first();

        if (!$network) {
            return response()->error('Network not found.', 404);
        }

        $since        = Carbon::now()->subDays(7);
        $monthStart   = Carbon::now()->startOfMonth();
        $canceled     = ['canceled', 'order_canceled'];
        $orders       = Order::where(['company_uuid' => $network->company_uuid, 'type' => 'storefront'])
            ->where('meta->storefront_network_id', $network->public_id)
            ->whereNull('deleted_at')
            ->where('created_at', '>=', $since)
            ->with(['customer'])
            ->orderBy('created_at', 'desc')
            ->get();
        $liveOrders   = $orders->whereNotIn('status', $canceled);
        $revenue      = round($liveOrders->sum(fn ($order) => (float) (data_get($order, 'meta.total') ?? 0)), 2);
        $tips         = round($liveOrders->sum(fn ($order) => (float) (data_get($order, 'meta.tip') ?? 0) + (float) (data_get($order, 'meta.delivery_tip') ?? 0)), 2);
        $multiStore   = $liveOrders->filter(fn ($order) => data_get($order, 'meta.checkout_id'))->groupBy(fn ($order) => data_get($order, 'meta.checkout_id'))->filter(fn ($group) => $group->count() > 1)->count();
        $allCustomers = Order::where(['company_uuid' => $network->company_uuid, 'type' => 'storefront'])->where('meta->storefront_network_id', $network->public_id)->whereNull('deleted_at')->whereNotNull('customer_uuid');
        $customers    = (clone $allCustomers)->distinct()->count('customer_uuid');
        $newCustomers = (clone $allCustomers)->where('created_at', '>=', $monthStart)->distinct()->count('customer_uuid');

        $invitations = Invite::where(['subject_uuid' => $network->uuid, 'reason' => 'join_storefront_network'])->orderBy('created_at', 'desc')->get()->map(fn (Invite $invitation) => static::serializeInvitation($invitation));
        $byStore     = $liveOrders->groupBy(fn ($order) => data_get($order, 'meta.storefront_id'));
        $stores      = $network->stores->map(function (Store $store) use ($byStore, $liveOrders, $revenue) {
            $storeOrders  = $byStore->get($store->public_id, collect());
            $storeRevenue = round($storeOrders->sum(fn ($order) => (float) (data_get($order, 'meta.total') ?? 0)), 2);

            return [
                'uuid'          => $store->uuid,
                'public_id'     => $store->public_id,
                'name'          => $store->name,
                'logo_url'      => $store->logo_url,
                'online'        => (bool) $store->online,
                'currency'      => $store->currency,
                'category_uuid' => $store->pivot->category_uuid ?? null,
                'orders_7d'     => $storeOrders->count(),
                'revenue_7d'    => $storeRevenue,
                'share'         => $liveOrders->count() > 0 ? round(($storeOrders->count() / $liveOrders->count()) * 100) : 0,
                'revenue_share' => $revenue > 0 ? round(($storeRevenue / $revenue) * 100) : 0,
            ];
        })->values();

        return response()->json([
            'currency' => $network->currency,
            'stores'   => [
                'total'  => $network->stores->count(),
                'online' => $network->stores->where('online', true)->count(),
            ],
            'invitations' => [
                'pending'  => $invitations->where('status', 'pending')->count(),
                'declined' => $invitations->where('status', 'declined')->count(),
                'expired'  => $invitations->where('status', 'expired')->count(),
            ],
            'orders' => [
                'count_7d'       => $liveOrders->count(),
                'multi_store_7d' => $multiStore,
                'canceled_7d'    => $orders->count() - $liveOrders->count(),
            ],
            'revenue' => [
                'total_7d' => $revenue,
                'tips_7d'  => $tips,
                'currency' => $network->currency,
            ],
            'customers' => [
                'total'          => $customers,
                'new_this_month' => $newCustomers,
            ],
            'members'       => $stores,
            'recent_orders' => $orders->take(5)->map(fn ($order) => [
                'public_id'     => $order->public_id,
                'checkout_id'   => data_get($order, 'meta.checkout_id'),
                'customer_name' => data_get($order, 'customer.name'),
                'store_id'      => data_get($order, 'meta.storefront_id'),
                'store_name'    => data_get($order, 'meta.storefront'),
                'total'         => data_get($order, 'meta.total'),
                'currency'      => data_get($order, 'meta.currency', $network->currency),
                'status'        => $order->status,
                'created_at'    => $order->created_at,
            ])->values(),
        ]);
    }

    /**
     * The shape the console reads an invitation in: one recipient, a derived state and the
     * options it was sent with.
     */
    public static function serializeInvitation(Invite $invitation): array
    {
        $meta      = $invitation->meta ?? [];
        $status    = data_get($meta, 'status', 'pending');
        $expiresAt = $invitation->expires_at ? Carbon::parse($invitation->expires_at) : null;

        if ($status === 'pending' && $expiresAt && $expiresAt->isPast()) {
            $status = 'expired';
        }

        return [
            'id'               => $invitation->uuid,
            'uuid'             => $invitation->uuid,
            'public_id'        => $invitation->public_id,
            'uri'              => $invitation->uri,
            'code'             => $invitation->code,
            'email'            => collect($invitation->recipients ?? [])->first(),
            'recipients'       => $invitation->recipients ?? [],
            'status'           => $status,
            'category_uuid'    => data_get($meta, 'category_uuid'),
            'message'          => data_get($meta, 'message'),
            'require_approval' => (bool) data_get($meta, 'require_approval', false),
            'resent_at'        => data_get($meta, 'resent_at'),
            'declined_at'      => data_get($meta, 'declined_at'),
            'accepted_at'      => data_get($meta, 'accepted_at'),
            'expires_at'       => $expiresAt,
            'created_at'       => $invitation->created_at,
            'updated_at'       => $invitation->updated_at,
        ];
    }

    /**
     * Add stores to a network.
     *
     * @return \Illuminate\Http\Response
     */
    public function addStores(string $id, NetworkActionRequest $request)
    {
        $network = Network::find($id);
        $stores  = collect($request->input('stores', []));
        $remove  = collect($request->input('remove', []));

        // firstOrCreate each
        foreach ($stores as $storeId) {
            NetworkStore::firstOrCreate(
                ['network_uuid' => $network->uuid, 'store_uuid' => $storeId],
                ['network_uuid' => $network->uuid, 'store_uuid' => $storeId]
            );
        }

        // delete each
        foreach ($remove as $storeId) {
            NetworkStore::where('store_uuid', $storeId)->delete();
        }

        $this->forgetCachedResponses();

        return response()->json(['status' => 'ok']);
    }

    /**
     * Suspend memberships: the stores stay in the network but disappear from its app and carts.
     *
     * @return \Illuminate\Http\Response
     */
    public function suspendStores(string $id, NetworkActionRequest $request)
    {
        return $this->setMembershipStatus($id, $request->array('stores'), NetworkStore::STATUS_SUSPENDED);
    }

    /**
     * Reinstate suspended memberships.
     *
     * @return \Illuminate\Http\Response
     */
    public function reinstateStores(string $id, NetworkActionRequest $request)
    {
        return $this->setMembershipStatus($id, $request->array('stores'), NetworkStore::STATUS_ACTIVE);
    }

    protected function setMembershipStatus(string $networkId, array $stores, string $status)
    {
        $updated = NetworkStore::where('network_uuid', $networkId)
            ->whereIn('store_uuid', $stores)
            ->update(['status' => $status]);

        $this->forgetCachedResponses();

        return response()->json(['status' => 'ok', 'updated' => $updated]);
    }

    /**
     * Membership changes happen outside the resource controllers, so the cached GET
     * responses (store listings with their membership status) are cleared here.
     */
    protected function forgetCachedResponses(): void
    {
        if (class_exists(ResponseCache::class)) {
            ResponseCache::clear();
        }
    }

    /**
     * Remove stores from a network.
     *
     * @return \Illuminate\Http\Response
     */
    public function removeStores(string $id, NetworkActionRequest $request)
    {
        $stores = $request->array('stores');

        // delete each
        foreach ($stores as $storeId) {
            NetworkStore::where(['store_uuid' => $storeId, 'network_uuid' => $id])->delete();
        }

        $this->forgetCachedResponses();

        return response()->json(['status' => 'ok']);
    }

    /**
     * Add a store to a network category.
     *
     * @return \Illuminate\Http\Response
     */
    public function addStoreToCategory(string $id, AddStoreToNetworkCategory $request)
    {
        $category = $request->input('category');
        $store    = $request->input('store');

        // get network store instance
        $networkStore = NetworkStore::where(['network_uuid' => $id, 'store_uuid' => $store])->first();
        if ($networkStore) {
            $networkStore->update(['category_uuid' => $category]);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Remove stores to a network.
     *
     * @return \Illuminate\Http\Response
     */
    public function removeStoreCategory(string $id, NetworkActionRequest $request)
    {
        $store    = $request->input('store');

        // get network store instance
        $networkStore = NetworkStore::where(['network_uuid' => $id, 'store_uuid' => $store])->first();
        if ($networkStore) {
            $networkStore->update(['category_uuid' => null]);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Remove stores to a network.
     *
     * @return \Illuminate\Http\Response
     */
    public function deleteCategory(string $id, NetworkActionRequest $request)
    {
        $category = $request->input('category');

        // get network store instance
        NetworkStore::where(['network_uuid' => $id, 'category_uuid' => $category])->update(['category_uuid' => null]);

        // delete the category
        Category::where(['owner_uuid' => $id, 'uuid' => $category])->delete();

        return response()->json(['status' => 'ok']);
    }
}
