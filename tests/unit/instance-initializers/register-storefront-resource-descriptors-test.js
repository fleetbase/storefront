import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import { buildStorefrontResourceDescriptors } from '@fleetbase/storefront-engine/instance-initializers/register-storefront-resource-descriptors';

module('Unit | Instance Initializer | register-storefront-resource-descriptors', function (hooks) {
    setupTest(hooks);

    test('every storefront resource has a descriptor the shared identity components can read', function (assert) {
        const byKey = Object.fromEntries(buildStorefrontResourceDescriptors(this.owner).map((descriptor) => [descriptor.key, descriptor]));

        assert.deepEqual(Object.keys(byKey).sort(), ['catalog', 'food-truck', 'network', 'product', 'promotion', 'store']);

        assert.strictEqual(byKey.store.title({ name: 'Orchard Grocers' }), 'Orchard Grocers');
        assert.strictEqual(byKey.store.status({ online: true }), 'online');
        assert.strictEqual(byKey.product.title({ name: 'Iced Latte' }), 'Iced Latte');
        assert.strictEqual(byKey.product.identifier({ sku: 'SF-LATTE' }), 'SF-LATTE');
        assert.strictEqual(byKey.product.status({ status: 'published' }), 'published');
        assert.strictEqual(byKey.catalog.title({ name: 'Lunch menu' }), 'Lunch menu');
        assert.strictEqual(byKey['food-truck'].title({ vehicle: { display_name: 'Truck 10' } }), 'Truck 10');
        // A truck's status alone says whether it takes orders, not its vehicle's connectivity.
        assert.strictEqual(byKey['food-truck'].status({ status: 'active', online: false }), 'active');
        assert.strictEqual(byKey['food-truck'].status({ status: 'inactive', online: true }), 'inactive');
        assert.true(byKey['food-truck'].online({ status: 'active', vehicle: { online: false } }));
        assert.strictEqual(byKey.promotion.title({ name: 'Weekend 15% off' }), 'Weekend 15% off');
        assert.strictEqual(byKey.promotion.identifier({ type: 'percentage' }), 'percentage');
        assert.strictEqual(byKey.network.title({ public_id: 'network_1' }), 'network_1', 'falls back to the id');
    });
});
