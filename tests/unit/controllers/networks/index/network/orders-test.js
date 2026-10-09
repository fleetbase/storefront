import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Controller | networks/index/network/orders', function (hooks) {
    setupTest(hooks);

    test('it exists', function (assert) {
        let controller = this.owner.lookup('controller:networks/index/network/orders');
        assert.ok(controller);
    });

    test('customer, pickup, dropoff and driver are identity cells typed for the shared descriptors', function (assert) {
        const controller = this.owner.lookup('controller:networks/index/network/orders');
        const byId = Object.fromEntries(controller.columns.map((column) => [column.id, column]));

        for (const [id, resourceType] of [
            ['customer-name', 'customer'],
            ['pickup-name', 'place'],
            ['dropoff-name', 'place'],
            ['driver-assigned', 'driver'],
        ]) {
            assert.strictEqual(byId[id].cellComponent, 'table/cell/identity', `${id} is an identity cell`);
            assert.strictEqual(byId[id].resourceType, resourceType, `${id} is a ${resourceType}`);
            assert.strictEqual(typeof byId[id].resourcePath, 'function', `${id} resolves its resource`);
        }

        const driver = { id: 'driver_1' };
        assert.strictEqual(byId['driver-assigned'].resourcePath({ driver_assigned: driver }), driver);
        assert.strictEqual(byId['driver-assigned'].resourcePath({ driver_name: 'Bob' }).name, 'Bob');
    });
});
