import Service, { inject as service } from '@ember/service';
import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

class StorefrontStubService extends Service {
    getActiveStore(key) {
        if (key === 'public_id') {
            return 'store_123';
        }
    }
}

class FetchStubService extends Service {
    @service store;

    calls = [];

    async get(path, params, options) {
        this.calls.push({ path, params, options });

        return {
            orders: [
                {
                    id: 'order_456',
                    public_id: 'order_456',
                    meta: {
                        total: 19.99,
                        currency: 'USD',
                    },
                },
            ],
            meta: {
                current_page: 1,
                total: 1,
            },
        };
    }

    normalizeModel(payload, modelType) {
        this.normalized = { payload, modelType };

        return payload.orders.map((order) => this.store.createRecord('order', order));
    }
}

module('Unit | Route | networks/index/network/orders', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:storefront', StorefrontStubService);
        this.owner.register('service:fetch', FetchStubService);
    });

    test('it loads orders from the Storefront internal namespace as Ember Data models', async function (assert) {
        let route = this.owner.lookup('route:networks/index/network/orders');
        let fetch = this.owner.lookup('service:fetch');

        assert.ok(route);

        let orders = await route.model({ page: 2, limit: undefined, sort: '-created_at', query: undefined, status: '', customer: null });
        let request = fetch.calls[0];

        assert.strictEqual(request.path, 'orders');
        assert.deepEqual(request.params, { page: 2, sort: '-created_at', storefront: 'store_123' });
        assert.deepEqual(request.options, { namespace: 'storefront/int/v1' });
        assert.strictEqual(fetch.normalized.modelType, 'orders');
        assert.strictEqual(orders[0].constructor.modelName, 'order');
        assert.deepEqual(orders.meta, { current_page: 1, total: 1 });
    });
});
