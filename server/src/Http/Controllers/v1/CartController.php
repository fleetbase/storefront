<?php

namespace Fleetbase\Storefront\Http\Controllers\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Storefront\Http\Resources\Cart as StorefrontCart;
use Fleetbase\Storefront\Models\Cart;
use Illuminate\Http\Request;

class CartController extends Controller
{
    protected function retrieveCart(?string $uniqueId, bool $create = false): Cart
    {
        return Cart::retrieve($uniqueId, $create);
    }

    /**
     * Retrieve or create a cart using a unique identifier. If no unique identifier is provided
     * one will be created.
     *
     * @return \Illuminate\Http\Response
     */
    public function retrieve(?string $uniqueId = null, Request $request)
    {
        $cart = $this->retrieveCart($uniqueId, true);

        return new StorefrontCart($cart);
    }

    /**
     * Adds a product to cart and creates a line item for the product.
     *
     * @return \Illuminate\Http\Response
     */
    public function add(string $cartId, string $productId, Request $request)
    {
        $quantity        = $request->input('quantity', 1);
        $variants        = $request->input('variants', []);
        $addons          = $request->input('addons', []);
        $scheduledAt     = $request->input('scheduled_at');
        $storeLocationId = $request->input('store_location');
        $cart            = $this->retrieveCart($cartId);

        try {
            $cart->add($productId, $quantity, $variants, $addons, $storeLocationId, $scheduledAt);
        } catch (\Exception $e) {
            return response()->error($e->getMessage());
        }

        return new StorefrontCart($cart);
    }

    /**
     * Update a line item in the cart.
     *
     * @param string $cartItemId - can be either product id or line item id
     *
     * @return \Illuminate\Http\Response
     */
    public function update(string $cartId, string $cartItemId, Request $request)
    {
        $quantity    = $request->input('quantity', null);
        $variants    = $request->input('variants', null);
        $addons      = $request->input('addons', null);
        $scheduledAt = $request->input('scheduled_at');
        $cart        = $this->retrieveCart($cartId);

        try {
            $cart->updateItem($cartItemId, $quantity, $variants, $addons, $scheduledAt);
        } catch (\Exception $e) {
            return response()->error($e->getMessage());
        }

        return new StorefrontCart($cart);
    }

    /**
     * Removes a line item in the cart.
     *
     * @param string $cartItemId - can be either product id or line item id
     *
     * @return \Illuminate\Http\Response
     */
    public function remove(?string $cartId, ?string $cartItemId, Request $request)
    {
        $cart = $this->retrieveCart($cartId);

        try {
            $cart->remove($cartItemId);
        } catch (\Exception $e) {
            return response()->error($e->getMessage());
        }

        return new StorefrontCart($cart);
    }

    /**
     * Empties a cart.
     *
     * @return \Illuminate\Http\Response
     */
    public function empty(string $cartId)
    {
        $cart = $this->retrieveCart($cartId);

        $cart->empty();

        return new StorefrontCart($cart);
    }

    /**
     * Deletes a cart.
     *
     * @return \Illuminate\Http\Response
     */
    public function delete(string $cartId)
    {
        $cart = $this->retrieveCart($cartId);

        $cart->delete();

        return response()->json([]);
    }
}
