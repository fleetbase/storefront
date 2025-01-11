import Service, { inject as service } from '@ember/service';
import toBoolean from '@fleetbase/ember-core/utils/to-boolean';

export default class OrderActionsService extends Service {
    @service intl;
    @service notifications;
    @service fetch;
    @service store;
    @service modalsManager;
    @service storefront;

    cancelOrder(order, callback) {
        this.modalsManager.confirm({
            title: this.intl.t('fleet-ops.operations.orders.index.cancel-title'),
            body: this.intl.t('fleet-ops.operations.orders.index.cancel-body'),
            order,
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await this.fetch.patch('orders/cancel', { order: order.id });
                    order.set('status', 'canceled');
                    this.notifications.success('Order canceled.');
                    modal.done();
                    if (typeof callback === 'function') {
                        callback(order);
                    }
                } catch (error) {
                    this.notifications.serverError(error);
                } finally {
                    modal.stopLoading();
                }
            },
            decline: async (modal) => {
                if (typeof callback === 'function') {
                    callback(order);
                }
                modal.done();
            },
        });
    }

    async assignDriver(order, callback) {
        await order.loadDriver();

        this.modalsManager.show('modals/assign-driver', {
            title: this.intl.t('storefront.component.widget.orders.assign-driver-modal-title'),
            acceptButtonText: this.intl.t('storefront.component.widget.orders.assign-driver-modal-accept-button-text'),
            acceptButtonScheme: 'success',
            acceptButtonIcon: 'check',
            driver: order.driver_assigned,
            order,
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await order.save();
                    this.notifications.success('Driver assigned to order.');
                    modal.done();
                    if (typeof callback === 'function') {
                        callback(order);
                    }
                } catch (err) {
                    this.notifications.serverError(err);
                } finally {
                    modal.stopLoading();
                }
            },
            decline: async (modal) => {
                if (typeof callback === 'function') {
                    callback(order);
                }
                modal.done();
            },
        });
    }

    async acceptOrder(order, callback) {
        const store = this.storefront.activeStore;

        await order.loadPayload();
        await order.loadCustomer();

        this.modalsManager.show('modals/incoming-order', {
            title: this.intl.t('storefront.component.widget.orders.accept-order'),
            acceptButtonText: this.intl.t('storefront.component.widget.orders.accept-order'),
            acceptButtonScheme: 'success',
            acceptButtonIcon: 'check',
            modalClass: 'scrollable-height-dialog',
            order,
            store,
            assignDriver: async () => {
                await this.modalsManager.done();
                this.assignDriver(order, (order) => {
                    this.acceptOrder(order);
                });
            },
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await this.fetch.post('orders/accept', { order: order.id }, { namespace: 'storefront/int/v1' });
                    modal.done();
                    if (typeof callback === 'function') {
                        callback(order);
                    }
                } catch (err) {
                    this.notifications.serverError(err);
                } finally {
                    modal.stopLoading();
                }
            },
            decline: async (modal) => {
                if (typeof callback === 'function') {
                    callback(order);
                }
                modal.done();
            },
        });
    }

    markAsReady(order, callback) {
        // for pickup orders
        if (order.meta && toBoolean(order.meta.is_pickup) === true) {
            return this.modalsManager.confirm({
                title: this.intl.t('storefront.component.widget.orders.mark-as-ready-modal-pickup-title'),
                body: this.intl.t('storefront.component.widget.orders.mark-as-ready-modal-pickup-body'),
                acceptButtonText: this.intl.t('storefront.component.widget.orders.mark-as-ready-modal-pickup-accept-button-text'),
                acceptButtonIcon: 'check',
                acceptButtonScheme: 'success',
                confirm: async (modal) => {
                    modal.startLoading();

                    try {
                        await this.fetch.post('orders/ready', { order: order.id }, { namespace: 'storefront/int/v1' });
                        modal.done();
                        if (typeof callback === 'function') {
                            callback(order);
                        }
                    } catch (err) {
                        this.notifications.serverError(err);
                    } finally {
                        modal.stopLoading();
                    }
                },
                decline: async (modal) => {
                    if (typeof callback === 'function') {
                        callback(order);
                    }
                    modal.done();
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
                confirm: async (modal) => {
                    modal.startLoading();

                    try {
                        await this.fetch.post('orders/ready', { order: order.id, driver: modal.getOption('driver.id'), adhoc: modal.getOption('adhoc') }, { namespace: 'storefront/int/v1' });
                        modal.done();
                        if (typeof callback === 'function') {
                            callback(order);
                        }
                    } catch (err) {
                        this.notifications.serverError(err);
                    } finally {
                        modal.stopLoading();
                    }
                },
                decline: async (modal) => {
                    if (typeof callback === 'function') {
                        callback(order);
                    }
                    modal.done();
                },
            });
        }

        this.modalsManager.confirm({
            title: this.intl.t('storefront.component.widget.orders.mark-as-ready-modal-title'),
            body: this.intl.t('storefront.component.widget.orders.mark-as-ready-modal-body'),
            acceptButtonText: this.intl.t('storefront.component.widget.orders.mark-as-ready-modal-accept-button-text'),
            acceptButtonIcon: 'check',
            acceptButtonScheme: 'success',
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    await this.fetch.post('orders/ready', { order: order.id }, { namespace: 'storefront/int/v1' });
                    modal.done();
                    if (typeof callback === 'function') {
                        callback(order);
                    }
                } catch (err) {
                    this.notifications.serverError(err);
                } finally {
                    modal.stopLoading();
                }
            },
            decline: async (modal) => {
                if (typeof callback === 'function') {
                    callback(order);
                }
                modal.done();
            },
        });
    }

    markAsCompleted(order, callback) {
        this.modalsManager.confirm({
            title: this.intl.t('storefront.component.widget.orders.mark-as-completed-modal-title'),
            body: this.intl.t('storefront.component.widget.orders.mark-as-completed-modal-body'),
            acceptButtonText: this.intl.t('storefront.component.widget.orders.mark-as-completed-accept-button-text'),
            acceptButtonIcon: 'check',
            acceptButtonScheme: 'success',
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await this.fetch.post('orders/completed', { order: order.id }, { namespace: 'storefront/int/v1' });
                    modal.done();
                    if (typeof callback === 'function') {
                        callback(order);
                    }
                } catch (err) {
                    this.notifications.serverError(err);
                } finally {
                    modal.stopLoading();
                }
            },
            decline: async (modal) => {
                if (typeof callback === 'function') {
                    callback(order);
                }
                modal.done();
            },
        });
    }
}
