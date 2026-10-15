import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

module('Unit | Controller | networks/index/network/orders/view', function (hooks) {
    setupTest(hooks);

    test("closing returns to the network's orders, and a refresh reloads them with the panel open", function (assert) {
        const calls = [];
        this.owner.register(
            'service:hostRouter',
            class extends Service {
                transitionTo(...args) {
                    calls.push(['transitionTo', ...args]);
                }

                refresh(routeName) {
                    calls.push(['refresh', routeName]);
                }
            }
        );
        const controller = this.owner.lookup('controller:networks/index/network/orders/view');

        controller.transitionBack();
        controller.refreshOrder();

        assert.deepEqual(calls, [
            ['transitionTo', 'console.storefront.networks.index.network.orders'],
            ['refresh', 'console.storefront.networks.index.network.orders'],
        ]);
    });
});
