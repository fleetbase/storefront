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
     * Query params: `store` (a store public id, to only list that store's promotions in a network).
     */
    public function query(Request $request)
    {
        $promotions = $this->publicPromotions($request->input('store'))
            ->orderByDesc('priority')
            ->orderBy('ends_at')
            ->get()
            ->filter(fn (Promotion $promotion) => $promotion->isLiveAt())
            ->values();

        return PromotionResource::collection($promotions);
    }

    public function find(string $id)
    {
        $promotion = $this->publicPromotions()->where('public_id', $id)->first();

        if (!$promotion || !$promotion->isLiveAt()) {
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
            ->where('status', Promotion::STATUS_ACTIVE)
            ->where('is_public', true)
            ->with('image');
    }
}
