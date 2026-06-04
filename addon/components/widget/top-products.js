import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class WidgetTopProductsComponent extends Component {
    static widgetId = 'storefront-top-products-widget';

    @service fetch;
    @service storefront;
    @service storefrontDashboard;

    @tracked products = [];
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

    @task *load() {
        try {
            const response = yield this.fetch.get('analytics/top-products', this.storefrontDashboard.withStore(this.storeId), { namespace: 'storefront/int/v1' });
            this.products = response.products ?? [];
            this.error = null;
        } catch (error) {
            this.error = error?.message ?? 'Unable to load top products';
        }
    }
}
