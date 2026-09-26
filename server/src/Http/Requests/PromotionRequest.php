<?php

namespace Fleetbase\Storefront\Http\Requests;

use Fleetbase\Http\Requests\FleetbaseRequest;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Promotion;
use Fleetbase\Storefront\Models\Store;

class PromotionRequest extends FleetbaseRequest
{
    public function authorize()
    {
        return request()->session()->has('user');
    }

    public function rules()
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name'                     => [$required, 'string', 'max:191'],
            'type'                     => [$required, 'in:' . implode(',', Promotion::TYPES)],
            'trigger'                  => ['sometimes', 'in:' . Promotion::TRIGGER_AUTOMATIC . ',' . Promotion::TRIGGER_CODE],
            'status'                   => ['sometimes', 'in:' . implode(',', Promotion::STATUSES)],
            'owner_uuid'               => [$required, 'string', function ($attribute, $value, $fail) {
                $owned = fn ($model) => $model::where('uuid', $value)->where('company_uuid', session('company'))->exists();
                if (!$owned(Store::class) && !$owned(Network::class)) {
                    $fail('The promotion must belong to one of your stores or networks.');
                }
            }],
            'value'                        => ['nullable', 'numeric', 'min:0', 'required_if:type,' . Promotion::TYPE_PERCENTAGE . ',' . Promotion::TYPE_FIXED_AMOUNT],
            'max_discount_amount'          => ['nullable', 'integer', 'min:0'],
            'currency'                     => ['nullable', 'string', 'size:3'],
            'min_subtotal'                 => ['nullable', 'integer', 'min:0'],
            'min_items'                    => ['nullable', 'integer', 'min:1'],
            'applies_to'                   => ['nullable', 'array'],
            'bogo_config'                  => ['nullable', 'array'],
            'bogo_config.buy_quantity'     => ['nullable', 'integer', 'min:1'],
            'bogo_config.get_quantity'     => ['nullable', 'integer', 'min:1'],
            'bogo_config.discount_percent' => ['nullable', 'numeric', 'between:0,100'],
            'first_order_only'             => ['sometimes', 'boolean'],
            'usage_limit'                  => ['nullable', 'integer', 'min:1'],
            'usage_limit_per_customer'     => ['nullable', 'integer', 'min:1'],
            'budget_amount'                => ['nullable', 'integer', 'min:1'],
            'stackable'                    => ['sometimes', 'boolean'],
            'priority'                     => ['sometimes', 'integer'],
            'is_public'                    => ['sometimes', 'boolean'],
            'starts_at'                    => ['nullable', 'date'],
            'ends_at'                      => ['nullable', 'date', function ($attribute, $value, $fail) {
                $startsAt = $this->input('promotion.starts_at', $this->input('starts_at'));
                if ($value && $startsAt && strtotime($value) <= strtotime($startsAt)) {
                    $fail('The end date must be after the start date.');
                }
            }],
            'schedule'                     => ['nullable', 'array'],
            'schedule.*.days'              => ['sometimes', 'array'],
            'schedule.*.days.*'            => ['integer', 'between:1,7'],
            'schedule.*.start'             => ['sometimes', 'date_format:H:i'],
            'schedule.*.end'               => ['sometimes', 'date_format:H:i'],
            'timezone'                     => ['nullable', 'timezone'],
        ];
    }
}
