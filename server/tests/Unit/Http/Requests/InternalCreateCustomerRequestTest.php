<?php

use Fleetbase\Storefront\Http\Controllers\CustomerController;
use Fleetbase\Storefront\Http\Requests\Internal\CreateCustomerRequest;

test('a console-created customer needs a name and takes an optional email and phone', function () {
    $request = new CreateCustomerRequest();

    expect($request->authorize())->toBeTrue()
        ->and($request->rules())->toBe([
            'name'  => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
        ]);
});

test('the internal customers controller validates creates with the console request, not the app sign-up', function () {
    expect((new ReflectionClass(CustomerController::class))->getProperty('createRequest')->getDefaultValue())
        ->toBe(CreateCustomerRequest::class);
});
