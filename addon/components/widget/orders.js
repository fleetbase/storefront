import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action, computed, get } from '@ember/object';
import { later } from '@ember/runloop';

export default class WidgetOrdersComponent extends Component {
    @service store;
    @service storefront;
    @service fetch;
    @service intl;
    @service appCache;
    @service modalsManager;
    @tracked isLoading = true;
    @tracked orders = [];

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
        const store = this.storefront.activeStore;

        if (order.isFresh) {
            return this.acceptOrder(order);
        }

        if (order.isPreparing) {
            return this.markAsReady(order);
        }

        if (order.isPickupReady) {
            return this.markAsCompleted(order);
        }

        this.modalsManager.show('modals/incoming-order', {
            title: `${order.public_id}`,
            hideAcceptButton: true,
            declineButtonText: 'Done',
            declineButtonScheme: 'primary',
            declineButtonIcon: 'check',
            assignDriver: async () => {
                await this.modalsManager.done();
                this.assignDriver(order);
            },
            order,
            store,
        });
    }

    @action async acceptOrder(order) {
        const store = this.storefront.activeStore;

        await order.loadPayload();
        await order.loadCustomer();

        this.modalsManager.show('modals/incoming-order', {
            title: this.intl.t('storefront.component.widget.orders.accept-order'),
            acceptButtonText: this.intl.t('storefront.component.widget.orders.accept-order'),
            acceptButtonScheme: 'success',
            acceptButtonIcon: 'check',
            order,
            store,
            confirm: (modal) => {
                modal.startLoading();

                return this.fetch.post('orders/accept', { order: order.id }, { namespace: 'storefront/int/v1' }).then(() => {
                    return this.fetchOrders().then((orders) => {
                        this.orders = orders;
                        modal.stopLoading();
                    });
                });
            },
        });
    }

    @action markAsReady(order) {
        // for pickup orders
        if (order.meta?.is_pickup === true) {
            this.modalsManager.confirm({
                title: this.intl.t('storefront.component.widget.orders.mark-as-ready-modal-pickup-title'),
                body: this.intl.t('storefront.component.widget.orders.mark-as-ready-modal-pickup-body'),
                acceptButtonText: this.intl.t('storefront.component.widget.orders.mark-as-ready-modal-pickup-accept-button-text'),
                acceptButtonIcon: 'check',
                acceptButtonScheme: 'success',
                confirm: (modal) => {
                    modal.startLoading();

                    return this.fetch.post('orders/ready', { order: order.id }, { namespace: 'storefront/int/v1' }).then(() => {
                        return this.fetchOrders().then((orders) => {
                            this.orders = orders;
                            modal.stopLoading();
                        });
                    });
                },
            });
        }

        if (!order.adhoc) {
            // prompt to assign driver then dispatch
            return this.modalsManager.show('modals/order-ready-assign-driver', {
                title: this.intl.t('storefront.component.widget.orders.mark-as-ready-modal-not-adhoc-title'),
                acceptButtonText: this.intl.t('storefront.component.widget.orders.mark-as-ready-modal-not-adhoc-accept-button-text'),
                acceptButtonScheme: 'success',
                acceptButtonIcon: 'check',
                adhoc: false,
                driver: null,
                order,
                confirm: (modal) => {
                    modal.startLoading();

                    return this.fetch
                        .post('orders/ready', { order: order.id, driver: modal.getOption('driver.id'), adhoc: modal.getOption('adhoc') }, { namespace: 'storefront/int/v1' })
                        .then(() => {
                            return this.fetchOrders().then((orders) => {
                                this.orders = orders;
                                modal.stopLoading();
                            });
                        });
                },
            });
        }

        this.modalsManager.confirm({
            title: this.intl.t('storefront.component.widget.orders.mark-as-ready-modal-title'),
            body: this.intl.t('storefront.component.widget.orders.mark-as-ready-modal-body'),
            acceptButtonText: this.intl.t('storefront.component.widget.orders.mark-as-ready-modal-accept-button-text'),
            acceptButtonIcon: 'check',
            acceptButtonScheme: 'success',
            confirm: (modal) => {
                modal.startLoading();

                return this.fetch.post('orders/ready', { order: order.id }, { namespace: 'storefront/int/v1' }).then(() => {
                    return this.fetchOrders().then((orders) => {
                        this.orders = orders;
                        modal.stopLoading();
                    });
                });
            },
        });
    }

    @action markAsCompleted(order) {
        this.modalsManager.confirm({
            title: this.intl.t('storefront.component.widget.orders.mark-as-completed-modal-title'),
            body: this.intl.t('storefront.component.widget.orders.mark-as-completed-modal-body'),
            acceptButtonText: this.intl.t('storefront.component.widget.orders.mark-as-completed-accept-button-text'),
            acceptButtonIcon: 'check',
            acceptButtonScheme: 'success',
            confirm: (modal) => {
                modal.startLoading();

                return this.fetch.post('orders/completed', { order: order.id }, { namespace: 'storefront/int/v1' }).then(() => {
                    return this.fetchOrders().then((orders) => {
                        this.orders = orders;
                        modal.stopLoading();
                    });
                });
            },
        });
    }

    @action async assignDriver(order) {
        await order.loadDriver();

        this.modalsManager.show('modals/assign-driver', {
            title: this.intl.t('storefront.component.widget.orders.assign-driver-modal-title'),
            acceptButtonText: this.intl.t('storefront.component.widget.orders.assign-driver-modal-accept-button-text'),
            acceptButtonScheme: 'success',
            acceptButtonIcon: 'check',
            driver: order.driver_assigned,
            order,
            confirm: (modal) => {
                modal.startLoading();

                return order.save();
            },
        });
    }
}
