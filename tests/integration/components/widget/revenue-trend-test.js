import Component from '@glimmer/component';
import Service from '@ember/service';
import { setComponentTemplate } from '@ember/component';
import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

let revenueTrendResponse;

class StorefrontStubService extends Service {
    activeStore = { public_id: 'store_1', currency: 'USD' };
    on() {}
}

class StorefrontDashboardStubService extends Service {
    withStore(store) {
        return { store };
    }

    on() {}
}

class FetchStubService extends Service {
    get() {
        return revenueTrendResponse;
    }
}

class ChartStubComponent extends Component {
    get legendBoxWidth() {
        return this.args.options.plugins.legend.labels.boxWidth;
    }

    get maxTicksLimit() {
        return this.args.options.scales.x.ticks.maxTicksLimit;
    }

    get xTickFontSize() {
        return this.args.options.scales.x.ticks.font.size;
    }

    get revenueData() {
        return this.args.datasets.find((dataset) => dataset.label === 'Revenue')?.data.join(',');
    }

    get ordersData() {
        return this.args.datasets.find((dataset) => dataset.label === 'Orders')?.data.join(',');
    }

    get revenueTick() {
        return this.args.options.scales.y.ticks.callback(this.args.datasets.find((dataset) => dataset.label === 'Revenue')?.data[0]);
    }

    get revenueTooltip() {
        return this.args.options.plugins.tooltip.callbacks.label({
            dataset: { label: 'Revenue' },
            parsed: { y: this.args.datasets.find((dataset) => dataset.label === 'Revenue')?.data[0] },
        });
    }

    get ordersTooltip() {
        return this.args.options.plugins.tooltip.callbacks.label({
            dataset: { label: 'Orders' },
            parsed: { y: this.args.datasets.find((dataset) => dataset.label === 'Orders')?.data[0] },
        });
    }
}

module('Integration | Component | widget/revenue-trend', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        revenueTrendResponse = {
            labels: ['2026-06-01', '2026-06-02'],
            datasets: [
                { label: 'Revenue', data: [267545, 0] },
                { label: 'Orders', data: [29, 0] },
            ],
            summary: { revenue: 267545, orders: 29, currency: 'USD' },
        };

        this.owner.register('service:storefront', StorefrontStubService);
        this.owner.register('service:storefront-dashboard', StorefrontDashboardStubService);
        this.owner.register('service:fetch', FetchStubService);
        this.owner.register(
            'component:chart',
            setComponentTemplate(
                hbs`
                    <div class="chart-stub">{{this.legendBoxWidth}}/{{this.maxTicksLimit}}/{{this.xTickFontSize}}</div>
                    <div data-test-revenue-data>{{this.revenueData}}</div>
                    <div data-test-orders-data>{{this.ordersData}}</div>
                    <div data-test-revenue-tick>{{this.revenueTick}}</div>
                    <div data-test-revenue-tooltip>{{this.revenueTooltip}}</div>
                    <div data-test-orders-tooltip>{{this.ordersTooltip}}</div>
                `,
                ChartStubComponent
            )
        );
    });

    test('it passes compact chart options to the chart', async function (assert) {
        await render(hbs`<Widget::RevenueTrend />`);

        assert.dom('.storefront-chart-body').exists();
        assert.dom('.storefront-chart-frame').exists();
        assert.dom('.chart-stub').hasText('6/6/10');
    });

    test('it normalizes minor-unit revenue data for the chart and formats currency labels', async function (assert) {
        await render(hbs`<Widget::RevenueTrend />`);

        assert.dom('.storefront-widget-subtitle').hasText('$2,675.45 across 29 orders');
        assert.dom('[data-test-revenue-data]').hasText('2675.45,0');
        assert.dom('[data-test-orders-data]').hasText('29,0');
        assert.dom('[data-test-revenue-tick]').hasText('$2,675.45');
        assert.dom('[data-test-revenue-tooltip]').hasText('Revenue: $2,675.45');
        assert.dom('[data-test-orders-tooltip]').hasText('Orders: 29');
    });

    test('it uses currency precision when normalizing chart revenue', async function (assert) {
        revenueTrendResponse = {
            labels: ['2026-06-01'],
            datasets: [
                { label: 'Revenue', data: [267545] },
                { label: 'Orders', data: [4] },
            ],
            summary: { revenue: 267545, orders: 4, currency: 'JPY' },
        };

        await render(hbs`<Widget::RevenueTrend />`);

        assert.dom('[data-test-revenue-data]').hasText('267545');

        revenueTrendResponse = {
            labels: ['2026-06-01'],
            datasets: [
                { label: 'Revenue', data: [1234567] },
                { label: 'Orders', data: [4] },
            ],
            summary: { revenue: 1234567, orders: 4, currency: 'KWD' },
        };

        await render(hbs`<Widget::RevenueTrend />`);

        assert.dom('[data-test-revenue-data]').hasText('1234.567');
    });
});
