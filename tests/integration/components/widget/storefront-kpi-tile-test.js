import Service from '@ember/service';
import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

class StorefrontStubService extends Service {
    activeStore = { public_id: 'store_1' };
    on() {}
}

module('Integration | Component | widget/storefront-kpi-tile', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:storefront', StorefrontStubService);
    });

    test('it renders a loaded KPI value and trend', async function (assert) {
        class FetchStubService extends Service {
            get() {
                return {
                    currency: 'USD',
                    metrics: {
                        revenue: {
                            value: 12500,
                            previous: 10000,
                            delta_percent: 25,
                            format: 'money',
                            currency: 'USD',
                        },
                    },
                };
            }
        }

        this.owner.register('service:fetch', FetchStubService);

        await render(hbs`<Widget::StorefrontKpiTile @metric="revenue" @title="Revenue" @icon="sack-dollar" />`);

        assert.dom('.storefront-kpi-tile').exists();
        assert.dom('.storefront-kpi-tile').includesText('Revenue');
        assert.dom('.storefront-kpi-delta').hasText('+25%');
    });

    test('it renders an error state', async function (assert) {
        class FetchStubService extends Service {
            get() {
                throw new Error('Metrics unavailable');
            }
        }

        this.owner.register('service:fetch', FetchStubService);

        await render(hbs`<Widget::StorefrontKpiTile @metric="revenue" @title="Revenue" @icon="sack-dollar" />`);

        assert.dom('.storefront-kpi-tile').includesText('Metrics unavailable');
    });

    test('it renders inverse trend styling for cancellation rate', async function (assert) {
        class FetchStubService extends Service {
            get() {
                return {
                    metrics: {
                        cancellation_rate: {
                            value: 8,
                            previous: 4,
                            delta_percent: 100,
                            format: 'percent',
                            inverse: true,
                        },
                    },
                };
            }
        }

        this.owner.register('service:fetch', FetchStubService);

        await render(hbs`<Widget::StorefrontKpiTile @metric="cancellation_rate" @title="Cancellation Rate" @icon="ban" @accent="rose" />`);

        assert.dom('.storefront-kpi-tile').hasClass('storefront-kpi-accent-rose');
        assert.dom('.storefront-kpi-tile').hasClass('storefront-kpi-trend-bad');
        assert.dom('.storefront-kpi-delta').hasText('+100%');
    });
});
