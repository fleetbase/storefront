<?php

namespace Fleetbase\Storefront\Observers;

use Fleetbase\Storefront\Models\Network;
use Illuminate\Support\Facades\Request;

class NetworkObserver
{
    /**
     * Handle the Network "updated" event.
     *
     * @param Network $network the Network that is updating
     */
    public function updating(Network $network): void
    {
        $network->flushAttributesCache();
        $alertable = Request::array('network.alertable');

        // set alertables to public_id
        $network->alertable = collect($alertable)->mapWithKeys(
            function ($alertables, $key) {
                if (!is_array($alertables)) {
                    return [];
                }

                return [
                    $key => collect($alertables)->map(
                        function ($user) {
                            return data_get($user, 'public_id');
                        }
                    )
                        ->values()
                        ->toArray(),
                ];
            }
        )->toArray();
    }
}
