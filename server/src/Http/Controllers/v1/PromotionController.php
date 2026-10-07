<?php

namespace Fleetbase\Storefront\Http\Controllers\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Storefront\Http\Resources\Promotion as PromotionResource;
use Fleetbase\Storefront\Models\Promotion;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Support\Storefront;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Public promotions ("deals") of the storefront the request is made with.
 */
class PromotionController extends Controller
{
    /**
     * Live, public promotions of the storefront, and in a network of its member stores.
     *
     * Query params:
     * - `store`: a store public id, to only list that store's promotions in a network.
     * - `include=scheduled`: also list active promotions that are outside their weekly hours or
     *   have not started yet, so the app can show "starts again at 2 pm". Each promotion's
     *   `availability` says which it is.
     */
    public function query(Request $request)
    {
        $listed = $request->input('include') === 'scheduled'
            ? [Promotion::AVAILABILITY_LIVE, Promotion::AVAILABILITY_SCHEDULED]
            : [Promotion::AVAILABILITY_LIVE];

        $promotions = $this->publicPromotions($request->input('store'))
            ->where('status', Promotion::STATUS_ACTIVE)
            ->orderByDesc('priority')
            ->orderBy('ends_at')
            ->get()
            ->filter(fn (Promotion $promotion) => in_array($promotion->availabilityAt(), $listed, true))
            ->values();

        return PromotionResource::collection($promotions);
    }

    /**
     * One public promotion. Scheduled and ended promotions are returned too, so a link from a
     * notification still opens and can say when the offer runs or that it is over.
     */
    public function find(string $id)
    {
        $promotion = $this->publicPromotions()
            ->whereIn('status', [Promotion::STATUS_ACTIVE, Promotion::STATUS_ENDED])
            ->where('public_id', $id)
            ->first();

        if (!$promotion || $promotion->availabilityAt() === Promotion::AVAILABILITY_INACTIVE) {
            return response()->apiError('Promotion not found.', 404);
        }

        return new PromotionResource($promotion);
    }

    protected function publicPromotions(?string $storeId = null): Builder
    {
        $about  = Storefront::about();
        $owners = [$about?->uuid];

        if ($about?->is_network) {
            $stores = Store::whereHas('networks', fn ($query) => $query->where('network_uuid', $about->uuid))
                ->when($storeId, fn ($query) => $query->where('public_id', $storeId))
                ->pluck('uuid')
                ->all();
            $owners = $storeId ? $stores : [...$owners, ...$stores];
        }

        return Promotion::query()
            ->whereIn('owner_uuid', array_filter($owners))
            ->where('is_public', true)
            ->with(['image', 'owner', 'codes']);
    }
}
