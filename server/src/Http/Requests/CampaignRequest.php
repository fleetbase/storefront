<?php

namespace Fleetbase\Storefront\Http\Requests;

use Fleetbase\Http\Requests\FleetbaseRequest;
use Fleetbase\Storefront\Models\Campaign;

class CampaignRequest extends FleetbaseRequest
{
    public function authorize()
    {
        return request()->session()->has('user');
    }

    public function rules()
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name'           => [$required, 'string', 'max:191'],
            'title'          => [$required, 'string', 'max:191'],
            'body'           => [$required, 'string', 'max:2000'],
            'owner_uuid'     => [$required, 'string', CustomerSegmentRequest::ownedStorefrontRule()],
            'segment_uuid'   => ['nullable', 'string'],
            'promotion_uuid' => ['nullable', 'string'],
            'recipients'     => ['nullable', 'array'],
            'channels'       => ['nullable', 'array'],
            'channels.*'     => ['in:' . Campaign::CHANNEL_PUSH . ',' . Campaign::CHANNEL_INBOX],
            'action'         => ['nullable', 'array'],
            'action.type'    => ['nullable', 'in:promotion,product,store,category,url'],
            'send_at'        => ['nullable', 'date'],
            // Sending, sent and canceled are set by the send and cancel actions only.
            'status'         => ['sometimes', 'in:' . Campaign::STATUS_DRAFT . ',' . Campaign::STATUS_SCHEDULED],
        ];
    }
}
