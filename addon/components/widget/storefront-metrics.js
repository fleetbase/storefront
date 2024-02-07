import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action, computed } from '@ember/object';
import { startOfMonth, endOfMonth, format } from 'date-fns';

export default class WidgetStorefrontMetricsComponent extends Component {
    @service fetch;
    @service storefront;

    @tracked metrics = {
        orders_count: 0,
        customers_count: 0,
        stores_count: 0,
        earnings_sum: 0,
    };

    @tracked isLoading = true;
    @tracked start = format(startOfMonth(new Date()), 'yyyy-MM-dd');
    @tracked end = format(endOfMonth(new Date()), 'yyyy-MM-dd');

    @computed('args.title') get title() {
        return this.args.title || 'This Month';
    }

    @action async setupWidget() {
        this.metrics = await this.fetchMetrics(this.start, this.end);

        this.storefront.on('order.broadcasted', this.reloadMetrics);
        this.storefront.on('storefront.changed', this.reloadMetrics);
    }

    @action async reloadMetrics() {
        this.metrics = await this.fetchMetrics(this.start, this.end);
    }

    @action fetchMetrics(start, end) {
        this.isLoading = true;

        return new Promise((resolve) => {
            const store = this.storefront?.activeStore?.id;

            if (!store) {
                this.isLoading = false;
                return resolve(this.metrics);
            }

            this.fetch
                .get('actions/metrics', { start, end, store }, { namespace: 'storefront/int/v1' })
                .then((metrics) => {
                    this.isLoading = false;
                    resolve(metrics);
                })
                .catch(() => {
                    this.isLoading = false;

                    resolve(this.metrics);
                });
        });
    }
}
