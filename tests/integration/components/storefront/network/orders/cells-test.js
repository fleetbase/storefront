import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { setupIntl } from 'ember-intl/test-support';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import { initialize } from '@fleetbase/storefront-engine/instance-initializers/register-storefront-resource-descriptors';
import { initialize as initializeShared } from '@fleetbase/fleetops-data/instance-initializers/register-shared-resource-descriptors';

module('Integration | Component | storefront/network/orders cells', function (hooks) {
    setupRenderingTest(hooks);
    setupIntl(hooks, 'en-us');

    hooks.beforeEach(function () {
        initializeShared(this.owner);
        initialize(this.owner);
        this.set('storesById', {
            store_a: { public_id: 'store_a', name: 'Rochor Noodle House', currency: 'SGD', logo_url: 'https://cdn.test/a.png' },
            store_b: { public_id: 'store_b', name: 'Orchard Grocers', currency: 'SGD', logo_url: 'https://cdn.test/b.png' },
        });
    });

    test('one store reads as a single-line identity cell, without the currency underneath', async function (assert) {
        this.set('row', { meta: { storefront_id: 'store_a', storefront: 'Rochor Noodle House' } });

        await render(hbs`<Storefront::Network::Orders::Cell::Stores @row={{this.row}} @column={{hash storesById=this.storesById}} />`);

        assert.dom('[data-test-order-store-identity] [data-test-identity-label]').hasText('Rochor Noodle House');
        assert.dom('[data-test-order-store-identity] [data-test-resource-identity-image]').exists();
        assert.dom('[data-test-order-store-avatar]').doesNotExist();
    });

    test('a checkout across stores shows one round logo per store, named for screen readers', async function (assert) {
        this.set('row', { isGroup: true, storeIds: ['store_a', 'store_b', 'store_x'], storeNames: ['Rochor Noodle House', 'Orchard Grocers', 'Unknown Mart'] });

        await render(hbs`<Storefront::Network::Orders::Cell::Stores @row={{this.row}} @column={{hash storesById=this.storesById}} />`);

        assert.dom('[data-test-order-stores]').hasClass('storefront-identity-stack--avatars');
        assert.dom('[data-test-order-store-avatar]').exists({ count: 3 }, 'one evenly spaced slot per store');
        assert.dom('[data-test-order-store-avatar]').hasAttribute('aria-label', 'Rochor Noodle House');
        assert.dom('[data-test-order-store-avatar] img').exists({ count: 2 }, 'the loaded stores show their logo');
        assert.dom('.storefront-identity-stack__initial').hasText('U', 'a store that is not loaded falls back to its initial');
        assert.dom('.storefront-identity-stack__initial').hasAttribute('title', 'Unknown Mart');
    });

    test('an order reference is one line: the tracking number, with the order id on hover', async function (assert) {
        this.set('row', { public_id: 'order_1', tracking: 'FLE123SG', item_count: 2, meta: { checkout_id: 'chkt_123' } });
        this.set('column', { onView() {}, onToggle() {}, isChild: () => false });

        await render(hbs`<Storefront::Network::Orders::Cell::Reference @row={{this.row}} @column={{this.column}} />`);

        assert.dom('[data-test-order-tracking]').hasText('FLE123SG');
        assert.dom('[data-test-order-tracking]').hasAttribute('title', 'order_1');
        assert.dom('.storefront-order-ref__meta').doesNotExist('no item count or checkout under an order');
        assert.dom('.storefront-order-ref__branch').doesNotExist();
    });

    test('an order under an expanded checkout draws the branch', async function (assert) {
        this.set('row', { public_id: 'order_1', tracking: 'FLE123SG' });
        this.set('column', { onView() {}, onToggle() {}, isChild: () => true });

        await render(hbs`<Storefront::Network::Orders::Cell::Reference @row={{this.row}} @column={{this.column}} />`);

        assert.dom('[data-test-order-reference]').hasClass('is-child');
        assert.dom('.storefront-order-ref__branch').exists();
    });

    test('a single store and a driver are identity cells: a 20px image and one line of text', async function (assert) {
        this.set('row', { meta: { storefront_id: 'store_a', storefront: 'Rochor Noodle House' } });
        this.set('driverColumn', {});
        this.set('driverRow', { driver_assigned: { resourceType: 'driver', name: 'Ava Driver', phone: '+6590000000', photo_url: 'https://cdn.test/d.png' } });

        await render(hbs`
            <Storefront::Network::Orders::Cell::Stores @row={{this.row}} @column={{hash storesById=this.storesById}} />
            <Storefront::Network::Orders::Cell::Driver @row={{this.driverRow}} @column={{this.driverColumn}} />
        `);

        assert.dom('[data-test-order-store-identity] [data-test-identity-label]').hasText('Rochor Noodle House');
        assert.dom('[data-test-order-driver-identity] [data-test-identity-label]').hasText('Ava Driver');
        assert.dom('[data-test-order-driver]').doesNotIncludeText('+6590000000', 'no phone under the name');
        assert.dom('[data-test-resource-pill-subtitle]').doesNotExist();
    });

    test('an order placed at a food truck says which truck next to how it is fulfilled', async function (assert) {
        this.set('row', { meta: { is_pickup: true, food_truck_id: 'food_truck_one', food_truck: { id: 'food_truck_one', name: 'Taco Truck' } } });
        await render(hbs`<Storefront::Network::Orders::Cell::Fulfillment @row={{this.row}} />`);

        assert.dom('[data-test-order-fulfillment]').hasText('Pickup');
        assert.dom('[data-test-order-food-truck]').hasText('Taco Truck');

        // A truck that is gone still marks the order as a truck order.
        this.set('row', { meta: { is_pickup: false, food_truck_id: 'food_truck_gone', food_truck: { id: 'food_truck_gone', name: null } } });
        assert.dom('[data-test-order-food-truck]').hasText('Truck');

        this.set('row', { meta: { is_pickup: false } });
        assert.dom('[data-test-order-fulfillment]').hasText('Delivery');
        assert.dom('[data-test-order-food-truck]').doesNotExist('a store order has no truck badge');
    });
});
