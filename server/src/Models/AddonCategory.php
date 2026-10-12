<?php

namespace Fleetbase\Storefront\Models;

use Fleetbase\Casts\Money as MoneyCast;
use Fleetbase\Models\Category;
use Fleetbase\Support\ApiModelCache;
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
     * Override the boot method to set "for" automatically.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function (Category $model) {
            $model->for = 'storefront_product_addon';
        });
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function addons()
    {
        return $this->setConnection(config('storefront.connection.db'))->hasMany(ProductAddon::class, 'category_uuid');
    }

    public function setAddons(array $addons = []): AddonCategory
    {
        $updatedQuietly = false;
        foreach ($addons as $addon) {
            // get uuid if set
            $id = data_get($addon, 'uuid');

            // create an upsertable array
            $upsertableProductAddon = [
                'category_uuid'    => $this->uuid,
                'name'             => data_get($addon, 'name'),
                'description'      => data_get($addon, 'description'),
                'translations'     => data_get($addon, 'translations', []),
                'price'            => MoneyCast::apply($addon['price'] ?? 0),
                'sale_price'       => MoneyCast::apply($addon['sale_price'] ?? 0),
                'is_on_sale'       => data_get($addon, 'is_on_sale'),
            ];

            // update product addon category
            if (Str::isUuid($id)) {
                ProductAddon::where('uuid', $id)->update($upsertableProductAddon);
                $updatedQuietly = true;
                continue;
            }

            // create new product addon category
            ProductAddon::create([
                ...$upsertableProductAddon,
                'created_by_uuid' => session('user'),
            ]);
        }

        // Builder updates raise no model events, so the cached addon category and product lists are invalidated here
        if ($updatedQuietly) {
            ApiModelCache::invalidateModelCache(new ProductAddon(), $this->company_uuid);
        }

        return $this;
    }
}
