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
        assert.expect(5);

        class FetchStub extends Service {
            get(path, params, options) {
                assert.strictEqual(path, 'orders/order_test');
                assert.deepEqual(params, { storefront: 'store_test' });
                assert.deepEqual(options, {
                    namespace: 'storefront/int/v1',
                    normalizeToEmberData: true,
                    normalizeModelType: 'order',
                });

                return Promise.resolve({ id: 'order_test' });
            }
        }

        class StorefrontStub extends Service {
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

    test('it exposes overview and registered order detail tabs', function (assert) {
        assert.expect(5);

        class MenuServiceStub extends Service {
            getMenuItems(registry) {
                assert.strictEqual(registry, 'storefront:component:order:details');
                return [{ route: 'orders.index.view.virtual', label: 'Invoice', slug: 'invoice', icon: 'file-invoice-dollar' }];
            }
        }

        this.owner.register('service:universe/menu-service', MenuServiceStub);

        const controller = this.owner.lookup('controller:orders/index/view');
        const tabs = controller.tabs;

        assert.strictEqual(tabs.length, 2);
        assert.strictEqual(tabs[0].label, 'Overview');
        assert.strictEqual(tabs[0].route, 'orders.index.view.index');
        assert.strictEqual(tabs[1].label, 'Invoice');
    });
});
