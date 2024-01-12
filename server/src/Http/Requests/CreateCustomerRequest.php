<?php

namespace Fleetbase\Storefront\Http\Requests;

use Fleetbase\Http\Requests\FleetbaseRequest;
use Illuminate\Validation\Rule;

class CreateCustomerRequest extends FleetbaseRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return session('storefront_key') || request()->session()->has('api_credential');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'code'  => 'required|exists:verification_codes,code',
            'name'  => 'required',
            'email' => [
                'email', 'nullable', Rule::unique('contacts')->where(function ($query) {
                    return $query->whereNull('deleted_at');
                }),
            ],
            'phone' => [
                'nullable', Rule::unique('contacts')->where(function ($query) {
                    return $query->whereNull('deleted_at');
                }),
            ],
        ];
    }
}
