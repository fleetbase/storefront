import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

module('Unit | Route | networks/index/network/customers', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:hostRouter', class extends Service {});
    });

    test('it lists the customers who ordered through the network, not the active store', async function (assert) {
        const route = this.owner.lookup('route:networks/index/network/customers');
        const store = this.owner.lookup('service:store');
        const requests = [];
        store.query = (modelName, params) => {
            requests.push({ modelName, params });
            return Promise.resolve([]);
        };
        route.modelFor = () => ({ id: 'network_uuid', public_id: 'network_123' });

        await route.model({ page: 1 });

        assert.deepEqual(requests, [{ modelName: 'customer', params: { page: 1, network: 'network_123' } }]);
    });
});
