<?php

namespace Fleetbase\Storefront\Http\Resources;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class Category extends FleetbaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        return [
            'id'          => $this->public_id,
            'uuid'        => $this->when(Http::isInternalRequest(), $this->uuid),
            'name'        => $this->name,
            'description' => $this->description,
            'icon_url'    => $this->icon_url,
            'parent'      => $this->whenLoaded(
                'parentCategory',
                function ($parentCategory) {
                    return $parentCategory->public_id;
                }
            ),
            'tags'          => $this->tags ?? [],
            'translations'  => $this->translations ?? [],
            'products'      => $this->when($request->has('with_products') || $request->inArray('with', 'products'), Product::collection($this->products)),
            'subcategories' => $this->when(
                $request->has('with_subcategories') || $request->inArray('with', 'subcategories'),
                array_map(
                    function ($subCategory) {
                        return new Category($subCategory);
                    },
                    $this->subCategories->toArray()
                )
            ),
            'order'      => $this->order,
            'slug'       => $this->slug,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
