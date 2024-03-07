<?php

namespace Fleetbase\Storefront\Http\Requests;

use Fleetbase\Http\Requests\FleetbaseRequest;
use Fleetbase\Storefront\Rules\CustomerExists;
use Fleetbase\Storefront\Rules\GatewayExists;

class ConfirmOrderRequest extends FleetbaseRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return session('storefront_key');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'gateway'           => ['required', new GatewayExists()],
            'paymentIntent'     => ['required'],
            'card'              => ['required']
        ];
    }
}
