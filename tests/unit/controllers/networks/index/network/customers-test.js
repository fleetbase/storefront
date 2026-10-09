import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Controller | networks/index/network/customers', function (hooks) {
    setupTest(hooks);

    test('it exists', function (assert) {
        let controller = this.owner.lookup('controller:networks/index/network/customers');
        assert.ok(controller);
    });

    test('the name column is a customer identity cell that opens the customer panel', function (assert) {
        let controller = this.owner.lookup('controller:networks/index/network/customers');
        let [nameColumn] = controller.columns;

        assert.strictEqual(nameColumn.cellComponent, 'table/cell/identity');
        assert.strictEqual(nameColumn.resourceType, 'customer');
        assert.strictEqual(nameColumn.action, controller.viewCustomer);
    });
});
