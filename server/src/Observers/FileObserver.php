<?php

namespace Fleetbase\Storefront\Observers;

use Fleetbase\Models\File;
use Fleetbase\Storefront\Models\Product;
use Fleetbase\Support\ApiModelCache;

/**
 * Invalidates cached product lists when a product's images change.
 *
 * Product lists embed their images (`files`, and `primary_image_url` reads them),
 * but the files table is written by every upload in a company, so product lists
 * do not depend on it as a whole. Attaching, changing or deleting a file whose
 * subject is a product does not touch the product row, so it is handled here.
 */
class FileObserver
{
    public function saved(File $file): void
    {
        $this->invalidateProductLists($file);
    }

    public function deleted(File $file): void
    {
        $this->invalidateProductLists($file);
    }

    protected function invalidateProductLists(File $file): void
    {
        // The subject as written now and before this write, so moving a file off a product counts too
        foreach ([$file->subject_type, $file->getOriginal('subject_type')] as $subjectType) {
            if (is_string($subjectType) && is_a($subjectType, Product::class, true)) {
                ApiModelCache::invalidateModelCache(new Product(), $file->company_uuid);

                return;
            }
        }
    }
}
