<?php

namespace Fleetbase\Storefront\Rules;

use Fleetbase\Storefront\Models\Cart;
use Illuminate\Contracts\Validation\Rule;

class CartExists implements Rule
{
    /**
     * Determine if the validation rule passes.
     *
     * @param string $attribute
     *
     * @return bool
     */
    public function passes($attribute, $value)
    {
        return Cart::where(function ($query) use ($value) {
            $query->where('public_id', $value)
                ->orWhere('unique_identifier', $value);
        })->exists();
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return 'Cart session does not exists.';
    }
}
