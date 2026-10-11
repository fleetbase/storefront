import Service from '@ember/service';
import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

class StorefrontStubService extends Service {
    contextScope() {
        return this.isNetworkContext ? { network: this.activeNetwork?.public_id } : { storefront: this.activeStore?.public_id };
    }

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
        assert.dom('[data-test-kpi-period]').hasText('vs previous period');
    });

    test('it says there is no previous period instead of claiming +100%', async function (assert) {
        class FetchStubService extends Service {
            get() {
                return {
                    currency: 'USD',
                    metrics: {
                        orders: { value: 12, previous: 0, delta_percent: 100, format: 'number' },
                    },
                };
            }
        }

        this.owner.register('service:fetch', FetchStubService);

        await render(hbs`<Widget::StorefrontKpiTile @metric="orders" @title="Orders" @icon="bag-shopping" />`);

        assert.dom('.storefront-kpi-value').hasText('12');
        assert.dom('.storefront-kpi-delta').doesNotExist('no trend pill without a previous period');
        assert.dom('[data-test-kpi-period]').hasText('No previous period');
        assert.dom('.storefront-kpi-tile').hasClass('storefront-kpi-trend-neutral');
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
