import { module, test } from 'qunit';

import { setupTest } from 'dummy/tests/helpers';

module('Unit | Adapter | food truck', function (hooks) {
    setupTest(hooks);

    // Replace this with your real tests.
    test('it exists', function (assert) {
        let adapter = this.owner.lookup('adapter:food-truck');
        assert.ok(adapter);
    });
});
