<?php

namespace Fleetbase\Storefront\Http\Resources;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class Catalog extends FleetbaseResource
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
        return [
            'id'                                 => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'                               => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'                          => $this->when(Http::isInternalRequest(), $this->public_id),
            'company_uuid'                       => $this->when(Http::isInternalRequest(), $this->company_uuid),
            'created_by_uuid'                    => $this->when(Http::isInternalRequest(), $this->created_by_uuid),
            'store_uuid'                         => $this->when(Http::isInternalRequest(), $this->store_uuid),
            'name'                               => $this->name,
            'description'                        => $this->description,
            'categories'                         => CatalogCategory::collection($this->categoriesWithParents()),
            'hours'                              => $this->when(Http::isInternalRequest(), $this->resource->relationLoaded('hours') ? $this->hours->map(fn ($hour) => ['id' => $hour->uuid, 'uuid' => $hour->uuid, 'catalog_uuid' => $hour->catalog_uuid, 'day_of_week' => $hour->day_of_week, 'start' => $hour->start, 'end' => $hour->end])->values() : []),
            'subjects'                           => $this->when(Http::isInternalRequest(), $this->subjectsSummary()),
            'status'                             => $this->status,
            'created_at'                         => $this->created_at,
            'updated_at'                         => $this->updated_at,
        ];
    }

    /**
     * The catalog's categories, each told its parent's public id for the public menu tree.
     */
    private function categoriesWithParents()
    {
        $categories = collect($this->categories ?? []);
        $publicIds  = $categories->pluck('public_id', 'uuid');

        return $categories->each(fn ($category) => $category->setAttribute('parent_public_id', $category->parent_uuid ? $publicIds->get($category->parent_uuid) : null));
    }

    /**
     * The stores and trucks this catalog is served by, in the shape the console lists them.
     */
    private function subjectsSummary(): array
    {
        if (!$this->resource->relationLoaded('assignments')) {
            return [];
        }

        return $this->assignments
            ->map(function ($assignment) {
                $subject = $assignment->subject;

                if (!$subject) {
                    return null;
                }

                $isTruck = $subject instanceof \Fleetbase\Storefront\Models\FoodTruck;
                $vehicle = $isTruck ? $subject->vehicle : null;

                return [
                    'id'         => $subject->uuid,
                    'uuid'       => $subject->uuid,
                    'public_id'  => $subject->public_id ?? null,
                    'type'       => $isTruck ? 'food-truck' : 'store',
                    'name'       => $isTruck ? ($vehicle?->display_name ?? $vehicle?->plate_number ?? $subject->public_id ?? 'Truck') : $subject->name,
                    'plate'      => $isTruck ? $vehicle?->plate_number : null,
                    'logo_url'   => $isTruck ? ($vehicle?->photo_url ?? null) : ($subject->logo_url ?? null),
                    // A truck serves while its Storefront status is active, whatever its vehicle reports.
                    'online'     => (bool) ($isTruck ? $subject->status === 'active' : $subject->online),
                    'status'     => $subject->status ?? null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
