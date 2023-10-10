<?php

namespace Fleetbase\Storefront\Http\Controllers;

use Fleetbase\Storefront\Http\Requests\AddStoreToNetworkCategory;
use Fleetbase\Storefront\Http\Requests\NetworkActionRequest;
use Fleetbase\Storefront\Mail\StorefrontNetworkInvite;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\NetworkStore;
use Fleetbase\Models\Category;
use Fleetbase\Models\Invite;
use Fleetbase\FleetOps\Support\Utils;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class NetworkController extends StorefrontController
{
    /**
     * The resource to query
     *
     * @var string
     */
    public $resource = 'networks';

    /**
     * Find network by public_id or invitation code.
     *
     * @param string $id 
     * @return \Illuminate\Http\Response
     */
    public function findNetwork(string $id)
    {
        $id = trim($id);
        $isPublicId = Str::startsWith($id, ['storefront_network_', 'network_']);

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
     * @param string $id 
     * @param  \Fleetbase\Storefront\Http\Requests\NetworkActionRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function sendInvites(string $id, NetworkActionRequest $request)
    {
        $network = Network::find($id);
        $recipients = $request->input('recipients', []);

        // create invitation
        $invitation = Invite::create([
            'company_uuid' => session('company'),
            'created_by_uuid' => session('user'),
            'subject_uuid' => $network->uuid,
            'subject_type' => Utils::getMutationType($network),
            'protocol' => 'email',
            'recipients' => $recipients,
            'reason' => 'join_storefront_network'
        ]);

        // make sure subject is set
        $invitation->setRelation('subject', $network);
        $invitation->setRelation('createdBy', $request->user());

        // send invite
        Mail::send(new StorefrontNetworkInvite($invitation));

        return response()->json(['status' => 'ok']);
    }

    /**
     * Add stores to a network.
     *
     * @param string $id 
     * @param  \Fleetbase\Storefront\Http\Requests\NetworkActionRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function addStores(string $id, NetworkActionRequest $request)
    {
        $network = Network::find($id);
        $stores = collect($request->input('stores', []));
        $remove = collect($request->input('remove', []));

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

        return response()->json(['status' => 'ok']);
    }

    /**
     * Remove stores from a network.
     *
     * @param string $id 
     * @param  \Fleetbase\Storefront\Http\Requests\NetworkActionRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function removeStores(string $id, NetworkActionRequest $request)
    {
        $stores = collect($request->input('stores', []));

        // delete each
        foreach ($stores as $storeId) {
            NetworkStore::where(['store_uuid' => $storeId, 'network_uuid' => $id])->delete();
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Remove stores to a network.
     *
     * @param string $id 
     * @param  \Fleetbase\Storefront\Http\Requests\AddStoreToNetworkCategory  $request
     * @return \Illuminate\Http\Response
     */
    public function addStoreToCategory(string $id, AddStoreToNetworkCategory $request)
    {
        $category = $request->input('category');
        $store = $request->input('store');

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
     * @param string $id 
     * @param  \Fleetbase\Storefront\Http\Requests\NetworkActionRequest  $request
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
