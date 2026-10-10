import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

module('Unit | Route | networks/index/network/trucks', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:hostRouter', class extends Service {});
    });

    test('it lists the trucks of the stores in the network', async function (assert) {
        const route = this.owner.lookup('route:networks/index/network/trucks');
        const store = this.owner.lookup('service:store');
        const requests = [];
        store.query = (modelName, params) => {
            requests.push({ modelName, params });
            return Promise.resolve([]);
        };
        route.modelFor = (name) => {
            assert.strictEqual(name, 'networks.index.network');
            return { id: 'network_uuid' };
        };

        await route.model({ query: 'taco' });

        assert.deepEqual(requests, [{ modelName: 'food-truck', params: { query: 'taco', network: 'network_uuid' } }]);
        assert.strictEqual(route.queryParams.query.as, 't_query', 'the search param does not collide with the networks list search');
    });
});
