import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action, get } from '@ember/object';
import { later } from '@ember/runloop';
import { debug } from '@ember/debug';
import { task } from 'ember-concurrency';

export default class WidgetCustomersComponent extends Component {
    @service store;
    @service storefront;
    @service intl;
    @service contextPanel;
    @tracked loaded = false;
    @tracked customers = [];
    @tracked title = this.intl.t('storefront.component.widget.customers.widget-title');

    constructor(owner, { title }) {
        super(...arguments);
        this.title = title ?? this.intl.t('storefront.component.widget.customers.widget-title');
        this.loadCustomers.perform();
        this.storefront.on('order.broadcasted', () => {
            this.loadCustomers.perform();
        });
        this.storefront.on('storefront.changed', () => {
            this.loadCustomers.perform();
        });
    }

    @action viewCustomer(customer) {
        this.contextPanel.focus(customer, 'viewing');
    }

    @task *loadCustomers(params = {}) {
        const storefront = get(this.storefront, 'activeStore.public_id');

        try {
            const customers = yield this.store.query('customer', {
                storefront,
                limit: 14,
                ...params
            });
            this.loaded = true;
            this.customers = customers;

            return customers;
        } catch (err) {
            debug('Error loading customers for widget:', err);
        }
    }
}
