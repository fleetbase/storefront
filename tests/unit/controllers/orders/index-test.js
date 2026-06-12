import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Controller | orders/index', function (hooks) {
    setupTest(hooks);

    test('it exists', function (assert) {
        let controller = this.owner.lookup('controller:orders/index');
        assert.ok(controller);
    });

    test('it pins identity and action columns', function (assert) {
        let controller = this.owner.lookup('controller:orders/index');
        let [idColumn] = controller.columns;
        let actionColumn = controller.columns[controller.columns.length - 1];

        assert.true(idColumn.sticky, 'id column is sticky');
        assert.strictEqual(actionColumn.sticky, 'right', 'action column is sticky on the right');
    });
});
