<?php

namespace Fleetbase\Storefront\Models;

use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;

class CatalogProduct extends Pivot
{
    use SoftDeletes;
    use HasUuid;

    protected $connection = 'storefront';
    protected $table      = 'catalog_category_products';
    protected $primaryKey = 'uuid';
    public $incrementing  = false;
    protected $fillable   = ['catalog_category_uuid', 'product_uuid'];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('storefront.connection.db'));
        parent::__construct($attributes);
    }
}
