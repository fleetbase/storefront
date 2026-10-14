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
        const requests = [];
        route.fetch = {
            get(url, params, options) {
                requests.push({ url, params, options });
                return Promise.resolve({ customers: [], meta: { total: 0 } });
            },
        };
        route.modelFor = () => ({ id: 'network_uuid', public_id: 'network_123' });

        const customers = await route.model({ page: 1, query: null });

        assert.deepEqual(
            requests,
            [{ url: 'customers', params: { page: 1, network: 'network_123' }, options: { namespace: 'storefront/int/v1' } }],
            "Storefront's endpoint, scoped to the network"
        );
        assert.deepEqual(customers.meta, { total: 0 });
    });
});
