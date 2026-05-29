import Component from '@glimmer/component';
import Service from '@ember/service';
import { setComponentTemplate } from '@ember/component';
import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

class StorefrontStubService extends Service {
    activeStore = { public_id: 'store_1' };
    on() {}
}

class FetchStubService extends Service {
    get() {
        return {
            labels: ['2026-05-01', '2026-05-02'],
            datasets: [{ label: 'Revenue', data: [10, 20] }],
            summary: { revenue: 30, orders: 2, currency: 'USD' },
        };
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
}

module('Integration | Component | widget/revenue-trend', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:storefront', StorefrontStubService);
        this.owner.register('service:fetch', FetchStubService);
        this.owner.register(
            'component:chart',
            setComponentTemplate(hbs`<div class="chart-stub">{{this.legendBoxWidth}}/{{this.maxTicksLimit}}/{{this.xTickFontSize}}</div>`, ChartStubComponent)
        );
    });

    test('it passes compact chart options to the chart', async function (assert) {
        await render(hbs`<Widget::RevenueTrend />`);

        assert.dom('.storefront-chart-body').exists();
        assert.dom('.storefront-chart-frame').exists();
        assert.dom('.chart-stub').hasText('6/6/10');
    });
});
