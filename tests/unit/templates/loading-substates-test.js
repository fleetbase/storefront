import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import NetworkPromotionsIndexLoadingRoute from '@fleetbase/storefront-engine/routes/networks/index/network/promotions/index-loading';
import NetworkPromotionsLoadingRoute from '@fleetbase/storefront-engine/routes/networks/index/network/promotions/loading';

// Each list page loads with a skeleton drawn for that page. Ember finds `<route>-loading`
// (a sibling of the route) or `<parent>/loading`; these are the ones Storefront defines.
const LOADING_TEMPLATES = [
    'loading',
    'orders/loading',
    'customers/loading',
    'catalogs/loading',
    'food-trucks/loading',
    'products/loading',
    'products/index/loading',
    'promotions/loading',
    'promotions/index-loading',
    'settings/loading',
    'networks/loading',
    'networks/index/loading',
    'networks/index/network/loading',
    'networks/index/network/stores-loading',
    'networks/index/network/trucks-loading',
    'networks/index/network/customers-loading',
    'networks/index/network/categories-loading',
    'networks/index/network/orders-loading',
    'networks/index/network/settings-loading',
];

module('Unit | Template | loading substates', function (hooks) {
    setupTest(hooks);

    test('every list page has a skeleton of its own', function (assert) {
        // Engine templates live in the engine's module namespace, which the test app does not resolve by short name.
        const modules = window.requirejs.entries;

        for (const name of LOADING_TEMPLATES) {
            assert.ok(modules[`@fleetbase/storefront-engine/templates/${name}`], `${name} ships`);
        }
    });

    test("the network's promotions pages load with the store promotions skeletons", function (assert) {
        this.owner.register('route:network-promotions-index-loading', NetworkPromotionsIndexLoadingRoute);
        this.owner.register('route:network-promotions-loading', NetworkPromotionsLoadingRoute);

        assert.strictEqual(this.owner.lookup('route:network-promotions-index-loading').templateName, 'promotions/index-loading');
        assert.strictEqual(this.owner.lookup('route:network-promotions-loading').templateName, 'promotions/loading');
    });
});
