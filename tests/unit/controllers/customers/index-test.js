import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Controller | customers/index', function (hooks) {
    setupTest(hooks);

    test('it exists', function (assert) {
        let controller = this.owner.lookup('controller:customers/index');
        assert.ok(controller);
    });

    test('it pins identity and action columns', function (assert) {
        let controller = this.owner.lookup('controller:customers/index');
        let [nameColumn] = controller.columns;
        let actionColumn = controller.columns[controller.columns.length - 1];

        assert.true(nameColumn.sticky, 'name column is sticky');
        assert.strictEqual(actionColumn.sticky, 'right', 'action column is sticky on the right');
    });

    test('the name column is a customer identity cell that opens the storefront customer', function (assert) {
        let controller = this.owner.lookup('controller:customers/index');
        let [nameColumn] = controller.columns;

        assert.strictEqual(nameColumn.cellComponent, 'table/cell/identity');
        assert.strictEqual(nameColumn.resourceType, 'customer');
        assert.strictEqual(nameColumn.action, controller.viewCustomer);
    });
});
