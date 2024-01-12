<?php

namespace Fleetbase\Storefront\Http\Requests;

use Fleetbase\Http\Requests\FleetbaseRequest;
use Fleetbase\Rules\IsValidLocation;
use Illuminate\Support\Str;

class GetServiceQuoteFromCart extends FleetbaseRequest
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
        // valid origin is only required if store_ key
        return [
            'origin'      => $this->isStoreKey() ? ['required',  new IsValidLocation()] : [],
            'destination' => ['required', new IsValidLocation()],
            'cart'        => 'required',
        ];
    }

    private function isStoreKey()
    {
        return Str::startsWith(session('storefront_key'), 'store_');
    }
}
