<?php

namespace Fleetbase\Storefront\Observers;

use Fleetbase\Models\File;
use Fleetbase\Storefront\Models\Product;
use Illuminate\Support\Facades\Request;

class ProductObserver
{
    /**
     * Handle the Product "saved" event.
     *
     * @param Product $product the Product that was saved
     */
    public function saved(Product $product): void
    {
        $addonCategories = Request::input('product.addon_categories', []);
        $variants        = Request::input('product.variants', []);
        $files           = Request::input('product.files', []);

        // save addon categories
        $product->setAddonCategories($addonCategories);

        // save product variants
        $product->setProductVariants($variants);

        // set keys on files
        foreach ($files as $file) {
            $fileRecord = File::where('uuid', $file['uuid'])->first();
            $fileRecord->setKey($product);
        }
    }
}
