<?php

namespace Fleetbase\Storefront\Http\Resources;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Storefront\Models\PromotionRedemption;
use Fleetbase\Support\Http;

class Promotion extends FleetbaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return array
     */
    public function toArray($request)
    {
        $internal = Http::isInternalRequest();

        return [
            'id'                       => $this->when($internal, $this->uuid, $this->public_id),
            'uuid'                     => $this->when($internal, $this->uuid),
            'public_id'                => $this->when($internal, $this->public_id),
            'company_uuid'             => $this->when($internal, $this->company_uuid),
            'owner_uuid'               => $this->when($internal, $this->owner_uuid),
            'owner_type'               => $this->when($internal, $this->owner_type),
            'name'                     => $this->name,
            'description'              => $this->description,
            'status'                   => $this->when($internal, $this->status),
            'trigger'                  => $this->trigger,
            'type'                     => $this->type,
            'value'                    => $this->value,
            'max_discount_amount'      => $this->max_discount_amount,
            'currency'                 => $this->currency,
            'min_subtotal'             => $this->min_subtotal,
            'min_items'                => $this->min_items,
            'owner'                    => $this->when(!$internal, fn () => $this->ownerSummary()),
            'code'                     => $this->when(!$internal, fn () => $this->shareableCode()),
            'availability'             => $this->availabilityAt(),
            'next_starts_at'           => $this->nextLiveAt(),
            'applies_to'               => $internal ? $this->applies_to : $this->publicAppliesTo(),
            'bogo_config'              => $this->bogo_config,
            'first_order_only'         => $this->first_order_only,
            'usage_limit'              => $this->when($internal, $this->usage_limit),
            'usage_limit_per_customer' => $this->usage_limit_per_customer,
            'budget_amount'            => $this->when($internal, $this->budget_amount),
            'stackable'                => $this->stackable,
            'priority'                 => $this->when($internal, $this->priority),
            'is_public'                => $this->when($internal, $this->is_public),
            'starts_at'                => $this->starts_at,
            'ends_at'                  => $this->ends_at,
            'schedule'                 => $this->schedule,
            'timezone'                 => $this->timezone,
            'image_uuid'               => $this->when($internal, $this->image_uuid),
            'image_url'                => $this->image?->url,
            'translations'             => $this->translations,
            'stats'                    => $this->when($internal, fn () => $this->stats()),
            'meta'                     => $this->when($internal, $this->meta),
            'created_at'               => $this->created_at,
            'updated_at'               => $this->updated_at,
        ];
    }

    protected function stats(): array
    {
        $redeemed = PromotionRedemption::where('promotion_uuid', $this->uuid)->where('status', PromotionRedemption::STATUS_REDEEMED);

        return [
            'redemptions'    => (clone $redeemed)->count(),
            'discount_given' => (int) (clone $redeemed)->sum('amount'),
        ];
    }
}
