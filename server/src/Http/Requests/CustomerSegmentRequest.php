<?php

namespace Fleetbase\Storefront\Http\Requests;

use Fleetbase\Http\Requests\FleetbaseRequest;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Store;

class CustomerSegmentRequest extends FleetbaseRequest
{
    public function authorize()
    {
        return request()->session()->has('user');
    }

    public function rules()
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name'                          => [$required, 'string', 'max:191'],
            'owner_uuid'                    => [$required, 'string', static::ownedStorefrontRule()],
            'rules'                         => ['nullable', 'array'],
            'rules.customers'               => ['nullable', 'array'],
            'rules.min_orders'              => ['nullable', 'integer', 'min:0'],
            'rules.max_orders'              => ['nullable', 'integer', 'min:0'],
            'rules.ordered_within_days'     => ['nullable', 'integer', 'min:1'],
            'rules.not_ordered_within_days' => ['nullable', 'integer', 'min:1'],
            'rules.joined_within_days'      => ['nullable', 'integer', 'min:1'],
            'rules.min_spent'               => ['nullable', 'integer', 'min:1'],
            'rules.has_push_device'         => ['nullable', 'boolean'],
        ];
    }

    /**
     * The value must be the uuid of one of the company's stores or networks.
     */
    public static function ownedStorefrontRule(): \Closure
    {
        return function ($attribute, $value, $fail) {
            $owned = fn ($model) => $model::where('uuid', $value)->where('company_uuid', session('company'))->exists();
            if (!$owned(Store::class) && !$owned(Network::class)) {
                $fail('The owner must be one of your stores or networks.');
            }
        };
    }
}
