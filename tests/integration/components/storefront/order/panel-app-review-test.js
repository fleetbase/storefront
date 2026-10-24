import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | storefront/order/panel-app-review', function (hooks) {
    setupRenderingTest(hooks);

    test('it marks an order an app store reviewer placed, and nothing else', async function (assert) {
        this.set('order', { meta: { app_review: true } });
        await render(hbs`<Storefront::Order::PanelAppReview @order={{this.order}} />`);

        assert.dom('[data-test-order-app-review]').exists();
        assert.dom('[data-test-order-app-review]').includesText('App Review test order');

        this.set('order', { meta: { total: 100 } });
        assert.dom('[data-test-order-app-review]').doesNotExist();

        this.set('order', null);
        assert.dom('[data-test-order-app-review]').doesNotExist();
    });
});
