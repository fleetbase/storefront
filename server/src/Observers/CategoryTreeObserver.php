<?php

namespace Fleetbase\Storefront\Observers;

use Fleetbase\Models\Category;
use Fleetbase\Storefront\Support\CategoryTree;
use Fleetbase\Support\ApiModelCache;

/**
 * Keeps store product and catalog categories a valid tree: a parent in the same store or
 * catalog, no loops, at most three levels. Removing a category moves its subcategories up.
 */
class CategoryTreeObserver
{
    public function saving(Category $category): void
    {
        if (!CategoryTree::nests($category) || !$category->isDirty('parent_uuid') || !$category->parent_uuid) {
            return;
        }

        $parent = Category::where('uuid', $category->parent_uuid)->first();
        if (!$parent) {
            CategoryTree::fail('The parent category no longer exists.');
        }

        CategoryTree::assertValidParent($category, $parent);
    }

    public function deleting(Category $category): void
    {
        if (!CategoryTree::nests($category)) {
            return;
        }

        $moved = Category::where('parent_uuid', $category->uuid)->update(['parent_uuid' => $category->parent_uuid]);

        // A builder update fires no model events, so the cached category lists are told here.
        if ($moved > 0) {
            ApiModelCache::invalidateModelCache(new Category(), $category->company_uuid);
        }
    }
}
