import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import { setupIntl } from 'ember-intl/test-support';
import Service from '@ember/service';

module('Unit | Controller | networks/index/network/customers', function (hooks) {
    setupTest(hooks);
    setupIntl(hooks, 'en-us');

    hooks.beforeEach(function () {
        // The engine's dummy app has no console host to provide the host router.
        this.owner.register('service:hostRouter', class extends Service {});
    });

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
