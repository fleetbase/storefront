import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { setupIntl } from 'ember-intl/test-support';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | storefront/order/line-items', function (hooks) {
    setupRenderingTest(hooks);
    setupIntl(hooks, 'en-us');

    test('it lists the items and totals, with tips only when there is one', async function (assert) {
        this.set('order', {
            payload: { entities: [{ name: 'Buuz', meta: { quantity: 3, subtotal: 9000, addons: [{ name: 'Extra sauce' }] } }] },
            meta: { currency: 'MNT', subtotal: 9000, delivery_fee: 1000, total: 11000, tip: '10%', delivery_tip: 0, promotions: [{ name: 'Lunch', code: 'LUNCH', amount: 500 }] },
        });

        await render(hbs`<Storefront::Order::LineItems @order={{this.order}} />`);

        assert.dom('.storefront-order-item').exists({ count: 1 });
        assert.dom('.storefront-order-item__qty').hasText('3x');
        assert.dom('.storefront-order-item').includesText('+ Extra sauce');
        assert.dom('.storefront-order-totals').includesText('Lunch');
        assert.dom('.storefront-order-totals').includesText('Tip', 'a percentage tip shows');
        assert.dom('.storefront-order-totals').doesNotIncludeText('Delivery tip', 'a zero delivery tip is left out');
    });
});
