<?php

namespace Fleetbase\Storefront\Models;

use Fleetbase\Models\Category;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

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

    public function setAddons(array $addons = []): AddonCategory
    {
        foreach ($addons as $addon) {
            // get uuid if set
            $id = data_get($addon, 'uuid');

            // make sure the cateogry is set to this current
            data_set($addon, 'category_uuid', $this->uuid);

            // make sure sale price is 0 if null
            if (data_get($addon, 'sale_price') === null) {
                data_set($addon, 'sale_price', 0);
            }

            // update product addon category
            if (Str::isUuid($id)) {
                ProductAddon::where('uuid', $id)->update(Arr::except($addon, ['uuid', 'created_at', 'updated_at']));
                continue;
            }

            // create new product addon category
            ProductAddon::create([
                'category_uuid'    => $this->uuid,
                'name'             => data_get($addon, 'name'),
                'description'      => data_get($addon, 'description'),
                'translations'     => data_get($addon, 'translations', []),
                'price'            => data_get($addon, 'price'),
                'sale_price'       => data_get($addon, 'sale_price'),
                'is_on_sale'       => data_get($addon, 'is_on_sale'),
            ]);
        }

        return $this;
    }
}
