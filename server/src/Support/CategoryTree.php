<?php

namespace Fleetbase\Storefront\Support;

use Fleetbase\Models\Category;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Store product categories and catalog (menu) categories nest up to three levels:
 * Drinks › Coffee › Cold brew. A category's parent is in the same store or catalog
 * (`owner_uuid`) and of the same kind (`for`).
 */
class CategoryTree
{
    public const MAX_DEPTH = 3;

    /** The kinds of category that nest. Network categories keep their own rules. */
    public const NESTED = ['storefront_product', 'storefront_catalog'];

    public static function nests(?Category $category): bool
    {
        return $category !== null && in_array($category->for, self::NESTED, true);
    }

    /**
     * The category's uuid and every uuid under it, walking down a level at a time.
     *
     * @return array<string>
     */
    public static function withDescendants(Category|string $category): array
    {
        $uuid  = $category instanceof Category ? $category->uuid : $category;
        $all   = [$uuid];
        $level = [$uuid];

        // A level-one category has at most two levels under it.
        for ($depth = 1; $depth < self::MAX_DEPTH && $level; $depth++) {
            $level = Category::whereIn('parent_uuid', $level)->pluck('uuid')->all();
            $level = array_values(array_diff($level, $all));
            $all   = array_merge($all, $level);
        }

        return $all;
    }

    /** How many levels sit under the category: 0 for a category without subcategories. */
    public static function height(Category $category): int
    {
        $height = 0;
        $level  = [$category->uuid];
        $seen   = [$category->uuid];

        while ($level && $height <= self::MAX_DEPTH) {
            $level = array_values(array_diff(Category::whereIn('parent_uuid', $level)->pluck('uuid')->all(), $seen));
            $seen  = array_merge($seen, $level);
            if ($level) {
                $height++;
            }
        }

        return $height;
    }

    /**
     * The categories above this one, nearest first.
     *
     * @return array<Category>
     */
    public static function ancestors(Category $category): array
    {
        $ancestors = [];
        $seen      = [$category->uuid];
        $parentId  = $category->parent_uuid;

        while ($parentId && !in_array($parentId, $seen, true) && count($ancestors) <= self::MAX_DEPTH) {
            $parent = Category::where('uuid', $parentId)->first();
            if (!$parent) {
                break;
            }
            $ancestors[] = $parent;
            $seen[]      = $parent->uuid;
            $parentId    = $parent->parent_uuid;
        }

        return $ancestors;
    }

    /** The category's level: 1 at the top. */
    public static function depth(Category $category): int
    {
        return count(static::ancestors($category)) + 1;
    }

    /**
     * Refuse a parent that would put the category under itself, in another store or catalog,
     * or more than three levels deep (counting the levels already under the category).
     *
     * @throws ValidationException
     */
    public static function assertValidParent(Category $category, ?Category $parent): void
    {
        if (!$parent) {
            return;
        }

        if ($parent->owner_uuid !== $category->owner_uuid || $parent->for !== $category->for) {
            static::fail('A category can only be placed under a category of the same store or menu.');
        }

        if ($category->exists && in_array($parent->uuid, static::withDescendants($category), true)) {
            static::fail('A category cannot be placed under itself or one of its own subcategories.');
        }

        $height = $category->exists ? static::height($category) : 0;
        if (static::depth($parent) + 1 + $height > self::MAX_DEPTH) {
            static::fail('Categories nest at most three levels deep.');
        }
    }

    /**
     * Check a whole tree before it is written: `$parents` maps each key to its parent's key (or
     * null). Every parent must be in the tree, and no branch may loop or run past three levels.
     *
     * @param array<string, string|null> $parents
     *
     * @throws ValidationException
     */
    public static function assertValidTree(array $parents): void
    {
        foreach (array_keys($parents) as $key) {
            $depth = 1;
            $seen  = [$key];
            $at    = $parents[$key];

            while ($at !== null) {
                if (!array_key_exists($at, $parents)) {
                    static::fail('A category was placed under a category that is not in this menu.');
                }
                if (in_array($at, $seen, true)) {
                    static::fail('A category cannot be placed under itself or one of its own subcategories.');
                }
                $seen[] = $at;
                $at     = $parents[$at];
                $depth++;
            }

            if ($depth > self::MAX_DEPTH) {
                static::fail('Categories nest at most three levels deep.');
            }
        }
    }

    /**
     * Keys ordered so that every parent comes before its children.
     *
     * @param array<string, string|null> $parents a tree assertValidTree() accepted
     *
     * @return array<string>
     */
    public static function topDown(array $parents): array
    {
        $depthOf = function (string $key) use ($parents): int {
            $depth = 1;
            for ($at = $parents[$key]; $at !== null; $at = $parents[$at]) {
                $depth++;
            }

            return $depth;
        };

        $keys = array_keys($parents);
        usort($keys, fn ($a, $b) => $depthOf($a) <=> $depthOf($b));

        return $keys;
    }

    /**
     * A category index for one store or catalog: each category's parent's public id and its depth,
     * from the flat list, without a query per category.
     *
     * @return array<string, array{parent: ?string, depth: int}> by uuid
     */
    public static function index(Collection $categories): array
    {
        $byUuid = $categories->keyBy('uuid');
        $index  = [];

        foreach ($byUuid as $uuid => $category) {
            $depth    = 1;
            $seen     = [$uuid];
            $parentId = $category->parent_uuid;

            while ($parentId && $byUuid->has($parentId) && !in_array($parentId, $seen, true)) {
                $seen[]   = $parentId;
                $parentId = $byUuid->get($parentId)->parent_uuid;
                $depth++;
            }

            $index[$uuid] = [
                'parent' => $category->parent_uuid ? data_get($byUuid->get($category->parent_uuid), 'public_id') : null,
                'depth'  => $depth,
            ];
        }

        return $index;
    }

    /** @throws ValidationException */
    public static function fail(string $message): void
    {
        throw ValidationException::withMessages(['parent' => [$message]]);
    }
}
