import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { setupIntl } from 'ember-intl/test-support';
import { render, click } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import { initialize } from '@fleetbase/fleetops-data/instance-initializers/register-shared-resource-descriptors';

module('Integration | Component | storefront/truck/card', function (hooks) {
    setupRenderingTest(hooks);
    setupIntl(hooks, 'en-us');

    hooks.beforeEach(function () {
        initialize(this.owner);
    });

    test('an online truck with catalogs renders its vehicle pill, area, photo and presence', async function (assert) {
        this.set('truck', {
            status: 'active',
            online: true,
            vehicle: { id: 'vehicle_1', resourceType: 'vehicle', display_name: 'Truck 10', plate_number: 'SKZ 1049 A', photo_url: 'https://cdn.test/truck.png', online: true },
            service_area: { name: 'Central' },
            zone: { name: 'Zone 3' },
            catalogs: [{ name: 'Lunch menu' }, { name: 'Breakfast' }],
            store_summary: { name: 'Fleetbase Market' },
            today: { orders: 14 },
        });
        this.set('noop', () => {});

        await render(hbs`<Storefront::Truck::Card @truck={{this.truck}} @onEdit={{this.noop}} @onAssignCatalogs={{this.noop}} @onDelete={{this.noop}} />`);

        assert.dom('[data-test-storefront-truck-card]').exists();
        assert.dom('[data-test-truck-vehicle] [data-test-resource-pill-title]').hasText('Truck 10');
        assert.dom('[data-test-truck-vehicle] [data-test-pill-online-indicator]').hasClass('text-green-500');
        assert.dom('[data-test-truck-area]').hasText('Central service area · Zone 3');
        assert.dom('[data-test-truck-vehicle] [data-test-resource-pill-subtitle]').hasText('No driver assigned');
        assert.dom('[data-test-truck-photo]').hasAttribute('src', 'https://cdn.test/truck.png');
        // A catalog without hours is always serving, so the badge names it.
        assert.dom('[data-test-truck-presence]').includesText('Serving · Lunch menu');
        assert.dom('[data-test-truck-catalogs-count]').hasText('2 catalogs · 14 orders today');
        assert.dom('[data-test-truck-store]').hasText('Owned by Fleetbase Market');
        assert.dom('[data-test-truck-edit]').exists();
        // Assign catalogs, open vehicle and delete sit behind the one ellipsis menu.
        assert.dom('[data-test-truck-menu]').exists();
    });

    test('presence follows the truck status alone: inactive, or active with nothing to sell', async function (assert) {
        this.set('truck', { status: 'inactive', online: true, vehicle: { id: 'v1', online: true }, catalogs: [{}] });
        await render(hbs`<Storefront::Truck::Card @truck={{this.truck}} />`);
        assert.dom('[data-test-truck-presence]').includesText('Inactive', 'an inactive truck is inactive though its vehicle is online');

        // Its vehicle reporting offline does not take an active truck off sale.
        this.set('truck', { status: 'active', online: false, vehicle: { id: 'v1', online: false }, catalogs: [] });
        await render(hbs`<Storefront::Truck::Card @truck={{this.truck}} />`);
        assert.dom('[data-test-truck-presence]').includesText('Active, no catalog');
        assert.dom('[data-test-truck-presence]').doesNotIncludeText('Offline');

        this.set('truck', { status: 'active', online: true, catalogs: [] });
        await render(hbs`<Storefront::Truck::Card @truck={{this.truck}} />`);
        assert.dom('[data-test-truck-presence]').includesText('Active, no catalog');
        assert.dom('[data-test-truck-catalogs-count]').hasText('No catalogs · no orders today');
        assert.dom('[data-test-truck-area]').hasText('No service area');
        assert.dom('[data-test-truck-vehicle] [data-test-resource-pill-title]').hasText('No vehicle assigned');
    });

    test('the title opens the truck, not the Fleet-Ops vehicle', async function (assert) {
        const opened = [];
        this.set('truck', { id: 't1', status: 'active', vehicle: { id: 'v1', display_name: 'Truck 10', online: true }, catalogs: [] });
        this.set('open', (truck) => opened.push(truck));

        await render(hbs`<Storefront::Truck::Card @truck={{this.truck}} @onOpen={{this.open}} />`);
        await click('[data-test-truck-vehicle] [data-test-resource-pill-title]');

        assert.strictEqual(opened.length, 1);
        assert.strictEqual(opened[0], this.truck);
    });
});
