<?php

use Fleetbase\Storefront\Models\AddonCategory;
use Fleetbase\Storefront\Models\Catalog;
use Fleetbase\Storefront\Models\FoodTruck;
use Fleetbase\Storefront\Models\Product;
use Fleetbase\Storefront\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The `$apiCacheDependencies` a model declares.
 *
 * @return string[]
 */
function declaredApiCacheDependencies(Model $model): array
{
    $property = new ReflectionProperty($model, 'apiCacheDependencies');
    $property->setAccessible(true);

    return $property->getValue($model);
}

test('cached models declare cache dependencies that are relation paths or storefront tables', function (string $modelClass, array $expected) {
    /** @var Model $model */
    $model    = new $modelClass();
    $declared = declaredApiCacheDependencies($model);
    $tables   = [(new Store())->getTable(), (new FoodTruck())->getTable()];

    expect($declared)->toBe($expected);

    foreach ($declared as $path) {
        $segments = explode('.', $path);

        // A single segment that is no method on the model is a table name (morphTo targets)
        if (count($segments) === 1 && !method_exists($model, $path)) {
            expect($tables)->toContain($path);
            continue;
        }

        $current = $model;
        foreach ($segments as $segment) {
            expect(method_exists($current, $segment))->toBeTrue("{$modelClass}: {$path} has no relation {$segment}");

            $parent   = $current;
            $relation = Relation::noConstraints(fn () => $parent->newInstance()->{$segment}());

            expect($relation)->toBeInstanceOf(Relation::class)
                ->and($relation)->not->toBeInstanceOf(MorphTo::class);

            $current = $relation->getRelated();
        }
    }
})->with([
    'catalog'        => [Catalog::class, ['categories', 'categories.products', 'hours', 'assignments', 'stores', 'food_trucks']],
    'store'          => [Store::class, ['reviews', 'networks']],
    'food truck'     => [FoodTruck::class, ['store', 'store.networks', 'network', 'vehicle', 'vehicle.driver', 'serviceArea', 'serviceArea.zones', 'zone', 'catalogs']],
    'product'        => [Product::class, ['addonCategories', 'addonCategories.category', 'addonCategories.category.addons', 'variants', 'variants.options', 'files', 'store', 'store.networks', 'catalogCategories']],
    'addon category' => [AddonCategory::class, ['addons']],
]);
