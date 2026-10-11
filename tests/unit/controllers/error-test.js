import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Controller | error', function (hooks) {
    setupTest(hooks);

    test('it reads a message from an Error, an API payload or a string, and nothing from junk', function (assert) {
        const controller = this.owner.lookup('controller:error');

        controller.model = new Error('Network not found');
        assert.strictEqual(controller.message, 'Network not found');

        controller.model = { errors: [{ detail: 'Resource not found.' }] };
        assert.strictEqual(controller.message, 'Resource not found.');

        controller.model = { errors: [{ title: 'Forbidden' }] };
        assert.strictEqual(controller.message, 'Forbidden');

        controller.model = 'Timed out';
        assert.strictEqual(controller.message, 'Timed out');

        controller.model = { errors: [{ detail: '   ' }] };
        assert.strictEqual(controller.message, null, 'blank detail falls back to the generic text');

        controller.model = null;
        assert.strictEqual(controller.message, null);
    });

    test('retry re-runs the failed transition when it is known, else the current URL; back goes to the dashboard', function (assert) {
        const controller = this.owner.lookup('controller:error');
        const transitions = [];
        let retried = 0;
        controller.hostRouter = {
            currentURL: '/storefront/customers',
            transitionTo: (target) => transitions.push(target),
        };

        controller.failedTransition = { retry: () => retried++ };
        controller.retry();
        assert.strictEqual(retried, 1, 'the failed transition is retried');
        assert.deepEqual(transitions, [], 'the current URL is not re-entered');

        controller.failedTransition = null;
        controller.retry();
        controller.goToDashboard();

        assert.deepEqual(transitions, ['/storefront/customers', 'console.storefront']);
    });
});
