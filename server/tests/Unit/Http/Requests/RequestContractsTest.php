<?php

use Fleetbase\Storefront\Http\Requests\AddStoreToNetworkCategory;
use Fleetbase\Storefront\Http\Requests\CaptureOrderRequest;
use Fleetbase\Storefront\Http\Requests\CreateCustomerRequest;
use Fleetbase\Storefront\Http\Requests\CreateProductRequest;
use Fleetbase\Storefront\Http\Requests\CreateReviewRequest;
use Fleetbase\Storefront\Http\Requests\CreateStripeSetupIntentRequest;
use Fleetbase\Storefront\Http\Requests\GetServiceQuoteFromCart;
use Fleetbase\Storefront\Http\Requests\InitializeCheckoutRequest;
use Fleetbase\Storefront\Http\Requests\NetworkActionRequest;
use Fleetbase\Storefront\Http\Requests\StorefrontCustomerRequest;
use Fleetbase\Storefront\Http\Requests\UpdateProductRequest;
use Fleetbase\Storefront\Http\Requests\VerifyCreateCustomerRequest;
use Fleetbase\Storefront\Rules\CustomerExists;
use Fleetbase\Storefront\Rules\GatewayExists;
use Fleetbase\Storefront\Rules\IsValidLocation;
use Illuminate\Validation\Rules\RequiredIf;

test('public storefront requests require a storefront session key', function ($requestClass) {
    session(['storefront_key' => 'store_public_key']);
    expect((bool) (new $requestClass())->authorize())->toBeTrue();

    session(['storefront_key' => null]);
    expect((bool) (new $requestClass())->authorize())->toBeFalse();
})->with([
    CaptureOrderRequest::class,
    CreateStripeSetupIntentRequest::class,
    GetServiceQuoteFromCart::class,
    InitializeCheckoutRequest::class,
    StorefrontCustomerRequest::class,
]);

test('credential-aware storefront requests accept either storefront or API sessions', function ($requestClass) {
    request()->session()->forget('api_credential');
    session(['storefront_key' => 'network_public_key']);
    expect((bool) (new $requestClass())->authorize())->toBeTrue();

    session(['storefront_key' => null]);
    request()->session()->put('api_credential', 'credential_1');
    expect((bool) (new $requestClass())->authorize())->toBeTrue();

    request()->session()->forget('api_credential');
    expect((bool) (new $requestClass())->authorize())->toBeFalse();
})->with([
    CreateCustomerRequest::class,
    CreateProductRequest::class,
    CreateReviewRequest::class,
    VerifyCreateCustomerRequest::class,
]);

test('staff network requests require an authenticated user session', function ($requestClass) {
    session(['user' => 'user_1']);
    expect((new $requestClass())->authorize())->toBeTrue();

    session(['user' => null]);
    expect((new $requestClass())->authorize())->toBeFalse();
})->with([
    [AddStoreToNetworkCategory::class],
    [NetworkActionRequest::class],
]);

test('checkout initialization rules require core identities and delivery quote conditionally', function () {
    $deliveryRequest = InitializeCheckoutRequest::create('/checkout', 'POST', ['pickup' => false]);
    $pickupRequest   = InitializeCheckoutRequest::create('/checkout', 'POST', ['pickup' => true]);

    $deliveryRules = $deliveryRequest->rules();
    $pickupRules   = $pickupRequest->rules();

    expect($deliveryRules)->toHaveKeys(['gateway', 'customer', 'cart', 'serviceQuote', 'service_quote', 'cash', 'pickup'])
        ->and($deliveryRules['gateway'][1])->toBeInstanceOf(GatewayExists::class)
        ->and($deliveryRules['customer'][1])->toBeInstanceOf(CustomerExists::class)
        ->and($deliveryRules['serviceQuote'][0])->toBeInstanceOf(RequiredIf::class)
        ->and((string) $deliveryRules['serviceQuote'][0])->toBe('required')
        ->and((string) $deliveryRules['service_quote'][0])->toBe('required')
        ->and((string) $pickupRules['serviceQuote'][0])->toBe('')
        ->and((string) $pickupRules['service_quote'][0])->toBe('');
});

test('either spelling of the service quote satisfies the delivery requirement', function () {
    // The controller reads or(['serviceQuote', 'service_quote']), so validating only one spelling
    // rejected the other before the controller ran.
    $snakeRules = InitializeCheckoutRequest::create('/checkout', 'POST', ['pickup' => false, 'service_quote' => 'quote_1'])->rules();
    $camelRules = InitializeCheckoutRequest::create('/checkout', 'POST', ['pickup' => false, 'serviceQuote' => 'quote_1'])->rules();

    expect((string) $snakeRules['serviceQuote'][0])->toBe('')
        ->and((string) $snakeRules['service_quote'][0])->toBe('required')
        ->and((string) $camelRules['service_quote'][0])->toBe('')
        ->and((string) $camelRules['serviceQuote'][0])->toBe('required');
});

