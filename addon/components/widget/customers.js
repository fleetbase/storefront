import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import setComponentArg from '@fleetbase/ember-core/utils/set-component-arg';

export default class WidgetCustomersComponent extends Component {
    @service store;
    @service storefront;
    @tracked isLoading = true;
    @tracked customers = [];
    @tracked title = 'Recent Customers';

    constructor(owner, { title }) {
        super(...arguments);
        setComponentArg(this, 'title', title);
    }

    @action async getCustomers() {
        this.customers = await this.fetchCustomers();

        this.storefront.on('order.broadcasted', this.reloadCustomers);
        this.storefront.on('storefront.changed', this.reloadCustomers);
    }

    @action async reloadCustomers() {
        this.customers = await this.fetchCustomers();
    }

    @action fetchCustomers() {
        this.isLoading = true;

        return new Promise((resolve) => {
            const storefront = this.storefront.getActiveStore('public_id');

            if (!storefront) {
                this.isLoading = false;
                return resolve([]);
            }

            this.store
                .query('customer', {
                    storefront,
                    limit: 14,
                })
                .then((customers) => {
                    this.isLoading = false;
                    resolve(customers);
                })
                .catch(() => {
                    this.isLoading = false;
                    resolve(this.customers);
                });
        });
    }
}
