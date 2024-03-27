import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { inject as controller } from '@ember/controller';
import { action, computed, get } from '@ember/object';
import { later } from '@ember/runloop';
export default class WidgetOrdersComponent extends Component {
    @service store;
    @service storefront;
    @service fetch;
    @service intl;
    @service appCache;
    @service modalsManager;
    @service contextPanel;
    @tracked isLoading = true;
    @tracked orders = [];
    @controller('orders.index.view') orderDetailsController;

    @computed('args.title') get title() {
        return this.args.title ?? this.intl.t('storefront.component.widget.orders.widget-title');
    }

    constructor() {
        super(...arguments);
    }

    @action async setupWidget() {
        later(
            this,
            () => {
                this.reloadOrders();
            },
            100
        );

        // reload orders when new order income
        this.storefront.on('order.broadcasted', () => {
            this.reloadOrders();
        });

        // reload orders when store changes
        this.storefront.on('storefront.changed', () => {
            this.reloadOrders();
        });
    }

    @action async reloadOrders(params = {}) {
        this.orders = await this.fetchOrders(params);
    }

    @action fetchOrders(params = {}) {
        let cachedOrders;

        try {
            if (this.appCache.has('storefront_recent_orders')) {
                cachedOrders = this.appCache.getEmberData('storefront_recent_orders', 'order');
            }
        } catch (exception) {
            // silent exception just load orders from
        }

        if (cachedOrders) {
            this.orders = cachedOrders;
        }

        this.isLoading = true;

        return new Promise((resolve) => {
            const storefront = get(this.storefront, 'activeStore.public_id');

            if (!storefront) {
                this.isLoading = false;
                return resolve([]);
            }

            const queryParams = {
                storefront,
                limit: 14,
                sort: '-created_at',
                ...params,
            };

            this.fetch
                .get('orders', queryParams, {
                    namespace: 'storefront/int/v1',
                    normalizeToEmberData: true,
                })
                .then((orders) => {
                    this.isLoading = false;

                    try {
                        this.appCache.setEmberData('storefront_recent_orders', orders, ['tracking_statuses', 'tracking_number']);
                    } catch (exception) {
                        // silent exception just clear from cache if not able to set
                        this.appCache.set('storefront_recent_orders', undefined);
                    }

                    resolve(orders);
                })
                .catch(() => {
                    this.isLoading = false;

                    resolve(this.orders);
                });
        });
    }

    @action async viewOrder(order) {
        this.contextPanel.focus(order, 'viewing');
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
