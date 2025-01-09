import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action, computed, get } from '@ember/object';
import { task } from 'ember-concurrency-decorators';

export default class CustomerPanelOrdersComponent extends Component {
    @service store;
    @service storefront;
    @service fetch;
    @service intl;
    @service appCache;
    @service modalsManager;
    @service contextPanel;
    @service orderActions;
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
        this.contextPanel.focus(order, 'viewing');
    }

    @action async acceptOrder(order) {
        await this.orderActions.acceptOrder(order, () => {
            this.loadOrders.perform();
        });
    }

    @action markAsReady(order) {
        this.orderActions.markAsReady(order, () => {
            this.loadOrders.perform();
        });
    }

    @action markAsCompleted(order) {
        this.orderActions.markAsCompleted(order, () => {
            this.loadOrders.perform();
        });
    }

    @action assignDriver(order) {
        this.orderActions.assignDriver(order, () => {
            this.loadOrders.perform();
        });
    }

    @action cancelOrder(order) {
        this.orderActions.cancelOrder(order, () => {
            this.loadOrders.perform();
        });
    }
}
