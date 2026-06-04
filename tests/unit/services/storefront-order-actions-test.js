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

    test('it progresses action buttons for accepted and pickup ready orders', function (assert) {
        class MenuServiceStub extends Service {
            getMenuItems() {
                return [];
            }
        }

        this.owner.register('service:universe/menu-service', MenuServiceStub);

        const service = this.owner.lookup('service:storefront-order-actions');

        let actions = service.actionButtonsFor({ id: 'order_1', status: 'created', meta: {} })[0].items;
        assert.strictEqual(actions[0].text, 'Accept order');

        actions = service.actionButtonsFor({ id: 'order_1', status: 'accepted', meta: { is_pickup: false } })[0].items;
        assert.strictEqual(actions[0].text, 'Mark as Ready');

        actions = service.actionButtonsFor({ id: 'order_1', status: 'accepted', dispatched: true, meta: { is_pickup: false } })[0].items;
        assert.strictEqual(actions[0].text, 'Mark as Ready');

        actions = service.actionButtonsFor({ id: 'order_1', status: 'accepted', meta: { is_pickup: true } })[0].items;
        assert.strictEqual(actions[0].text, 'Mark as Ready');

        actions = service.actionButtonsFor({ id: 'order_1', status: 'pickup_ready', meta: { is_pickup: true } })[0].items;
        assert.strictEqual(actions[0].text, 'Mark Picked Up');
    });

    test('it applies mutation status before invoking callbacks', function (assert) {
        assert.expect(3);

        class ResourceContextPanelStub extends Service {
            overlays = [
                {
                    id: 'storefront-order:order_1',
                },
            ];

            update(id, definition) {
                assert.strictEqual(id, 'storefront-order:order_1');
                assert.strictEqual(definition.actionButtons[0].items[0].text, 'Mark as Ready');
            }
        }

        class MenuServiceStub extends Service {
            getMenuItems() {
                return [];
            }
        }

        this.owner.register('service:resource-context-panel', ResourceContextPanelStub);
        this.owner.register('service:universe/menu-service', MenuServiceStub);

        const service = this.owner.lookup('service:storefront-order-actions');
        const order = { id: 'order_1', status: 'created', meta: {} };

        service.didMutateOrder(order, 'accepted', (mutatedOrder) => {
            assert.strictEqual(mutatedOrder.status, 'accepted');
        });
    });

    test('it detects assigned drivers before dispatch', function (assert) {
        const service = this.owner.lookup('service:storefront-order-actions');

        assert.true(service.hasAssignedDriver({ has_driver_assigned: true }));
        assert.true(service.hasAssignedDriver({ driver_assigned: { id: 'driver_1' } }));
        assert.true(service.hasAssignedDriver({ driver_assigned_uuid: 'driver_1' }));
        assert.false(service.hasAssignedDriver({ has_driver_assigned: false, driver_assigned: null }));
    });

    test('it changes driver action label when a driver is assigned', function (assert) {
        const service = this.owner.lookup('service:storefront-order-actions');

        let actions = service.actionButtonsFor({ id: 'order_1', status: 'accepted', meta: {}, driver_assigned: null })[0].items;
        assert.strictEqual(actions.find((action) => action.icon === 'id-card').text, 'Assign Driver');

        actions = service.actionButtonsFor({ id: 'order_1', status: 'accepted', meta: {}, driver_assigned_uuid: 'driver_1' })[0].items;
        assert.strictEqual(actions.find((action) => action.icon === 'user-minus').text, 'Unassign Driver');
    });

    test('it marks orders dispatched even when dispatch response keeps a pre-dispatch status', function (assert) {
        assert.expect(4);

        class ResourceContextPanelStub extends Service {
            overlays = [
                {
                    id: 'storefront-order:order_1',
                },
            ];

            update(id, definition) {
                assert.strictEqual(id, 'storefront-order:order_1');
                assert.notStrictEqual(definition.actionButtons[0].items[0].text, 'Mark as Ready');
            }
        }

        class MenuServiceStub extends Service {
            getMenuItems() {
                return [];
            }
        }

        this.owner.register('service:resource-context-panel', ResourceContextPanelStub);
        this.owner.register('service:universe/menu-service', MenuServiceStub);

        const service = this.owner.lookup('service:storefront-order-actions');
        const order = { id: 'order_1', status: 'accepted', dispatched: false, meta: {} };

        service.didDispatchOrder(order, 'accepted', (mutatedOrder) => {
            assert.strictEqual(mutatedOrder.status, 'dispatched');
            assert.true(mutatedOrder.dispatched);
        });
    });
});
