import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import { initialize } from '@fleetbase/fleetops-data/instance-initializers/register-shared-resource-descriptors';

module('Integration | Component | storefront/order/details', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        initialize(this.owner);
    });

    test('it renders dedicated storefront order detail panels', async function (assert) {
        this.set('order', {
            public_id: 'order_test',
            internal_id: '1001',
            status: 'created',
            createdAt: 'May 29, 2026 18:00',
            scheduledAt: null,
            transaction_amount: 100,
            meta: {
                storefront: {
                    name: 'Tasty Store',
                    logo_url: 'https://example.com/store.png',
                },
                subtotal: 90,
                delivery_fee: 10,
                total: 100,
                currency: 'USD',
                gateway: 'cash',
                is_pickup: true,
            },
            customer: {},
            driver_assigned: {},
            payload: {
                pickup: {},
                dropoff: {},
                entities: [
                    {
                        name: 'Burger',
                        description: 'Cheeseburger',
                        image_url: 'https://example.com/burger.png',
                        meta: {
                            quantity: 2,
                            subtotal: 20,
                        },
                    },
                ],
            },
            tracking_number: {},
            tracking_statuses: [],
            files: [],
        });

        await render(hbs`<Storefront::Order::Details @resource={{this.order}} />`);

        assert.dom('.next-content-panel-title-container').hasTextContaining('Activity');
        assert.dom('.next-content-panel-title-container').hasTextContaining('Store');
        assert.dom('.next-content-panel-title-container').hasTextContaining('Order');
        assert.dom('.next-content-panel-title-container').hasTextContaining('Details');
        assert.dom('.next-content-panel-title-container').hasTextContaining('Customer Insights');
        assert.dom('.next-content-panel-title-container').hasTextContaining('Route');
        assert.dom('.next-content-panel-title-container').hasTextContaining('Metadata');
        assert.dom('.storefront-order-person__name').hasTextContaining('Tasty Store');
        assert.dom('[data-test-order-driver-pill] [data-test-resource-pill-title]').hasText('No driver assigned', 'an unassigned driver is a static pill with the fallback title');
        assert.dom('[data-test-order-customer-pill] [data-test-resource-pill-title]').hasText('No customer');
        assert.dom('.storefront-order-item__image').exists();
        assert.dom('.storefront-order-item__price').exists();
        assert.dom().doesNotContainText('Delivery Address');
        assert.dom().doesNotContainText('Pickup Address');
    });

    test('a loaded customer and driver render as their pills', async function (assert) {
        this.set('order', {
            public_id: 'order_test',
            meta: { total: 10, currency: 'USD', is_pickup: true },
            customer: { id: 'contact_1', resourceType: 'customer', name: 'Ava Chen', email: 'ava@example.test', phone: '+15550201' },
            driver_assigned: { id: 'driver_1', resourceType: 'driver', name: 'Ada Driver', phone: '+15550100' },
            payload: { pickup: {}, dropoff: {}, entities: [] },
            tracking_number: {},
            tracking_statuses: [],
            files: [],
        });

        await render(hbs`<Storefront::Order::Details @resource={{this.order}} />`);

        assert.dom('[data-test-order-customer-pill] [data-test-resource-pill-title]').hasText('Ava Chen');
        assert.dom('[data-test-order-driver-pill] [data-test-resource-pill-title]').hasText('Ada Driver');
        assert.dom('[data-test-order-driver-pill] [data-test-resource-pill-subtitle]').hasText('+15550100');
    });
});
