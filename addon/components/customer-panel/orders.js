import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action, get } from '@ember/object';
import { debug } from '@ember/debug';
import { task } from 'ember-concurrency';

export default class CustomerPanelOrdersComponent extends Component {
    @service store;
    @service storefront;
    @service fetch;
    @service intl;
    @service appCache;
    @service modalsManager;
    @service storefrontOrderActions;
    @tracked loaded = false;
    @tracked orders = [];
    @tracked customer;

    constructor(owner, { customer }) {
        super(...arguments);
        this.customer = customer;
        this.loadOrders.perform();
    }

    @action search(event) {
        this.loadOrders.perform({ query: event.target.value ?? '' });
    }

    @task *loadOrders(params = {}) {
        const storefront = get(this.storefront, 'activeStore.public_id');
        const queryParams = {
            storefront,
            limit: 14,
            sort: '-created_at',
            customer_uuid: this.customer?.id,
            ...params,
        };

        try {
            const orders = yield this.fetch.get('orders', queryParams, { namespace: 'storefront/int/v1', normalizeToEmberData: true });
            this.loaded = true;
            this.orders = orders;

            return orders;
        } catch (err) {
            debug('Error loading orders for widget:', err);
        }
    }

    @action async viewOrder(order) {
        return this.storefrontOrderActions.viewOrder(order, {
            onChange: () => {
                this.loadOrders.perform();
            },
        });
    }

    @action async acceptOrder(order) {
        await this.storefrontOrderActions.acceptOrder(order, () => {
            this.loadOrders.perform();
        });
    }

    @action markAsReady(order) {
        this.storefrontOrderActions.markAsReady(order, () => {
            this.loadOrders.perform();
        });
    }

    @action markAsCompleted(order) {
        this.storefrontOrderActions.markAsCompleted(order, () => {
            this.loadOrders.perform();
        });
    }

    @action assignDriver(order) {
        this.storefrontOrderActions.assignDriver(order, () => {
            this.loadOrders.perform();
        });
    }

    @action cancelOrder(order) {
        this.storefrontOrderActions.cancelOrder(order, () => {
            this.loadOrders.perform();
        });
    }
}
