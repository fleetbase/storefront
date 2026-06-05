import Service from '@ember/service';
import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

class StorefrontStubService extends Service {
    activeStore = { public_id: 'store_1' };
    on() {}
}

module('Integration | Component | widget/customer-insights', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:storefront', StorefrontStubService);
    });

    test('it renders compact customer insight content', async function (assert) {
        class FetchStubService extends Service {
            get() {
                return {
                    new_customers: 2,
                    returning_customers: 3,
                    repeat_rate: 60,
                    total_customers: 5,
                };
            }
        }

        this.owner.register('service:fetch', FetchStubService);

        await render(hbs`<Widget::CustomerInsights />`);

        assert.dom('.storefront-customer-insights-body').exists();
        assert.dom('.storefront-repeat-rate').includesText('Repeat purchase rate');
        assert.dom('.storefront-insight-note').hasText('5 buyers ordered during this period.');
    });
});
