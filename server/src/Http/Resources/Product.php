<?php

namespace Fleetbase\Storefront\Http\Resources;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class Product extends FleetbaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id' => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid' => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id' => $this->when(Http::isInternalRequest(), $this->public_id),
            'name' => $this->name,
            'description' => $this->description,
            'sku' => $this->sku,
            'primary_image_url' => $this->primary_image_url,
            'price' => $this->price,
            'sale_price' => $this->sale_price,
            'currency' => $this->currency,
            'is_on_sale' => $this->is_on_sale,
            'is_recommended' => $this->is_recommended,
            'is_service' => $this->is_service,
            'is_bookable' => $this->is_bookable,
            'is_available' => $this->is_available,
            'tags' => $this->tags ?? [],
            'status' => $this->status,
            'slug' => $this->slug,
            'translations' => $this->translations ?? [],
            'addon_categories' => $this->mapAddonCategories($this->addonCategories),
            'variants' => $this->mapVariants($this->variants),
            'files' => $this->when(Http::isInternalRequest(), $this->files),
            'images' => $this->when(!Http::isInternalRequest(), $this->mapFiles($this->files)),
            'videos' => $this->when(!Http::isInternalRequest(), $this->mapFiles($this->files, 'video')),
            'hours' => $this->mapHours($this->hours),
            'youtube_urls' => $this->youtube_urls ?? [],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    public function mapHours(?\Illuminate\Database\Eloquent\Collection $hours = null): array
    {
        if (empty($hours)) {
            return [];
        }

        return array_map(
            function ($hour) {
                // modify for internal requests
                if (Http::isInternalRequest()) {
                    $hour['id'] = data_get($hour, 'id');
                    $hour['uuid'] = data_get($hour, 'uuid');
                }

                return array_merge($hour, [
                    'day' => data_get($hour, 'day_of_week'),
                    'start' => data_get($hour, 'start'),
                    'end' => data_get($hour, 'end'),
                ]);
            },
            $hours->toArray()
        );
    }

    public function mapFiles(?\Illuminate\Database\Eloquent\Collection $files = null, $contentType = 'image')
    {
        return collect($files)->map(function ($file) use ($contentType) {
            if (!Str::contains($file->content_type, $contentType)) {
                return null;
            }

            return $file->url;
        })->filter()->values();
    }

    public function mapAddonCategories(?\Illuminate\Database\Eloquent\Collection $addonCategories = null)
    {
        return collect($addonCategories)->map(function ($addonCategory) {
            $addonCategoryArr = [
                'id' => $addonCategory->category->public_id ?? null,
                'name' => $addonCategory->name,
                'description' => $addonCategory->category->description ?? null,
                'addons' => $this->mapProductAddons($addonCategory->category->addons ?? [], $addonCategory->excluded_addons)
            ];

            // modify for internal requests
            if (Http::isInternalRequest()) {
                $addonCategoryArr['id'] = $addonCategory->category->id;

                $addonCategoryArr = Arr::insertAfterKey(
                    $addonCategoryArr,
                    [
                        'uuid' => $addonCategory->category->uuid,
                        'public_id' => $addonCategory->category->public_id
                    ],
                    'id'
                );
            }

            return $addonCategoryArr;
        });
    }

    public function mapProductAddons(?\Illuminate\Database\Eloquent\Collection $addons = null, $excluded = [])
    {
        return collect($addons)->map(function ($addon) use ($excluded) {
            if (is_array($excluded) && in_array($addon->uuid, $excluded)) {
                return null;
            }

            $productAddonArr = [
                'id' => $addon->public_id,
                'name' => $addon->name,
                'description' => $addon->description,
                'price' => $addon->price,
                'sale_price' => $addon->sale_price,
                'is_on_sale' => $addon->is_on_sale,
                'slug' => $addon->slug,
                'created_at' => $addon->created_at,
                'updated_at' => $addon->updated_at
            ];

            // modify for internal requests
            if (Http::isInternalRequest()) {
                $productAddonArr['id'] = $addon->id;

                $productAddonArr = Arr::insertAfterKey(
                    $productAddonArr,
                    [
                        'uuid' => $addon->uuid,
                        'public_id' => $addon->public_id
                    ],
                    'id'
                );
            }

            return $productAddonArr;
        })->filter()->values();
    }

    public function mapVariants(?\Illuminate\Database\Eloquent\Collection $variants = null)
    {
        return collect($variants)->map(function ($variant) {
            $productVariantArr = [
                'id' => $variant->public_id,
                'name' => $variant->name,
                'description' => $variant->description,
                'is_multiselect' => $variant->is_multiselect,
                'is_required' => $variant->is_required,
                'slug' => $variant->slug,
                'options' => collect($variant->options)->map(function ($variantOpt) {
                    $variantOptArr = [
                        'id' => $variantOpt->public_id,
                        'name' => $variantOpt->name,
                        'description' => $variantOpt->description,
                        'additional_cost' => $variantOpt->additional_cost,
                        'created_at' => $variantOpt->created_at,
                        'updated_at' => $variantOpt->updated_at
                    ];

                    // modify for internal requests
                    if (Http::isInternalRequest()) {
                        $variantOptArr['id'] = $variantOpt->id;

                        $variantOptArr = Arr::insertAfterKey(
                            $variantOptArr,
                            [
                                'uuid' => $variantOpt->uuid,
                                'public_id' => $variantOpt->public_id
                            ],
                            'id'
                        );
                    }

                    return $variantOptArr;
                }),
                'created_at' => $variant->created_at,
                'updated_at' => $variant->updated_at
            ];

            // modify for internal requests
            if (Http::isInternalRequest()) {
                $productVariantArr['id'] = $variant->id;

                $productVariantArr = Arr::insertAfterKey(
                    $productVariantArr,
                    [
                        'uuid' => $variant->uuid,
                        'public_id' => $variant->public_id
                    ],
                    'id'
                );
            }

            return $productVariantArr;
        });
    }
}
