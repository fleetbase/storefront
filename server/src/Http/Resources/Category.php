<?php

namespace Fleetbase\Storefront\Http\Resources;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;
use Fleetbase\Support\Utils;

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
        $attributes = $this->resource->getAttributes();

        return [
            'id'          => $this->public_id,
            'uuid'        => $this->when(Http::isInternalRequest(), $this->uuid),
            'name'        => $this->name,
            'description' => $this->description,
            'icon_url'    => $this->icon_url,
            // The parent's public id and the level (1 at the top), from the store's category index;
            // an app builds the Drinks › Coffee › Cold brew tree from these.
            'parent'      => $this->when(
                array_key_exists('parent_public_id', $attributes) || $this->resource->relationLoaded('parentCategory'),
                fn () => array_key_exists('parent_public_id', $attributes) ? $attributes['parent_public_id'] : $this->parentCategory?->public_id
            ),
            'depth'         => $this->when(array_key_exists('depth', $attributes), fn () => (int) $attributes['depth']),
            'tags'          => Utils::arrayFrom($this->tags),
            'translations'  => $this->translations ?? [],
            'products'      => $this->when($request->has('with_products') || $request->inArray('with', 'products'), $this->products ? Product::collection($this->products) : []),
            'subcategories' => $this->when(
                $request->has('with_subcategories') || $request->inArray('with', 'subcategories'),
                fn () => Category::collection($this->resource->relationLoaded('subCategories') ? $this->subCategories : collect())
            ),
            'meta'       => data_get($this, 'meta', Utils::createObject()),
            'order'      => $this->order,
            'slug'       => $this->slug,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
