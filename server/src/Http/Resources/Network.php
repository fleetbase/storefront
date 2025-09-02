<?php

namespace Fleetbase\Storefront\Http\Resources;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class Network extends FleetbaseResource
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
            'id'                        => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'                      => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'                 => $this->when(Http::isInternalRequest(), $this->public_id),
            'key'                       => $this->when(Http::isInternalRequest(), $this->key),
            'company_uuid'              => $this->when(Http::isInternalRequest(), $this->company_uuid),
            'created_by_uuid'           => $this->when(Http::isInternalRequest(), $this->created_by_uuid),
            'logo_uuid'                 => $this->when(Http::isInternalRequest(), $this->logo_uuid),
            'backdrop_uuid'             => $this->when(Http::isInternalRequest(), $this->backdrop_uuid),
            'order_config_uuid'         => $this->when(Http::isInternalRequest(), $this->order_config_uuid),
            'name'                      => $this->name,
            'description'               => $this->description,
            'translations'              => $this->translations ?? [],
            'website'                   => $this->website,
            'facebook'                  => $this->facebook,
            'instagram'                 => $this->instagram,
            'twitter'                   => $this->twitter,
            'email'                     => $this->email,
            'phone'                     => $this->phone,
            'tags'                      => $this->tags ?? [],
            'currency'                  => $this->currency ?? 'USD',
            'options'                   => $this->options ?? [],
            'alertable'                 => $this->alertable,
            'logo_url'                  => $this->logo_url,
            'backdrop_url'              => $this->backdrop_url,
            'rating'                    => $this->rating,
            'online'                    => $this->online,
            'stores'                    => $this->when($request->boolean('with_stores') || $request->inArray('with', 'stores'), Store::collection($this->stores)),
            'categories'                => $this->when($request->boolean('with_categories') || $request->inArray('with', 'categories'), Category::collection($this->categories)),
            'gateways'                  => $this->when($request->boolean('with_gateways') || $request->inArray('with', 'gateways'), Gateway::collection($this->gateways)),
            'notification_channels'     => $this->when($request->boolean('with_notification_channels') || $request->inArray('with', 'notification_channels'), NotificationChannel::collection($this->notificationChannels)),
            'is_network'                => true,
            'is_store'                  => false,
            'slug'                      => $this->slug,
            'created_at'                => $this->created_at,
            'updated_at'                => $this->updated_at,
        ];
    }
}
