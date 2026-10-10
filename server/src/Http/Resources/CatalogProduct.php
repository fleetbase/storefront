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
        $pivot            = $this->resource->pivot ?? null;
        $catalogPrice     = $pivot && $pivot->price !== null ? (int) $pivot->price : null;
        $catalogAvailable = $pivot && $pivot->is_available !== null ? (bool) $pivot->is_available : null;

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
            // A catalog price replaces the store price (and any sale) while the product is sold
            // through this catalog; `store_price` keeps what the product costs elsewhere.
            'price'              => $catalogPrice ?? $this->price,
            'sale_price'         => $catalogPrice === null ? $this->sale_price : null,
            'store_price'        => $this->price,
            'catalog_price'      => $catalogPrice,
            'currency'           => $this->currency,
            'is_on_sale'         => $catalogPrice === null ? $this->is_on_sale : false,
            'is_recommended'     => $this->is_recommended,
            'is_service'         => $this->is_service,
            'is_bookable'        => $this->is_bookable,
            'is_available'       => $catalogAvailable ?? $this->is_available,
            'catalog_available'  => $catalogAvailable,
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
