import Service, { inject as service } from '@ember/service';
import { isArray } from '@ember/array';
import toBoolean from '@fleetbase/ember-core/utils/to-boolean';

export default class StorefrontOrderActionsService extends Service {
    @service intl;
    @service notifications;
    @service fetch;
    @service modalsManager;
    @service storefront;
    @service resourceContextPanel;
    @service('universe/menu-service') menuService;

    async viewOrder(order, options = {}) {
        const hydratedOrder = await this.fetch.get(
            `orders/${order.public_id ?? order.id}`,
            {
                storefront: this.storefront.getActiveStore('public_id'),
            },
            {
                namespace: 'storefront/int/v1',
                normalizeToEmberData: true,
                normalizeModelType: 'order',
            }
        );

        this.resourceContextPanel.open({
            id: `storefront-order:${hydratedOrder.id}`,
            resource: hydratedOrder,
            title: hydratedOrder.public_id,
            header: 'storefront/order/panel-header',
            tabs: this.tabsFor(hydratedOrder),
            actionButtons: this.actionButtonsFor(hydratedOrder, options.onChange),
            width: '560px',
            size: 'sm',
            dismissible: false,
            bodyClass: 'scrollable',
            headerClass: 'storefront-order-panel-header no-bottom-border',
            panelContentClass: 'storefront-order-panel-content',
        });

        return hydratedOrder;
    }

    tabsFor() {
        const registeredTabs = this.menuService.getMenuItems('storefront:component:order:details');

        return [
            {
                label: 'Overview',
                key: 'overview',
                icon: 'folder-open',
                component: 'storefront/order/details',
            },
            ...(isArray(registeredTabs)
                ? registeredTabs.map((tab) => ({
                      label: tab.label ?? tab.title,
                      key: tab.slug,
                      icon: tab.icon,
                      component: 'storefront/order/details/registered-tab',
                      class: tab.slug,
                  }))
                : []),
        ];
    }

    actionButtonsFor(order, callback) {
        return [
            {
                items: [
                    order.isFresh
                        ? {
                              text: 'Accept order',
                              icon: 'check',
                              fn: () => this.acceptOrder(order, callback),
                          }
                        : null,
                    order.isPreparing
                        ? {
                              text: 'Mark ready',
                              icon: 'bell-concierge',
                              fn: () => this.markAsReady(order, callback),
                          }
                        : null,
                    order.isPickupReady
                        ? {
                              text: 'Complete order',
                              icon: 'check',
                              fn: () => this.markAsCompleted(order, callback),
                          }
                        : null,
                    {
                        text: order.has_driver_assigned ? 'Change driver' : 'Assign driver',
                        icon: 'id-card',
                        disabled: order.isCanceled || order.status === 'order_canceled',
                        fn: () => this.assignDriver(order, callback),
                    },
                    {
                        separator: true,
                    },
                    {
                        text: 'Cancel order',
                        icon: 'ban',
                        class: 'text-danger',
                        disabled: order.isCanceled || order.status === 'order_canceled',
                        fn: () => this.cancelOrder(order, callback),
                    },
                ].filter(Boolean),
            },
        ];
    }

    cancelOrder(order, callback) {
        this.modalsManager.confirm({
            title: this.intl.t('fleet-ops.operations.orders.index.cancel-title'),
            body: this.intl.t('fleet-ops.operations.orders.index.cancel-body'),
            order,
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await this.fetch.patch('orders/cancel', { order: order.id }, { namespace: 'storefront/int/v1' });
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

    markAsPreparing(order, callback) {
        this.modalsManager.confirm({
            title: this.intl.t('storefront.component.widget.orders.mark-as-preparing-modal-title'),
            body: this.intl.t('storefront.component.widget.orders.mark-as-preparing-modal-body'),
            acceptButtonText: this.intl.t('storefront.component.widget.orders.mark-as-preparing-accept-button-text'),
            acceptButtonIcon: 'check',
            acceptButtonScheme: 'success',
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await this.fetch.post('orders/preparing', { order: order.id }, { namespace: 'storefront/int/v1' });
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
