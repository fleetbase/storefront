import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import ErrorController from '@fleetbase/storefront-engine/controllers/error';

module('Unit | Route | application', function (hooks) {
    setupTest(hooks);

    // The error controller lives in the engine's namespace only: an `app/` re-export would
    // replace the console's own error controller once the engine's app tree merges into it.
    hooks.beforeEach(function () {
        this.owner.register('controller:error', ErrorController);
    });

    test('it exists', function (assert) {
        let route = this.owner.lookup('route:application');
        assert.ok(route);
    });

    test('loading shows the overlay when entering the engine and bubbles to the substate inside it', function (assert) {
        const route = this.owner.lookup('route:application');
        const shown = [];
        route.loader = { showOnInitialTransition: (transition, target, options) => shown.push({ to: transition.to?.name, target, message: options.loadingMessage }) };
        route.intl = { t: (key) => key };

        const entering = { from: null, to: { name: 'console.storefront.home' } };
        assert.false(route.loading(entering), 'entering the engine is handled here');
        assert.deepEqual(shown, [{ to: 'console.storefront.home', target: 'section.next-view-section', message: 'storefront.common.loading-storefront' }]);

        const fromHost = { from: { name: 'console.fleet-ops.operations.orders' }, to: { name: 'console.storefront.orders.index.index' } };
        assert.false(route.loading(fromHost), 'coming from another extension counts as entering');
        assert.strictEqual(shown.length, 2);

        const inside = { from: { name: 'console.storefront.orders.index.index' }, to: { name: 'console.storefront.customers.index.index' } };
        assert.true(route.loading(inside), 'moving inside the engine bubbles so the loading substate renders');
        assert.strictEqual(shown.length, 2, 'no overlay for an in-engine transition');
    });

    test('error toasts the detail, hands the failed transition to the error page and bubbles', function (assert) {
        const route = this.owner.lookup('route:application');
        const toasts = [];
        route.notifications = { serverError: (error) => toasts.push(error.message) };
        const transition = { retry() {} };

        assert.true(route.error(new Error('Network not found'), transition));
        assert.deepEqual(toasts, ['Network not found']);
        assert.strictEqual(this.owner.lookup('controller:error').failedTransition, transition);
    });
});
