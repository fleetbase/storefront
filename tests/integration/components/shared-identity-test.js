import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import { initialize } from '@fleetbase/fleetops-data/instance-initializers/register-shared-resource-descriptors';

/**
 * Storefront never loads the FleetOps engine to show a driver, customer or
 * place: the descriptors and wrappers come from fleetops-data and the
 * generic components from ember-ui, both of which this engine bundles.
 */
module('Integration | Component | shared identity components', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        initialize(this.owner);
    });

    test('a driver identity cell renders the name, status dot and vehicle badge from the shared descriptor', async function (assert) {
        this.set('row', { driver_assigned: { resourceType: 'driver', name: 'Ada Driver', status: 'available', vehicle_name: 'Truck 10' } });
        this.set('column', { resourceType: 'driver', resourcePath: 'driver_assigned', popover: false });

        await render(hbs`<Table::Cell::Identity @row={{this.row}} @column={{this.column}} />`);

        assert.dom('[data-test-identity-label]').hasText('Ada Driver');
        assert.dom('[data-test-resource-identity-status-dot]').hasClass('text-green-500');
        assert.dom('[data-test-resource-identity-meta-badge][data-badge-key="vehicle"]').hasText('Truck 10');
    });

    test('an empty relation renders the column empty text instead of a cell', async function (assert) {
        this.set('row', {});
        this.set('column', { resourceType: 'driver', resourcePath: 'driver_assigned', emptyText: 'No driver assigned' });

        await render(hbs`<Table::Cell::Identity @row={{this.row}} @column={{this.column}} />`);

        assert.dom('[data-test-identity-empty-text]').hasText('No driver assigned');
        assert.dom('[data-test-identity-cell]').doesNotExist();
    });

    test('the customer, driver, place and vehicle pills and select options resolve inside storefront', async function (assert) {
        this.set('customer', { resourceType: 'customer', name: 'Ava Chen', customer_type: 'fleet-ops:contact' });
        this.set('driver', { resourceType: 'driver', name: 'Ada Driver', phone: '+15550100' });
        this.set('place', { resourceType: 'place', name: 'Depot', city: 'Singapore', country: 'SG' });
        this.set('vehicle', { resourceType: 'vehicle', displayName: 'Truck 10', plate_number: 'TRK-10' });

        await render(hbs`
            <div data-test-customer><Customer::Pill @customer={{this.customer}} @noPopover={{true}} /></div>
            <div data-test-driver><Driver::Pill @driver={{this.driver}} @noPopover={{true}} /></div>
            <div data-test-place><Place::Pill @place={{this.place}} @noPopover={{true}} /></div>
            <div data-test-vehicle><SelectOption::Vehicle @option={{this.vehicle}} @compact={{true}} /></div>
            <div data-test-driver-option><SelectOption::Driver @option={{this.driver}} /></div>
        `);

        assert.dom('[data-test-customer] [data-test-resource-pill-title]').hasText('Ava Chen');
        assert.dom('[data-test-customer] [data-test-resource-pill-subtitle]').hasText('Customer');
        assert.dom('[data-test-driver] [data-test-resource-pill-title]').hasText('Ada Driver');
        assert.dom('[data-test-driver] [data-test-resource-pill-subtitle]').hasText('+15550100');
        assert.dom('[data-test-place] [data-test-resource-pill-title]').hasText('Depot');
        assert.dom('[data-test-place] [data-test-resource-pill-subtitle]').hasText('Singapore, SG');
        assert.dom('[data-test-vehicle] [data-test-select-option-title]').hasText('Truck 10');
        assert.dom('[data-test-vehicle] .select-option').hasClass('select-option--compact');
        assert.dom('[data-test-driver-option] [data-test-select-option-title]').hasText('Ada Driver');
    });

    test('a pill with no record shows its fallback title and does not open anything', async function (assert) {
        await render(hbs`<Driver::Pill @driver={{this.nothing}} @titleFallback="No driver assigned" @static={{true}} />`);

        assert.dom('[data-test-resource-pill-title]').hasText('No driver assigned');
        assert.dom('[data-test-resource-pill] a[href]').doesNotExist();
    });
});
