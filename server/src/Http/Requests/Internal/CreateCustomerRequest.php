<?php

namespace Fleetbase\Storefront\Http\Requests\Internal;

use Fleetbase\Http\Requests\FleetbaseRequest;

/**
 * A customer added from the console (for example while placing an order for them): a name,
 * with an email and phone when known. The app's sign-up keeps its own verified request.
 */
class CreateCustomerRequest extends FleetbaseRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'  => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
        ];
    }
}
