<?php

namespace Fleetbase\Storefront\Http\Resources;

use Fleetbase\Http\Resources\FleetbaseResource;

class Campaign extends FleetbaseResource
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
            'id'             => $this->uuid,
            'uuid'           => $this->uuid,
            'public_id'      => $this->public_id,
            'owner_uuid'     => $this->owner_uuid,
            'owner_type'     => $this->owner_type,
            'segment_uuid'   => $this->segment_uuid,
            'segment_name'   => $this->segment?->name,
            'promotion_uuid' => $this->promotion_uuid,
            'promotion_name' => $this->promotion?->name,
            'recipients'     => $this->recipients ?? [],
            'name'           => $this->name,
            'status'         => $this->status,
            'channels'       => $this->enabledChannels(),
            'title'          => $this->title,
            'body'           => $this->body,
            'image_uuid'     => $this->image_uuid,
            'image_url'      => $this->image_uuid ? $this->image?->url : null,
            'action'         => $this->action,
            'send_at'        => $this->send_at,
            'started_at'     => $this->started_at,
            'sent_at'        => $this->sent_at,
            'stats'          => $this->stats ?? (object) [],
            'meta'           => $this->meta,
            'created_at'     => $this->created_at,
            'updated_at'     => $this->updated_at,
        ];
    }
}
