<?php

namespace Fleetbase\Storefront\Http\Requests;

use Fleetbase\Http\Requests\FleetbaseRequest;

class CreateReviewRequest extends FleetbaseRequest
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
        // `subject` is required, and typing it matters: the controller resolves it with
        // Utils::resolveSubject(), whose parameter is a non-nullable string. A request
        // without a subject therefore threw a TypeError —
        //   Utils::resolveSubject(): Argument #1 ($publicId) must be of type string,
        //   null given
        // — as a 500, before the controller's own "Invalid subject for review" guard
        // could run. That guard was unreachable for the commonest way to get it wrong.
        return [
            'subject'  => 'required|string',
            'rating'   => 'required|numeric',
            'content'  => 'required',
            'files'    => 'sometimes|array',
            'rejected' => 'sometimes|boolean',
        ];
    }
}
