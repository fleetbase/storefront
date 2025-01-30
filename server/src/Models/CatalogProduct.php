<?php

namespace Fleetbase\Storefront\Models;

use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;

class CatalogProduct extends Pivot
{
    use SoftDeletes;
    use HasUuid;

    protected $table      = 'catalog_category_products';
    protected $primaryKey = 'uuid';
    public $incrementing  = false;
    protected $fillable   = ['catalog_category_uuid', 'product_uuid'];
}
