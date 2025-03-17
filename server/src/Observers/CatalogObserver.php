<?php

namespace Fleetbase\Storefront\Observers;

use Fleetbase\Storefront\Models\Catalog;
use Fleetbase\Storefront\Models\CatalogCategory;
use Fleetbase\Storefront\Models\CatalogProduct;
use Illuminate\Support\Facades\Request;

class CatalogObserver
{
    /**
     * Handle the Catalog "saved" event.
     *
     * @param Catalog $catalog the Catalog that was saved
     */
    public function saved(Catalog $catalog): void
    {
        $categories = Request::input('catalog.categories', []);
        $catalog->setCategories($categories);
    }

    /**
     * Handle the Catalog "deleted" event.
     *
     * @param Catalog $catalog the Catalog that was created
     */
    public function deleted(Catalog $catalog): void
    {
        // Delete all the catalog category records
        $categories = CatalogCategory::where('owner_uuid', $catalog->uuid)->get();
        foreach ($categories as $category) {
            // Delete the category product records
            CatalogProduct::where('catalog_category_uuid', $category->uuid)->delete();

            // Delete the category itself
            $category->delete();
        }
    }
}
