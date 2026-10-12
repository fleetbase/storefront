import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { setupIntl } from 'ember-intl/test-support';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

function order(overrides = {}) {
    return {
        public_id: 'order_abc',
        tracking: 'FLE123MN',
        status: 'created',
        notes: '',
        createdAgo: '2 minutes ago',
        customer: { id: 'c1', name: 'Bold Batbayar', phone: '+97699110001', email: 'bold@example.mn' },
        driver_assigned: null,
        tracking_number: { qr_code: 'QR', barcode: 'BAR' },
        payload: {
            pickup: { name: 'Zaisan', address: 'Zaisan, Ulaanbaatar' },
            dropoff: { name: 'T2', address: 'T2 - 26 Peace Avenue' },
            entities: [{ name: 'Milk tea', description: 'Hot', meta: { quantity: 2, subtotal: 4000 } }],
        },
        meta: { currency: 'MNT', subtotal: 4000, delivery_fee: 1000, total: 5000, tip: '0', gateway: 'qpay' },
        ...overrides,
    };
}

module('Integration | Component | modals/incoming-order', function (hooks) {
    setupRenderingTest(hooks);
    setupIntl(hooks, 'en-us');

    test('a delivery reads as a ticket: store and total, tracking number, route, people, items and label codes', async function (assert) {
        this.set('options', { title: 'New order', order: order(), store: { name: 'Khaan Buuz', currency: 'MNT' } });

        await render(hbs`<Modals::IncomingOrder @modalIsOpened={{true}} @options={{this.options}} />`);

        assert.dom('[data-test-incoming-order-store]').hasText('Khaan Buuz');
        assert.dom('[data-test-incoming-order-tracking]').hasText('FLE123MN');
        assert.dom('[data-test-incoming-order]').includesText('order_abc');
        assert.dom('[data-test-incoming-order]').includesText('2 items', 'the item count follows the quantities');
        assert.dom('[data-test-incoming-order-timing]').includesText('ASAP');
        assert.dom('[data-test-incoming-order-route] li').exists({ count: 2 }, 'store location and drop-off');
        assert.dom('[data-test-incoming-order-route]').includesText('T2 - 26 Peace Avenue');
        assert.dom('[data-test-incoming-order-route]').doesNotIncludeText('T2 ·', 'a name the address starts with is not repeated');
        assert.dom('[data-test-incoming-order]').includesText('+97699110001');
        assert.dom('[data-test-incoming-order]').includesText('No driver assigned');
        assert.dom('[data-test-incoming-order-codes] img').exists({ count: 2 });
        assert.dom('[data-test-incoming-order-note]').doesNotExist('no note, no section');
        assert.dom('[data-test-incoming-order]').doesNotIncludeText('Tip', 'a zero tip is left out');
    });

    test('a pickup has one stop, needs no driver and shows the customer note', async function (assert) {
        this.set('options', {
            order: order({
                notes: 'Ring the bell',
                meta: { currency: 'MNT', subtotal: 4000, total: 4000, is_pickup: true },
                payload: { pickup: { name: 'Counter', address: 'Bagatoiruu 12' }, entities: [] },
            }),
            store: { name: 'Khaan Buuz' },
        });

        await render(hbs`<Modals::IncomingOrder @modalIsOpened={{true}} @options={{this.options}} />`);

        assert.dom('[data-test-incoming-order-route] li').exists({ count: 1 });
        assert.dom('[data-test-incoming-order-route]').includesText('Customer collects from');
        assert.dom('[data-test-incoming-order]').includesText('No driver needed');
        assert.dom('[data-test-incoming-order-assign-driver]').doesNotExist();
        assert.dom('[data-test-incoming-order-note]').hasText('Ring the bell');
    });
});
