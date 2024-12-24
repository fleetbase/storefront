<?php

namespace Fleetbase\Storefront\Http\Resources;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;
use Fleetbase\Support\Utils;

class Store extends FleetbaseResource
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
        $currency = $this->currency ?? 'USD';

        return [
            'id'              => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'            => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'       => $this->when(Http::isInternalRequest(), $this->public_id),
            'key'             => $this->when(Http::isInternalRequest(), $this->key),
            'company_uuid'    => $this->when(Http::isInternalRequest(), $this->company_uuid),
            'created_by_uuid' => $this->when(Http::isInternalRequest(), $this->created_by_uuid),
            'logo_uuid'       => $this->when(Http::isInternalRequest(), $this->logo_uuid),
            'backdrop_uuid'   => $this->when(Http::isInternalRequest(), $this->backdrop_uuid),
            'name'            => $this->name,
            'description'     => $this->description,
            'translations'    => $this->translations ?? [],
            'website'         => $this->website,
            'facebook'        => $this->facebook,
            'instagram'       => $this->instagram,
            'twitter'         => $this->twitter,
            'email'           => $this->email,
            'phone'           => $this->phone,
            'tags'            => $this->tags ?? [],
            'currency'        => $currency,
            'country'         => Utils::getCountryCodeByCurrency($currency, 'US'),
            'options'         => $this->formatOptions($this->options),
            'logo_url'        => $this->logo_url,
            'backdrop_url'    => $this->backdrop_url,
            'rating'          => $this->rating,
            'online'          => $this->online,
            'alertable'       => $this->alertable,
            'is_network'      => false,
            'is_store'        => true,
            'category'        => $this->when($request->filled('network') && ($request->has('with_category') || $request->inArray('with', 'category')), new Category($this->getNetworkCategoryUsingId($request->input('network')))),
            'networks'        => $this->when($request->boolean('with_networks') || $request->inArray('with', 'networks'), Network::collection($this->networks)),
            'locations'       => $this->when($request->boolean('with_locations') || $request->inArray('with', 'locations'), $this->locations->mapInto(StoreLocation::class)),
            'media'           => $this->when($request->boolean('with_media') || $request->inArray('with', 'media'), Media::collection($this->media)),
            'slug'            => $this->slug,
            'created_at'      => $this->created_at,
            'updated_at'      => $this->updated_at,
        ];
    }

    /**
     * Format the given options array by removing excluded keys.
     *
     * @param mixed $options the options array to format
     *
     * @return array the formatted options array
     */
    public function formatOptions($options = []): array
    {
        if (!is_array($options)) {
            return [];
        }

        $formattedOptions = [];
        $exclude          = ['alerted_for_new_order'];

        foreach ($options as $key => $value) {
            if (in_array($key, $exclude)) {
                continue;
            }

            $formattedOptions[$key] = $value;
        }

        return $formattedOptions;
    }
}
