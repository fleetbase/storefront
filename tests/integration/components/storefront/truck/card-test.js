import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { setupIntl } from 'ember-intl/test-support';
import { render } from '@ember/test-helpers';
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
            store: { name: 'Fleetbase Market' },
        });
        this.set('noop', () => {});

        await render(hbs`<Storefront::Truck::Card @truck={{this.truck}} @onEdit={{this.noop}} @onAssignCatalogs={{this.noop}} @onDelete={{this.noop}} />`);

        assert.dom('[data-test-storefront-truck-card]').exists();
        assert.dom('[data-test-truck-vehicle] [data-test-resource-pill-title]').hasText('Truck 10');
        assert.dom('[data-test-truck-vehicle] [data-test-pill-online-indicator]').hasClass('text-green-500');
        assert.dom('[data-test-truck-area]').hasText('Central · Zone 3');
        assert.dom('[data-test-truck-photo]').hasAttribute('src', 'https://cdn.test/truck.png');
        assert.dom('[data-test-truck-presence]').includesText('Online');
        assert.dom('[data-test-truck-catalogs-count]').hasText('2 catalogs');
        assert.dom('[data-test-truck-store]').hasText('Owned by Fleetbase Market');
        assert.dom('[data-test-truck-edit]').exists();
        assert.dom('[data-test-truck-catalogs]').exists();
        assert.dom('[data-test-truck-delete]').exists();
    });

    test('presence reads inactive, offline, or online with nothing to sell', async function (assert) {
        this.set('truck', { status: 'inactive', online: true, catalogs: [{}] });
        await render(hbs`<Storefront::Truck::Card @truck={{this.truck}} />`);
        assert.dom('[data-test-truck-presence]').includesText('Inactive');

        this.set('truck', { status: 'active', online: false, catalogs: [{}] });
        await render(hbs`<Storefront::Truck::Card @truck={{this.truck}} />`);
        assert.dom('[data-test-truck-presence]').includesText('Offline');

        this.set('truck', { status: 'active', online: true, catalogs: [] });
        await render(hbs`<Storefront::Truck::Card @truck={{this.truck}} />`);
        assert.dom('[data-test-truck-presence]').includesText('Online, no catalog');
        assert.dom('[data-test-truck-catalogs-count]').hasText('No catalogs');
        assert.dom('[data-test-truck-area]').hasText('No service area');
        assert.dom('[data-test-truck-vehicle] [data-test-resource-pill-title]').hasText('No vehicle assigned');
    });
});
