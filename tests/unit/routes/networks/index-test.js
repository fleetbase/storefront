import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';
import Evented from '@ember/object/evented';

module('Unit | Route | networks/index', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        // The engine's dummy app has no console host to provide the host router.
        this.owner.register('service:hostRouter', class extends Service.extend(Evented) {});
        this.owner.register(
            'service:store',
            class extends Service {
                queries = [];
                query = (modelName, params) => {
                    this.queries.push([modelName, params]);
                    return [];
                };
            }
        );
    });

    test('it exists', function (assert) {
        let route = this.owner.lookup('route:networks/index');
        assert.ok(route);
    });

    test('entering a network skips the networks list, and arriving on the list loads it', function (assert) {
        const route = this.owner.lookup('route:networks/index');
        const store = this.owner.lookup('service:store');
        const hostRouter = this.owner.lookup('service:hostRouter');
        let refreshed = 0;
        route.refresh = () => refreshed++;

        assert.deepEqual(route.model({}, { to: { name: 'console.storefront.networks.index.network.index' } }), []);
        assert.strictEqual(store.queries.length, 0, 'no networks query on the way into a network');

        hostRouter.trigger('routeDidChange', { to: { name: 'console.storefront.networks.index.network.stores' } });
        assert.strictEqual(refreshed, 0, 'moving inside the network leaves the list alone');

        hostRouter.trigger('routeDidChange', { to: { name: 'console.storefront.networks.index.index' } });
        assert.strictEqual(refreshed, 1, 'the list loads once it is shown');

        route.model({ page: 2 }, { to: { name: 'console.storefront.networks.index.index' } });
        assert.deepEqual(store.queries[0], ['network', { with_gateways: 1, with_notification_channels: 1, page: 2 }]);
    });
});
