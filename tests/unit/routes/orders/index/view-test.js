import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

module('Unit | Route | orders/index/view', function (hooks) {
    setupTest(hooks);

    test('it exists', function (assert) {
        let route = this.owner.lookup('route:orders/index/view');
        assert.ok(route);
    });

    test('it loads order details from the Storefront internal namespace', async function (assert) {
        assert.expect(8);

        class FetchStub extends Service {
            get(path, params, options) {
                assert.strictEqual(path, 'orders/order_test');
                assert.strictEqual(params.storefront, 'store_test', 'scoped to the active store');
                assert.ok(Array.isArray(params.with) && params.with.includes('payload'), 'eager-loads the payload');
                assert.strictEqual(options.namespace, 'storefront/int/v1');
                assert.true(options.normalizeToEmberData);
                assert.strictEqual(options.normalizeModelType, 'order');

                return Promise.resolve({ id: 'order_test' });
            }
        }

        class StorefrontStub extends Service {
            contextScope() {
                return this.isNetworkContext ? { network: this.activeNetwork?.public_id } : { storefront: this.activeStore?.public_id };
            }

            getActiveStore(key) {
                assert.strictEqual(key, 'public_id');
                return 'store_test';
            }
        }

        this.owner.register('service:fetch', FetchStub);
        this.owner.register('service:storefront', StorefrontStub);

        const route = this.owner.lookup('route:orders/index/view');
        const order = await route.model({ public_id: 'order_test' });

        assert.deepEqual(order, { id: 'order_test' });
    });

    test('it exposes the six order tabs and appends registered ones', function (assert) {
        assert.expect(6);

        class MenuServiceStub extends Service {
            getMenuItems(registry) {
                assert.strictEqual(registry, 'storefront:component:order:details');
                return [{ route: 'orders.index.view.virtual', label: 'Invoice', slug: 'invoice', icon: 'file-invoice-dollar' }];
            }
        }

        this.owner.register('service:universe/menu-service', MenuServiceStub);

        const controller = this.owner.lookup('controller:orders/index/view');
        const tabs = controller.tabs;

        assert.strictEqual(tabs.length, 7);
        assert.deepEqual(
            tabs.slice(0, 6).map((tab) => tab.id),
            ['items', 'route', 'payment', 'activity', 'customer', 'data']
        );
        assert.strictEqual(tabs[0].component, 'storefront/order/details/tabs/items');
        assert.strictEqual(tabs[6].label, 'Invoice');
        assert.strictEqual(tabs[6].registeredSlug, 'invoice');
    });
});
