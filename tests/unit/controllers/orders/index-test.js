import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import { setupIntl } from 'ember-intl/test-support';
import Service from '@ember/service';

class StorefrontStubService extends Service {
    activeStore = { id: 'store_1', public_id: 'store_test', currency: 'USD' };
    activeNetwork = null;
    isNetworkContext = false;

    getActiveStore(property = null) {
        return property ? this.activeStore?.[property] : this.activeStore;
    }

    contextScope() {
        return { storefront: this.activeStore?.public_id };
    }
}

module('Unit | Controller | orders/index', function (hooks) {
    setupTest(hooks);
    setupIntl(hooks, 'en-us');

    hooks.beforeEach(function () {
        this.owner.register('service:storefront', StorefrontStubService);
    });

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

    test('customer, pickup, dropoff and driver are identity cells typed for the shared descriptors', function (assert) {
        const controller = this.owner.lookup('controller:orders/index');
        const byId = Object.fromEntries(controller.columns.map((column) => [column.id, column]));

        assert.strictEqual(byId['customer-name'].cellComponent, 'table/cell/identity');
        assert.strictEqual(byId['customer-name'].resourceType, 'customer');
        assert.strictEqual(byId['pickup-name'].cellComponent, 'table/cell/identity');
        assert.strictEqual(byId['pickup-name'].resourceType, 'place');
        assert.strictEqual(byId['dropoff-name'].cellComponent, 'table/cell/identity');
        assert.strictEqual(byId['dropoff-name'].resourceType, 'place');
        // The driver column is the shared order driver cell: a driver pill, "no driver needed" for pickups, or an Assign driver button.
        assert.strictEqual(byId['driver-assigned'].cellComponent, 'storefront/network/orders/cell/driver');
        assert.strictEqual(typeof byId['driver-assigned'].onAssign, 'function');
        assert.strictEqual(byId['fulfillment'].cellComponent, 'storefront/network/orders/cell/fulfillment');
        assert.strictEqual(byId['row-actions'].cellComponent, 'storefront/network/orders/cell/actions');
    });

    test('identity cells read the loaded relation, or a stub named after the row', async function (assert) {
        const controller = this.owner.lookup('controller:orders/index');
        const byId = Object.fromEntries(controller.columns.map((column) => [column.id, column]));
        const customer = { id: 'contact_1', name: 'Ada' };
        const pickup = { id: 'place_1', name: 'Depot' };

        assert.strictEqual(byId['customer-name'].resourcePath({ customer }), customer);
        assert.strictEqual(byId['pickup-name'].resourcePath({ payload: { pickup } }), pickup);

        const customerStub = byId['customer-name'].resourcePath({ customer_name: 'Ada', customer_type: 'fleet-ops:contact' });
        assert.strictEqual(customerStub.name, 'Ada');
        assert.strictEqual(customerStub.resourceType, 'fleet-ops:contact');
        assert.true(customerStub.isIdentityStub);

        const placeStub = byId['dropoff-name'].resourcePath({ dropoffName: '1 Main St' });
        assert.strictEqual(placeStub.name, '1 Main St');
        assert.strictEqual(placeStub.resourceType, 'place');
    });

    test('clicking a customer opens the storefront customer page, and a name-only stub opens nothing', function (assert) {
        const transitions = [];
        this.owner.register(
            'service:hostRouter',
            class extends Service {
                transitionTo(...args) {
                    transitions.push(args);
                }
            }
        );
        const controller = this.owner.lookup('controller:orders/index');

        controller.viewCustomer({ public_id: 'contact_1', name: 'Ada' });
        controller.viewCustomer({ name: 'Ada', isIdentityStub: true });
        controller.viewCustomer(null);

        assert.deepEqual(transitions, [['console.storefront.customers.index.view', 'contact_1']]);
    });
});
