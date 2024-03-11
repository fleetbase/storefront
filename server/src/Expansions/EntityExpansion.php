<?php

namespace Fleetbase\Storefront\Expansions;

use Fleetbase\Build\Expansion;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\Storefront\Models\Product;

class EntityExpansion implements Expansion
{
    /**
     * Get the target class to expand.
     *
     * @return string|Class
     */
    public static function target()
    {
        return Entity::class;
    }

    /**
     * Create a new Entity from a Storefront Product.
     *
     * @return Entity
     */
    public static function fromStorefrontProduct()
    {
        return static function (Product $product, $meta = []) {
            return new Entity([
                'company_uuid' => session('company'),
                'photo_uuid'   => $product->primary_image_uuid,
                'internal_id'  => $product->public_id,
                'name'         => $product->name,
                'description'  => $product->description,
                'currency'     => $product->currency,
                'sku'          => $product->sku,
                'price'        => $product->price,
                'sale_price'   => $product->sale_price,
                'meta'         => [
                    'product_id' => $product->public_id,
                    'image_url'  => $product->primary_image_url,
                    ...$meta,
                ],
            ]);
        };
    }
}
