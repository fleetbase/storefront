import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';
import formatCurrency from '@fleetbase/ember-ui/utils/format-currency';

export default class WidgetStorefrontKpiTileComponent extends Component {
    @service fetch;
    @service storefront;
    @service storefrontDashboard;

    @tracked data = null;
    @tracked error = null;

    constructor() {
        super(...arguments);
        this.load.perform();
        this.storefront.on('order.broadcasted', () => {
            this.load.perform();
        });
        this.storefront.on('storefront.changed', () => {
            this.load.perform();
        });
        this.storefrontDashboard.on('periodChanged', () => {
            this.load.perform();
        });
    }

    get storeId() {
        return this.storefront.activeStore?.public_id ?? this.storefront.activeStore?.id;
    }

    get metric() {
        return this.data?.metrics?.[this.args.metric] ?? {};
    }

    get title() {
        return this.args.title ?? 'Metric';
    }

    get formattedValue() {
        const value = this.metric.value ?? 0;

        if (this.metric.format === 'money') {
            return formatCurrency(value, this.metric.currency ?? this.data?.currency ?? 'USD');
        }

        if (this.metric.format === 'percent') {
            return `${value}%`;
        }

        return Number(value).toLocaleString();
    }

    get deltaText() {
        const delta = this.metric.delta_percent;
        if (typeof delta !== 'number') {
            return '0%';
        }

        return `${delta > 0 ? '+' : ''}${delta}%`;
    }

    get deltaDirection() {
        const delta = this.metric.delta_percent ?? 0;
        if (delta === 0) {
            return 'neutral';
        }

        const isGood = this.metric.inverse ? delta < 0 : delta > 0;

        return isGood ? 'good' : 'bad';
    }

    get deltaIcon() {
        if (this.deltaDirection === 'neutral') {
            return 'minus';
        }

        return (this.metric.delta_percent ?? 0) > 0 ? 'arrow-up' : 'arrow-down';
    }

    get accentClass() {
        return `storefront-kpi-accent-${this.args.accent ?? 'blue'} storefront-kpi-trend-${this.deltaDirection}`;
    }

    @task *load() {
        try {
            this.data = yield this.fetch.get('analytics/overview', this.storefrontDashboard.withStore(this.storeId), { namespace: 'storefront/int/v1' });
            this.error = null;
        } catch (error) {
            this.error = error?.message ?? 'Unable to load metric';
        }
    }
}