test('service quote request varies origin validation by storefront key type', function () {
    session(['storefront_key' => 'store_123']);
    $storeRules = (new GetServiceQuoteFromCart())->rules();

    session(['storefront_key' => 'network_123']);
    $networkRules = (new GetServiceQuoteFromCart())->rules();

    expect($storeRules['origin'][0])->toBe('required')
        ->and($storeRules['origin'][1])->toBeInstanceOf(IsValidLocation::class)
        ->and($storeRules['destination'][1])->toBeInstanceOf(IsValidLocation::class)
        ->and($storeRules['cart'])->toBe('required')
        ->and($networkRules['origin'])->toBe([]);
});

test('product request publishes complete create and update validation contracts', function () {
    $createRules = CreateProductRequest::create('/products', 'POST')->rules();
    $updateRules = UpdateProductRequest::create('/products/product_1', 'PATCH')->rules();

    expect($createRules)->toHaveKeys([
        'name',
        'description',
        'tags',
        'meta',
        'sku',
        'price',
        'sale_price',
        'currency',
        'addons',
        'variants',
        'is_service',
        'is_bookable',
        'is_available',
        'is_on_sale',
        'is_recommended',
        'can_pickup',
        'youtube_urls',
        'status',
        'category',
        'addon_categories',
    ])->and($createRules['price'][0])->toBeInstanceOf(RequiredIf::class)
        ->and((string) $createRules['price'][0])->toBe('required')
        ->and((string) $updateRules['price'][0])->toBe('')
        // `published` is what the eleven read paths filter on and what the console writes.
        // Leaving it out meant a product created through the public API could never be
        // seen by a network storefront, nor survive CheckoutController's cart validation.
        ->and($createRules['status'])->toContain('in:draft,active,archived,published')
        ->and($updateRules['status'])->toContain('in:draft,active,archived,published')
        ->and($createRules['currency'])->toContain('size:3');
});

test('customer review verification and capture request rules preserve API contracts', function () {
    $customerRules     = (new CreateCustomerRequest())->rules();
    $reviewRules       = (new CreateReviewRequest())->rules();
    $verificationRules = (new VerifyCreateCustomerRequest())->rules();
    $captureRules      = (new CaptureOrderRequest())->rules();
    $setupRules        = (new CreateStripeSetupIntentRequest())->rules();
    $storefrontRules   = (new StorefrontCustomerRequest())->rules();

    expect($customerRules)->toHaveKeys(['code', 'name', 'email', 'phone'])
        ->and($customerRules['code'])->toBe('required|exists:verification_codes,code')
        // `subject` is required: the controller resolves it with Utils::resolveSubject(),
        // whose parameter is a non-nullable string, so omitting it threw a TypeError as a
        // 500 before the controller's own "Invalid subject for review" guard could run.
        ->and($reviewRules)->toBe([
            'subject'  => 'required|string',
            'rating'   => 'required|numeric',
            'content'  => 'required',
            'files'    => 'sometimes|array',
            'rejected' => 'sometimes|boolean',
        ])->and($verificationRules)->toBe([
            'mode'     => 'required|in:email,sms',
            'identity' => 'required',
        ])->and($captureRules['token'])->toBe(['required', 'exists:storefront.checkouts,token'])
        ->and($setupRules['customer'][1])->toBeInstanceOf(CustomerExists::class)
        ->and($storefrontRules['customer'][1])->toBeInstanceOf(CustomerExists::class);
});

test('customer uniqueness rules scope active email and phone identities to the company', function () {
    session(['company' => 'company_uuid']);
    $rules        = (new CreateCustomerRequest())->rules();
    $emailBuilder = (new Fleetbase\FleetOps\Models\Contact())->newQuery();
    $phoneBuilder = (new Fleetbase\FleetOps\Models\Contact())->newQuery();
    $rules['email'][2]->queryCallbacks()[0]($emailBuilder);
    $rules['phone'][1]->queryCallbacks()[0]($phoneBuilder);

    expect($emailBuilder->toSql())->toContain('"company_uuid" = ?')
        ->and($emailBuilder->toSql())->toContain('"deleted_at" is null')
        ->and($emailBuilder->getBindings())->toContain('company_uuid')
        ->and($phoneBuilder->toSql())->toContain('"company_uuid" = ?')
        ->and($phoneBuilder->toSql())->toContain('"deleted_at" is null')
        ->and($phoneBuilder->getBindings())->toContain('company_uuid');
});

test('network route requests merge route identity into validation input', function ($requestClass, $expectedRules) {
    $request = $requestClass::create('/networks/network_uuid', 'POST', [
        'category' => 'category_uuid',
        'store'    => 'store_uuid',
    ]);
    $request->setRouteResolver(fn () => new class {
        public function parameter(string $key): ?string
        {
            return $key === 'id' ? 'network_uuid' : null;
        }
    });

    expect($request->all())->toMatchArray([
        'id'       => 'network_uuid',
        'category' => 'category_uuid',
        'store'    => 'store_uuid',
    ])->and($request->rules())->toBe($expectedRules);
})->with([
    'network action' => [
        NetworkActionRequest::class,
        [
            'id' => ['required', 'exists:storefront.networks,uuid'],
        ],
    ],
    'add store category' => [
        AddStoreToNetworkCategory::class,
        [
            'id'       => ['required', 'exists:storefront.networks,uuid'],
            'category' => ['required', 'exists:categories,uuid'],
            'store'    => ['required', 'exists:storefront.stores,uuid', 'exists:storefront.network_stores,store_uuid'],
        ],
    ],
]);
