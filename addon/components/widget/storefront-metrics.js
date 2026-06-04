import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { get } from '@ember/object';
import { debug } from '@ember/debug';
import { task } from 'ember-concurrency';

export default class WidgetStorefrontMetricsComponent extends Component {
    @service fetch;
    @service storefront;
    @service storefrontDashboard;

    @tracked title = 'This Month';
    @tracked metrics = {
        orders_count: 0,
        customers_count: 0,
        stores_count: 0,
        earnings_sum: 0,
    };

    constructor(owner, { title = 'This Month' }) {
        super(...arguments);
        this.title = title;
        this.loadMetrics.perform();
        this.storefront.on('order.broadcasted', () => {
            this.loadMetrics.perform();
        });
        this.storefront.on('storefront.changed', () => {
            this.loadMetrics.perform();
        });
        this.storefrontDashboard.on('periodChanged', () => {
            this.loadMetrics.perform();
        });
    }

    @task *loadMetrics() {
        const store = get(this.storefront, 'activeStore.id');

        try {
            const metrics = yield this.fetch.get('actions/metrics', this.storefrontDashboard.withStore(store), { namespace: 'storefront/int/v1' });
            this.metrics = metrics;
            return metrics;
        } catch (err) {
            debug('Error loading storefront metrics:', err);
        }
    }
}
