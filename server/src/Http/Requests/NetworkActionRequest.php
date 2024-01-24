<?php

namespace Fleetbase\Storefront\Http\Requests;

use Fleetbase\Http\Requests\FleetbaseRequest;

class NetworkActionRequest extends FleetbaseRequest
{
    /**
     * Override the all method to include $id.
     *
     * @return array
     */
    public function all($keys = null)
    {
        $data       = parent::all($keys);
        $data['id'] = $this->route('id');

        return $data;
    }

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return (bool) session('user');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'id' => ['required', 'exists:storefront.networks,uuid'],
        ];
    }
}
