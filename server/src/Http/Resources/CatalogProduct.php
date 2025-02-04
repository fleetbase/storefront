<?php

namespace Fleetbase\Storefront\Http\Resources;

use Fleetbase\Support\Http;

class CatalogProduct extends Product
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
            'id'                 => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'               => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'          => $this->when(Http::isInternalRequest(), $this->public_id),
            'company_uuid'       => $this->when(Http::isInternalRequest(), $this->company_uuid),
            'store_uuid'         => $this->when(Http::isInternalRequest(), $this->store_uuid),
            'category_uuid'      => $this->when(Http::isInternalRequest(), $this->category_uuid),
            'created_by_uuid'    => $this->when(Http::isInternalRequest(), $this->created_by_uuid),
            'primary_image_uuid' => $this->when(Http::isInternalRequest(), $this->primary_image_uuid),
            'name'               => $this->name,
            'description'        => $this->description,
            'sku'                => $this->sku,
            'primary_image_url'  => $this->primary_image_url,
            'price'              => $this->price,
            'sale_price'         => $this->sale_price,
            'currency'           => $this->currency,
            'is_on_sale'         => $this->is_on_sale,
            'is_recommended'     => $this->is_recommended,
            'is_service'         => $this->is_service,
            'is_bookable'        => $this->is_bookable,
            'is_available'       => $this->is_available,
            'tags'               => $this->tags ?? [],
            'status'             => $this->status,
            'slug'               => $this->slug,
            'translations'       => $this->translations ?? [],
            'addon_categories'   => $this->mapAddonCategories($this->addonCategories),
            'variants'           => $this->mapVariants($this->variants),
            'files'              => $this->when(Http::isInternalRequest(), $this->files),
            'images'             => $this->when(!Http::isInternalRequest(), $this->mapFiles($this->files)),
            'videos'             => $this->when(!Http::isInternalRequest(), $this->mapFiles($this->files, 'video')),
            'hours'              => $this->mapHours($this->hours),
            'youtube_urls'       => $this->youtube_urls ?? [],
            'created_at'         => $this->created_at,
            'updated_at'         => $this->updated_at,
            'type'               => $this->when(Http::isInternalRequest(), 'product'),
        ];
    }
}
