import queryCustomers from '@fleetbase/storefront-engine/utils/query-customers';
import { module, test } from 'qunit';

module('Unit | Utility | query-customers', function () {
    test('it queries the storefront endpoint and keeps the pagination meta', async function (assert) {
        const calls = [];
        const fetch = {
            get(url, params, options) {
                calls.push({ url, params, options });
                return Promise.resolve({ customers: [{ uuid: 'a', name: 'Ava' }], meta: { total: 1, current_page: 1 } });
            },
        };
        const store = {
            normalize: (type, payload) => ({ type, payload }),
            push: ({ type, payload }) => ({ modelName: type, ...payload }),
        };

        const records = await queryCustomers({ fetch, store }, { network: 'network_1', page: 1, query: '', sort: null, limit: undefined });

        assert.deepEqual(calls, [{ url: 'customers', params: { network: 'network_1', page: 1 }, options: { namespace: 'storefront/int/v1' } }], 'blank params are dropped');
        assert.deepEqual(
            records.map((record) => [record.modelName, record.name]),
            [['customer', 'Ava']],
            'records are pushed as customers'
        );
        assert.deepEqual(records.meta, { total: 1, current_page: 1 });
    });

    test('it returns an empty list when the endpoint has no customers', async function (assert) {
        const fetch = { get: () => Promise.resolve({}) };
        const store = { normalize: () => null, push: () => null };

        const records = await queryCustomers({ fetch, store }, { storefront: 'store_1' });

        assert.deepEqual(Array.from(records), []);
        assert.deepEqual(records.meta, {});
    });
});
