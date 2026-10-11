import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

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
});
