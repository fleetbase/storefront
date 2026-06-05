import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Service | storefront-order-workflow', function (hooks) {
    setupTest(hooks);

    test('it preserves the default storefront operator flow', function (assert) {
        const service = this.owner.lookup('service:storefront-order-workflow');
        const orderConfig = { key: 'storefront', namespace: 'system:order-config:storefront', flow: {} };

        assert.deepEqual(
            service.primaryActionDescriptorsFor({ status: 'created', type: 'storefront', order_config: orderConfig, meta: {} }).map((descriptor) => descriptor.text),
            ['Accept order']
        );
        assert.deepEqual(
            service.primaryActionDescriptorsFor({ status: 'accepted', type: 'storefront', order_config: orderConfig, meta: {} }).map((descriptor) => descriptor.text),
            ['Mark as Ready']
        );
        assert.deepEqual(service.primaryActionDescriptorsFor({ status: 'preparing', type: 'storefront', order_config: orderConfig, meta: {} }), []);
        assert.deepEqual(
            service.primaryActionDescriptorsFor({ status: 'pickup_ready', type: 'storefront', order_config: orderConfig, meta: { is_pickup: true } }).map((descriptor) => descriptor.text),
            ['Mark Picked Up']
        );
    });

    test('it derives custom config actions from next activities', function (assert) {
        const service = this.owner.lookup('service:storefront-order-workflow');
        const orderConfig = {
            key: 'custom',
            flow: {
                packing: {
                    activities: ['quality_check'],
                },
                quality_check: {
                    code: 'quality_check',
                    status: 'Quality Check',
                },
            },
        };

        assert.deepEqual(
            service.primaryActionDescriptorsFor({ status: 'packing', order_config: orderConfig, meta: {} }).map((descriptor) => descriptor.text),
            ['Quality Check']
        );
    });

    test('it detects assigned drivers from relationship uuid or flag', function (assert) {
        const service = this.owner.lookup('service:storefront-order-workflow');

        assert.true(service.hasAssignedDriver({ driver_assigned: { id: 'driver_1' } }));
        assert.true(service.hasAssignedDriver({ driver_assigned_uuid: 'driver_1' }));
        assert.true(service.hasAssignedDriver({ has_driver_assigned: true }));
        assert.false(service.hasAssignedDriver({ has_driver_assigned: false, driver_assigned: null, driver_assigned_uuid: null }));
    });
});
