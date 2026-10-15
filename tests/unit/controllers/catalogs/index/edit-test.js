import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import EmberObject from '@ember/object';
import { A } from '@ember/array';

module('Unit | Controller | catalogs/index/edit', function (hooks) {
    setupTest(hooks);

    test('removeServedBy sends the remaining stores and trucks back and toasts the removal', async function (assert) {
        const controller = this.owner.lookup('controller:catalogs/index/edit');
        const sent = [];
        const toasts = [];
        const store = { id: 'store_1', type: 'store', name: 'Market' };
        const truckA = { id: 'truck_a', type: 'food-truck', name: 'Truck 10' };
        const truckB = { id: 'truck_b', type: 'food-truck', name: 'Truck 12' };

        controller.catalog = {
            storeSubjects: [store],
            truckSubjects: [truckA, truckB],
            assignSubjects: (payload) => {
                sent.push(payload);
                return Promise.resolve([]);
            },
        };
        controller.notifications = { success: (message) => toasts.push(message), serverError: (error) => toasts.push(`error:${error.message}`) };
        controller.intl = { t: (key, params) => `${key}:${params?.name ?? ''}` };
        controller.touch = () => {};

        await controller.removeServedBy(truckA);

        assert.deepEqual(sent, [{ stores: [store], food_trucks: [truckB] }], 'the removed truck is left out and the rest are kept');
        assert.deepEqual(toasts, ['storefront.catalogs.editor.served-by-removed:Truck 10']);
    });

    test('removeServedBy reports a server error instead of throwing', async function (assert) {
        const controller = this.owner.lookup('controller:catalogs/index/edit');
        const toasts = [];

        controller.catalog = { storeSubjects: [], truckSubjects: [{ id: 'truck_a', type: 'food-truck', name: 'Truck 10' }], assignSubjects: () => Promise.reject(new Error('nope')) };
        controller.notifications = { success: () => {}, serverError: (error) => toasts.push(error.message) };
        controller.intl = { t: (key) => key };

        await controller.removeServedBy({ id: 'truck_a', name: 'Truck 10' });

        assert.deepEqual(toasts, ['nope']);
    });

    /** A catalog with Lunch › Tacos › Vegetarian and Drinks, saved, as the editor holds it. */
    function nestedMenu(controller) {
        const category = (attributes) => EmberObject.create({ products: [], parent_uuid: null, parent_key: null, key: null, ...attributes });
        const lunch = category({ id: 'lunch', name: 'Lunch', order: 0 });
        const tacos = category({ id: 'tacos', name: 'Tacos', parent_uuid: 'lunch', order: 1 });
        const veggie = category({ id: 'veggie', name: 'Vegetarian', parent_uuid: 'tacos', order: 2 });
        const drinks = category({ id: 'drinks', name: 'Drinks', order: 3 });

        controller.catalog = { categories: A([lunch, tacos, veggie, drinks]) };
        controller.intl = { t: (key) => key };
        controller.store = { createRecord: (type, attributes) => category({ id: null, ...attributes }) };
        controller.modalsManager = { confirm: ({ confirm }) => confirm({ done() {} }) };

        return { lunch, tacos, veggie, drinks };
    }

    const shape = (controller) => controller.categoryRows.map((row) => `${'-'.repeat(row.depth)}${row.category.name}`);

    test('the menu reads as a tree, and only moves that keep it three levels deep are offered', function (assert) {
        const controller = this.owner.lookup('controller:catalogs/index/edit');
        nestedMenu(controller);
        const rows = Object.fromEntries(controller.categoryRows.map((row) => [row.category.name, row]));

        assert.deepEqual(shape(controller), ['Lunch', '-Tacos', '--Vegetarian', 'Drinks']);
        assert.strictEqual(rows.Vegetarian.path, 'Lunch › Tacos › Vegetarian');
        assert.true(rows.Drinks.canIndent, 'Drinks can go under Lunch, the category above it at its level');
        assert.false(rows.Tacos.canIndent, 'Tacos is the first under Lunch: nothing above it to go under');
        assert.false(rows.Vegetarian.canAddSubcategory, 'a third-level category takes no subcategories');
        assert.true(rows.Tacos.canAddSubcategory);
        assert.false(rows.Lunch.canOutdent);
        assert.true(rows.Vegetarian.canOutdent);
        assert.true(rows.Lunch.isFirst && rows.Drinks.isLast);
    });

    test('a category is indented under the one above, moved up a level, given a subcategory, and moved among its siblings', function (assert) {
        const controller = this.owner.lookup('controller:catalogs/index/edit');
        const { lunch, veggie, drinks } = nestedMenu(controller);

        controller.outdentCategory(veggie);
        assert.deepEqual(shape(controller), ['Lunch', '-Tacos', '-Vegetarian', 'Drinks'], 'Vegetarian sits right after Tacos, under Lunch');
        assert.strictEqual(veggie.parent_uuid, 'lunch');

        controller.indentCategory(drinks);
        assert.deepEqual(shape(controller), ['Lunch', '-Tacos', '-Vegetarian', '-Drinks'], 'Drinks goes under Lunch, last');

        controller.moveCategory(drinks, -1);
        assert.deepEqual(shape(controller), ['Lunch', '-Tacos', '-Drinks', '-Vegetarian']);

        controller.addSubcategory(drinks);
        const added = controller.categories.find((category) => category.name === 'storefront.catalogs.editor.new-subcategory-name');
        assert.deepEqual(shape(controller), ['Lunch', '-Tacos', '-Drinks', '--storefront.catalogs.editor.new-subcategory-name', '-Vegetarian']);
        assert.strictEqual(added.parent_uuid, 'drinks', 'a saved parent is found by its id');
        assert.ok(added.key, 'a new category has a key until it is saved');
        assert.strictEqual(controller.renamingCategory, added, 'the new subcategory is ready to be named');

        // Already three levels deep: no subcategory.
        const count = controller.categories.length;
        controller.addSubcategory(added);
        assert.strictEqual(controller.categories.length, count, 'a third-level category takes no subcategory');

        // A subcategory of a new category finds its parent by key.
        controller.newCategoryName = 'Breakfast';
        controller.addCategory();
        const breakfast = controller.categories.find((category) => category.name === 'Breakfast');
        controller.addSubcategory(breakfast);
        const child = controller.categories.find((category) => category.parent_key === breakfast.key);
        assert.ok(child, 'placed under the unsaved category by its key');
        assert.strictEqual(child.parent_uuid, null);
        assert.ok(lunch.order < drinks.order, 'orders follow the tree');
    });

    test('removing a category moves its subcategories up to where it was', async function (assert) {
        const controller = this.owner.lookup('controller:catalogs/index/edit');
        const { tacos, veggie } = nestedMenu(controller);

        await controller.removeCategory(tacos);

        assert.deepEqual(shape(controller), ['Lunch', '-Vegetarian', 'Drinks']);
        assert.strictEqual(veggie.parent_uuid, 'lunch');
    });

    test('a duplicated catalog keeps its menu tree', function (assert) {
        const controller = this.owner.lookup('controller:catalogs/index/edit');
        nestedMenu(controller);
        let copy;
        controller.store.createRecord = (type, attributes) => {
            const record = EmberObject.create({ products: A([]), categories: A([]), parent_key: null, ...attributes });
            if (type === 'catalog') {
                record.save = () => Promise.resolve();
                copy = record;
            }
            return record;
        };
        controller.notifications = { success() {}, serverError() {} };
        controller.hostRouter = { transitionTo() {} };

        controller.duplicate();
        const clones = Object.fromEntries(copy.categories.map((category) => [category.name, category]));

        assert.strictEqual(clones.Tacos.parent_key, clones.Lunch.key);
        assert.strictEqual(clones.Vegetarian.parent_key, clones.Tacos.key);
        assert.strictEqual(clones.Drinks.parent_key, null);
    });
});
