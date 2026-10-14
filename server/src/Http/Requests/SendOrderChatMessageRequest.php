<?php

namespace Fleetbase\Storefront\Http\Requests;

use Fleetbase\Http\Requests\FleetbaseRequest;

class SendOrderChatMessageRequest extends FleetbaseRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return (bool) session('storefront_key');
    }

    /**
     * A message is text, up to four photos, or both.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'content'      => 'required_without:files|nullable|string|max:2000',
            'files'        => 'required_without:content|array|max:4',
            'files.*.data' => 'required_with:files|string',
            'files.*.type' => 'required_with:files|string|starts_with:image/',
        ];
    }
}
