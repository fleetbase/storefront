import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

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

    test('customer, pickup, dropoff and driver are identity cells typed for the shared descriptors', function (assert) {
        const controller = this.owner.lookup('controller:orders/index');
        const byId = Object.fromEntries(controller.columns.map((column) => [column.id, column]));

        assert.strictEqual(byId['customer-name'].cellComponent, 'table/cell/identity');
        assert.strictEqual(byId['customer-name'].resourceType, 'customer');
        assert.strictEqual(byId['pickup-name'].cellComponent, 'table/cell/identity');
        assert.strictEqual(byId['pickup-name'].resourceType, 'place');
        assert.strictEqual(byId['dropoff-name'].cellComponent, 'table/cell/identity');
        assert.strictEqual(byId['dropoff-name'].resourceType, 'place');
        assert.strictEqual(byId['driver-assigned'].cellComponent, 'table/cell/identity');
        assert.strictEqual(byId['driver-assigned'].resourceType, 'driver');
        assert.strictEqual(byId['driver-assigned'].emptyText, 'No driver assigned');
    });

    test('identity cells read the loaded relation, or a stub named after the row', async function (assert) {
        const controller = this.owner.lookup('controller:orders/index');
        const byId = Object.fromEntries(controller.columns.map((column) => [column.id, column]));
        const customer = { id: 'contact_1', name: 'Ada' };
        const driver = { id: 'driver_1', name: 'Bob' };
        const pickup = { id: 'place_1', name: 'Depot' };

        assert.strictEqual(byId['customer-name'].resourcePath({ customer }), customer);
        assert.strictEqual(byId['driver-assigned'].resourcePath({ driver_assigned: driver }), driver);
        assert.strictEqual(byId['pickup-name'].resourcePath({ payload: { pickup } }), pickup);

        const customerStub = byId['customer-name'].resourcePath({ customer_name: 'Ada', customer_type: 'fleet-ops:contact' });
        assert.strictEqual(customerStub.name, 'Ada');
        assert.strictEqual(customerStub.resourceType, 'fleet-ops:contact');
        assert.true(customerStub.isIdentityStub);

        const placeStub = byId['dropoff-name'].resourcePath({ dropoffName: '1 Main St' });
        assert.strictEqual(placeStub.name, '1 Main St');
        assert.strictEqual(placeStub.resourceType, 'place');

        const found = { id: 'driver_9' };
        const findRecord = (modelName, id) => (modelName === 'driver' && id === 'driver_9' ? Promise.resolve(found) : Promise.resolve(null));
        controller.store = { findRecord };
        const driverStub = byId['driver-assigned'].resourcePath({ driver_name: 'Bob', driver_assigned_uuid: 'driver_9' });
        assert.strictEqual(driverStub.name, 'Bob');
        assert.strictEqual(await driverStub.loadResource(), found, 'the stub loads the driver by uuid on demand');
        assert.strictEqual(await byId['driver-assigned'].resourcePath({ driver_name: 'Bob' }).loadResource(), null, 'no uuid, nothing to load');

        assert.strictEqual(byId['driver-assigned'].resourcePath({}), null, 'no driver at all renders the empty text');
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
