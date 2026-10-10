import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import { setupIntl } from 'ember-intl/test-support';
import Service from '@ember/service';

module('Unit | Controller | networks/index/network/orders', function (hooks) {
    setupTest(hooks);
    setupIntl(hooks, 'en-us');

    hooks.beforeEach(function () {
        // The engine's dummy app has no console host to provide the host router.
        this.owner.register('service:hostRouter', class extends Service {});
    });

    test('it exists', function (assert) {
        let controller = this.owner.lookup('controller:networks/index/network/orders');
        assert.ok(controller);
    });

    test('customer, pickup, dropoff and driver are identity cells typed for the shared descriptors', function (assert) {
        const controller = this.owner.lookup('controller:networks/index/network/orders');
        const byId = Object.fromEntries(controller.columns.map((column) => [column.id, column]));

        assert.strictEqual(byId.driver.cellComponent, 'storefront/network/orders/cell/driver', 'driver cell carries the inline assign button');

        for (const [id, resourceType] of [['customer', 'customer']]) {
            assert.strictEqual(byId[id].cellComponent, 'table/cell/identity', `${id} is an identity cell`);
            assert.strictEqual(byId[id].resourceType, resourceType, `${id} is a ${resourceType}`);
            assert.strictEqual(typeof byId[id].resourcePath, 'function', `${id} resolves its resource`);
        }

    });
});
