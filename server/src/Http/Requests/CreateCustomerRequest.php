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
            // The code is checked in CustomerController::create() against the newest live code
            // for the identity, with attempt counting. Phone codes are stored hashed, so the
            // plain code can't be looked up in verification_codes here.
            'code'  => 'required|string|max:32',
            'name'  => 'required',
            'email' => [
                'email', 'nullable', Rule::unique('contacts')->where(function ($query) {
                    $query->where('company_uuid', session('company'));

                    return $query->whereNull('deleted_at');
                }),
            ],
            'phone' => [
                'nullable', Rule::unique('contacts')->where(function ($query) {
                    $query->where('company_uuid', session('company'));

                    return $query->whereNull('deleted_at');
                }),
            ],
        ];
    }
}
