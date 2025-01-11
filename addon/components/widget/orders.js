import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action, get } from '@ember/object';
import { debug } from '@ember/debug';
import { task } from 'ember-concurrency';

export default class WidgetOrdersComponent extends Component {
    @service store;
    @service storefront;
    @service fetch;
    @service intl;
    @service appCache;
    @service modalsManager;
    @service contextPanel;
    @service orderActions;
    @service notifications;
    @tracked orders = [];
    @tracked title = this.intl.t('storefront.component.widget.orders.widget-title');
    @tracked loaded = false;
    @tracked total = 0;

    constructor(owner, { title }) {
        super(...arguments);
        this.title = title ?? this.intl.t('storefront.component.widget.orders.widget-title');
        this.currency = get(this.storefront, 'activeStore.currency');
        // this.orders = this.getCachedOrders();
        this.loadOrders.perform();
        this.storefront.on('order.broadcasted', () => {
            this.loadOrders.perform();
        });
        this.storefront.on('storefront.changed', () => {
            this.loadOrders.perform();
        });
    }

    @action async reloadOrders(params = {}) {
        this.orders = await this.fetchOrders(params);
    }

    @task *loadOrders(params = {}) {
        const storefront = get(this.storefront, 'activeStore.public_id');
        const queryParams = {
            storefront,
            limit: 14,
            sort: '-created_at',
            ...params,
        };

        try {
            const orders = yield this.fetch.get('orders', queryParams, { namespace: 'storefront/int/v1', normalizeToEmberData: true });
            this.loaded = true;
            this.updateCachedOrders(orders);
            this.total = this.calculateTotal(orders);
            this.orders = orders;

            return orders;
        } catch (err) {
            debug('Error loading orders for widget:', err);
        }
    }

    calculateTotal(orders = []) {
        let total = 0;
        orders.forEach((order) => {
            total += get(order, 'meta.total');
        });

        return total;
    }

    getCachedOrders() {
        let cachedOrders = [];
        if (this.appCache.has('storefront_recent_orders')) {
            cachedOrders = this.appCache.getEmberData('storefront_recent_orders', 'order');
        }

        return cachedOrders;
    }

    updateCachedOrders(orders = []) {
        try {
            this.appCache.setEmberData('storefront_recent_orders', orders, ['tracking_statuses', 'tracking_number']);
        } catch (err) {
            this.appCache.set('storefront_recent_orders', undefined);
            debug('Error updating orders widget cache:', err);
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
