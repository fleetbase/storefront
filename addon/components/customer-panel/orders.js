import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { inject as controller } from '@ember/controller';
import { action, computed, get } from '@ember/object';
import { later } from '@ember/runloop';
import { task } from 'ember-concurrency-decorators';
export default class CustomerPanelOrdersComponent extends Component {
    @service store;
    @service storefront;
    @service fetch;
    @service intl;
    @service appCache;
    @service modalsManager;
    @tracked isLoading = true;
    @tracked orders = [];
    @controller('orders.index.view') orderDetailsController;

    @computed('args.title') get title() {
        return this.args.title ?? this.intl.t('storefront.component.widget.orders.widget-title');
    }

    constructor() {
        super(...arguments);
        this.reloadOrders.perform();
    }

    @task *reloadOrders(params = {}) {
        console.log('Reloading orders...');
        this.orders = yield this.fetchOrders(params);
    }

    @action fetchOrders(params = {}) {
        this.isLoading = true;

        return new Promise((resolve) => {
            this.fetch
                .get('customers/orders', null, {
                    namespace: 'storefront/int/v1',
                    normalizeToEmberData: true,
                })
                .then((orders) => {
                    this.isLoading = false;
                    console.log('Orders: ', orders);
                    resolve(orders);
                })
                .catch((err) => {
                    this.isLoading = false;
                    console.error('Error fetching orders: ', err);
                    resolve(this.orders);
                });
        });
    }

    @action async viewOrder(order) {
        await this.orderDetailsController.viewOrder(order);
    }

    @action async acceptOrder(order) {
        await this.orderDetailsController.acceptOrder(order);
    }

    @action markAsReady(order) {
        this.orderDetailsController.markAsReady(order);
    }

    @action markAsCompleted(order) {
        this.orderDetailsController.markAsCompleted(order);
    }

    @action async assignDriver(order) {
        await this.orderDetailsController.assignDriver(order);
    }
}
