import Component from '@glimmer/component';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import contextComponentCallback from '@fleetbase/ember-core/utils/context-component-callback';
import { tracked } from '@glimmer/tracking';

export default class OrderPanelDetailsComponent extends Component {
    @service store;
    @service storefront;
    @service fetch;
    @service intl;
    @service appCache;
    @service modalsManager;
    @tracked orders = [];

    constructor() {
        super(...arguments);
    }

    /**
     * Sets the overlay context.
     *
     * @action
     * @param {OverlayContextObject} overlayContext
     */
    @action setOverlayContext(overlayContext) {
        this.context = overlayContext;
        contextComponentCallback(this, 'onLoad', ...arguments);
    }

    /**
     * Handles the cancel action.
     *
     * @method
     * @action
     * @returns {Boolean} Indicates whether the cancel action was overridden.
     */
    @action onPressCancel() {
        return contextComponentCallback(this, 'onPressCancel', this.customer);
    }

    @action async viewOrder(order) {
        if (order.isFresh) {
            return this.acceptOrder(order);
        }

        if (order.isPreparing) {
            return this.markAsReady(order);
        }

        if (order.isPickupReady) {
            return this.markAsCompleted(order);
        }

        return this.transitionToRoute('orders.index.view', order);
    }

    @action async acceptOrder(order) {
        const activeStore = this.storefront.activeStore;

        await order.loadPayload();
        await order.loadCustomer();

        this.modalsManager.show('modals/incoming-order', {
            title: this.intl.t('storefront.component.widget.orders.accept-order'),
            acceptButtonText: this.intl.t('storefront.component.widget.orders.accept-order'),
            acceptButtonScheme: 'success',
            acceptButtonIcon: 'check',
            order,
            activeStore,
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
