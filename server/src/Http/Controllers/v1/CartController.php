<?php

namespace Fleetbase\Storefront\Http\Controllers\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Storefront\Http\Resources\Cart as StorefrontCart;
use Fleetbase\Storefront\Models\Cart;
use Illuminate\Http\Request;

class CartController extends Controller
{
    /**
     * The flag used to be passed through as `$create`, but Cart::retrieve()'s second
     * parameter is `$excludeCheckedout`. Every mutating action below took the `false`
     * default and so happily added to, updated, emptied or deleted a cart that had
     * already produced an order, while the GET action passed `true` and did not. The
     * name made that read as intentional. Let Cart::retrieve() keep its own default so
     * a checked-out cart is off limits everywhere.
     */
    protected function retrieveCart(?string $uniqueId): Cart
    {
        return Cart::retrieve($uniqueId);
    }

    /**
     * Retrieve or create a cart using a unique identifier. If no unique identifier is provided
     * one will be created.
     *
     * The injected Request is declared FIRST on purpose. Laravel resolves method
     * dependencies by splicing class-typed parameters in at their own index and filling
     * the remainder from the route parameters, in order. With the optional $uniqueId
     * first, GET /storefront/v1/carts (the route with no {uniqueId}) had nothing to put
     * at index 0, so the Request landed at index 1 and the call arrived with a hole:
     *
     *   ArgumentCountError: Too few arguments to function retrieve(), 1 passed
     *
     * The route WITH an id worked, which is why this looked like a cart problem rather
     * than a signature one. Five requests in the Storefront collection failed behind it.
     *
     * @return \Illuminate\Http\Response
     */
    public function retrieve(Request $request, ?string $uniqueId = null)
    {
        $cart = $this->retrieveCart($uniqueId);

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
