<?php

namespace Fleetbase\Storefront\Http\Requests;

use Fleetbase\Http\Requests\FleetbaseRequest;
use Illuminate\Validation\Rule;

class CreateProductRequest extends FleetbaseRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return session('storefront_key') || request()->session()->has('api_credential');
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name'             => 'required|string|max:255',
            'description'      => 'nullable|string',
            'tags'             => 'nullable|array',
            'meta'             => 'nullable|array',
            'sku'              => 'nullable|string|max:100',
            'price'            => [Rule::requiredIf(fn () => $this->isMethod('POST')), 'numeric', 'min:0'],
            'sale_price'       => 'nullable|numeric|min:0',
            'currency'         => 'nullable|string|size:3',
            'addons'           => 'nullable|array',
            'variants'         => 'nullable|array',
            'is_service'       => 'nullable|boolean',
            'is_bookable'      => 'nullable|boolean',
            'is_available'     => 'nullable|boolean',
            'is_on_sale'       => 'nullable|boolean',
            'is_recommended'   => 'nullable|boolean',
            'can_pickup'       => 'nullable|boolean',
            'youtube_urls'     => 'nullable|array',
            // `published` is the status the rest of the module reads — Product::PUBLISHED,
            // the network branch of Cart::findProduct(), ProductController::find/query,
            // CategoryController, StoreController, and CheckoutController's cart validation
            // all filter on it, and the console writes it. Leaving it out of this list meant
            // a product created through the public API could never satisfy any of them.
            'status'           => 'nullable|string|in:draft,active,archived,published',
            'category'         => 'nullable',
            'addon_categories' => 'nullable|array',
        ];
    }
}
