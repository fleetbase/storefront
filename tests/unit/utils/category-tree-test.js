import { module, test } from 'qunit';
import { flattenCategoryTree, categoryAndDescendantIds, categoryHeight, parentCategoryOptions, categoryPath, MAX_CATEGORY_DEPTH } from '@fleetbase/storefront-engine/utils/category-tree';

module('Unit | Utility | category-tree', function () {
    // Drinks › Coffee › Cold brew, Drinks › Tea, Food
    const drinks = { id: 'drinks', name: 'Drinks', parent_uuid: null, order: 0 };
    const coffee = { id: 'coffee', name: 'Coffee', parent_uuid: 'drinks', order: 0 };
    const coldBrew = { id: 'cold_brew', name: 'Cold brew', parent_uuid: 'coffee', order: 0 };
    const tea = { id: 'tea', name: 'Tea', parent_uuid: 'drinks', order: 1 };
    const food = { id: 'food', name: 'Food', parent_uuid: null, order: 1 };
    const all = [food, coldBrew, tea, coffee, drinks];

    test('categories come out in tree order with their depth and path', function (assert) {
        assert.strictEqual(MAX_CATEGORY_DEPTH, 3);
        assert.deepEqual(
            flattenCategoryTree(all).map((row) => [row.category.id, row.depth, row.path, row.parentId]),
            [
                ['drinks', 0, 'Drinks', null],
                ['coffee', 1, 'Drinks › Coffee', 'drinks'],
                ['cold_brew', 2, 'Drinks › Coffee › Cold brew', 'coffee'],
                ['tea', 1, 'Drinks › Tea', 'drinks'],
                ['food', 0, 'Food', null],
            ]
        );
        assert.deepEqual(flattenCategoryTree(null), [], 'nothing to flatten');
        assert.deepEqual(
            flattenCategoryTree([{ id: 'orphan', name: 'Orphan', parent_uuid: 'gone' }]).map((row) => [row.category.id, row.depth, row.parentId]),
            [['orphan', 0, null]],
            'a category whose parent is not listed is at the top'
        );
    });

    test('a category knows what is under it and how many levels', function (assert) {
        assert.deepEqual([...categoryAndDescendantIds(all, drinks)].sort(), ['coffee', 'cold_brew', 'drinks', 'tea']);
        assert.strictEqual(categoryHeight(all, drinks), 2);
        assert.strictEqual(categoryHeight(all, coffee), 1);
        assert.strictEqual(categoryHeight(all, food), 0);
        assert.strictEqual(categoryPath(all, coldBrew), 'Drinks › Coffee › Cold brew');
        assert.strictEqual(categoryPath([], { name: 'Loose' }), 'Loose', 'a category outside the list is named as is');
    });

    test('a category can be placed under anything but itself, its subcategories, or too deep', function (assert) {
        const ids = (rows) => rows.map((row) => row.category.id);

        // A new category can go under any category above the third level.
        assert.deepEqual(ids(parentCategoryOptions(all)), ['drinks', 'coffee', 'tea', 'food']);
        // Food (nothing under it) can go under Coffee, but not under Cold brew (a fourth level).
        assert.deepEqual(ids(parentCategoryOptions(all, food)), ['drinks', 'coffee', 'tea']);
        // Coffee (Cold brew under it) only goes under a top-level category, and never under itself.
        assert.deepEqual(ids(parentCategoryOptions(all, coffee)), ['drinks', 'food']);
        // Drinks has two levels under it: it can only stay at the top.
        assert.deepEqual(ids(parentCategoryOptions(all, drinks)), []);
    });

    test("a tree can be read by other keys, as a catalog's unsaved categories are", function (assert) {
        const lunch = { key: 'new-lunch', name: 'Lunch', order: 0 };
        const tacos = { key: 'new-tacos', parent_key: 'new-lunch', name: 'Tacos', order: 1 };
        const rows = flattenCategoryTree([tacos, lunch], { idOf: (category) => category.key, parentOf: (category) => category.parent_key ?? null });

        assert.deepEqual(
            rows.map((row) => [row.category.key, row.depth, row.path]),
            [
                ['new-lunch', 0, 'Lunch'],
                ['new-tacos', 1, 'Lunch › Tacos'],
            ]
        );
    });
});
