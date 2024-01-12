<?php

namespace Fleetbase\Storefront\Models;

use Fleetbase\Models\Category;

class AddonCategory extends Category
{
    /**
     * Relationships to auto load with driver.
     *
     * @var array
     */
    protected $with = ['addons'];

    /**
     * The key to use in the payload responses.
     */
    protected string $payloadKey = 'addon_category';

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function addons()
    {
        return $this->setConnection(config('storefront.connection.db'))->hasMany(ProductAddon::class, 'category_uuid');
    }
}
