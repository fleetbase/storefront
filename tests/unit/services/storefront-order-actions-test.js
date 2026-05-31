import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

module('Unit | Service | storefront-order-actions', function (hooks) {
    setupTest(hooks);

    test('it opens order details through the resource context panel', async function (assert) {
        assert.expect(14);

        class FetchStub extends Service {
            get(path, params, options) {
                assert.strictEqual(path, 'orders/order_test');
                assert.deepEqual(params, { storefront: 'store_test' });
                assert.deepEqual(options, {
                    namespace: 'storefront/int/v1',
                    normalizeToEmberData: true,
                    normalizeModelType: 'order',
                });

                return Promise.resolve({
                    id: 'order_test',
                    public_id: 'order_test',
                });
            }
        }

        class StorefrontStub extends Service {
            getActiveStore(key) {
                assert.strictEqual(key, 'public_id');
                return 'store_test';
            }
        }

        class ResourceContextPanelStub extends Service {
            open(definition) {
                assert.strictEqual(definition.resource.public_id, 'order_test');
                assert.strictEqual(definition.header, 'storefront/order/panel-header');
                assert.notOk(definition.content);
                assert.strictEqual(definition.tabs.length, 2);
                assert.strictEqual(definition.tabs[0].label, 'Overview');
                assert.strictEqual(definition.tabs[0].component, 'storefront/order/details');
                assert.strictEqual(definition.tabs[1].key, 'invoice');
                assert.strictEqual(definition.tabs[1].component, 'storefront/order/details/registered-tab');
                assert.strictEqual(definition.width, '560px');
            }
        }

        class MenuServiceStub extends Service {
            getMenuItems(registry) {
                assert.strictEqual(registry, 'storefront:component:order:details');
                return [{ title: 'Invoice', slug: 'invoice', icon: 'file-invoice-dollar' }];
            }
        }

        this.owner.register('service:fetch', FetchStub);
        this.owner.register('service:storefront', StorefrontStub);
        this.owner.register('service:resource-context-panel', ResourceContextPanelStub);
        this.owner.register('service:universe/menu-service', MenuServiceStub);

        const service = this.owner.lookup('service:storefront-order-actions');

        await service.viewOrder({ public_id: 'order_test' });
    });
});
