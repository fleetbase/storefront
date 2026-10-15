import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import OrdersIndexViewRoute from '@fleetbase/storefront-engine/routes/orders/index/view';

module('Unit | Route | networks/index/network/orders/view', function (hooks) {
    setupTest(hooks);

    test("it is the store's order panel, rendered with the store's template", function (assert) {
        const route = this.owner.lookup('route:networks/index/network/orders/view');

        assert.true(route instanceof OrdersIndexViewRoute, "loads the order the way the store's panel does");
        assert.strictEqual(route.templateName, 'orders/index/view');
    });
});
