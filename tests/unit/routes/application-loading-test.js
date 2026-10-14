import { module, test } from 'qunit';
import { isReloadOnScreen } from '@fleetbase/storefront-engine/routes/application';

function info(name, queryParams = {}, parent = null) {
    return { name, queryParams, parent };
}

module('Unit | Route | application | loading', function () {
    const list = info('console.storefront.orders.index', { page: '1' });
    const panel = info('console.storefront.orders.index.view', { page: '1' }, list);
    const orders = { fullRouteName: 'console.storefront.orders.index' };

    test('a refresh of the page on screen keeps it on screen', function (assert) {
        assert.true(isReloadOnScreen({ from: panel, to: info('console.storefront.orders.index.view', { page: '1' }) }, orders));
    });

    test('a filter, search or page change still shows the skeleton', function (assert) {
        assert.false(isReloadOnScreen({ from: panel, to: info('console.storefront.orders.index.view', { page: '2' }) }, orders));
        assert.false(isReloadOnScreen({ from: panel, to: info('console.storefront.orders.index.index', { page: '1', status: 'created' }) }, orders));
    });

    test('a page that is not on screen yet shows its skeleton', function (assert) {
        assert.false(isReloadOnScreen({ from: info('console.storefront.home'), to: info('console.storefront.orders.index.index') }, orders));
        assert.false(isReloadOnScreen({ from: null, to: info('console.storefront.orders.index.index') }, orders));
    });

    test('params at their default count as none (a refresh leaves them out)', function (assert) {
        const opened = info('console.storefront.orders.index.view', { page: '1', sort: '-created_at' }, list);
        const defaults = { page: '1', sort: '-created_at' };
        assert.true(isReloadOnScreen({ from: opened, to: info('console.storefront.orders.index.view', {}) }, orders, defaults));
        assert.false(isReloadOnScreen({ from: opened, to: info('console.storefront.orders.index.view', {}) }, orders), 'without the defaults it reads as a change');
        assert.false(isReloadOnScreen({ from: opened, to: info('console.storefront.orders.index.view', { sort: 'created_at' }) }, orders, defaults), 'a different sort still is one');
    });

    test('empty params count as none', function (assert) {
        assert.true(isReloadOnScreen({ from: list, to: info('console.storefront.orders.index.index', { page: '1', query: '', status: null }) }, orders));
    });
});
