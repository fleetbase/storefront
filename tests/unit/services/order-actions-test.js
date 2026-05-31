import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Service | order-actions', function (hooks) {
    setupTest(hooks);

    // TODO: Replace this with your real tests.
    test('it exists', function (assert) {
        let service = this.owner.lookup('service:order-actions');
        assert.ok(service);
    });

    test('it remains a compatibility alias for storefront order actions', function (assert) {
        let service = this.owner.lookup('service:order-actions');

        assert.strictEqual(typeof service.viewOrder, 'function');
        assert.strictEqual(typeof service.actionButtonsFor, 'function');
    });
});
