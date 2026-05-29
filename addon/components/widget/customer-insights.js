import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class WidgetCustomerInsightsComponent extends Component {
    static widgetId = 'storefront-customer-insights-widget';

    @service fetch;
    @service storefront;

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
    }

    get storeId() {
        return this.storefront.activeStore?.public_id ?? this.storefront.activeStore?.id;
    }

    get returningPercentStyle() {
        return `width: ${this.data?.repeat_rate ?? 0}%`;
    }

    @task *load() {
        try {
            this.data = yield this.fetch.get('analytics/customer-insights', { store: this.storeId }, { namespace: 'storefront/int/v1' });
            this.error = null;
        } catch (error) {
            this.error = error?.message ?? 'Unable to load customer insights';
        }
    }
}
