import Service, { inject as service } from '@ember/service';
import { isArray } from '@ember/array';

export default class StorefrontOrderActionsService extends Service {
    @service intl;
    @service notifications;
    @service fetch;
    @service modalsManager;
    @service storefront;
    @service resourceContextPanel;
    @service storefrontOrderWorkflow;
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
                items: this.actionItemsFor(order, callback),
            },
        ];
    }

    actionItemsFor(order, callback) {
        const isTerminal = this.storefrontOrderWorkflow.isTerminal(order);
        const workflowItems = this.storefrontOrderWorkflow.primaryActionDescriptorsFor(order).map((descriptor) => ({
            text: descriptor.text,
            icon: descriptor.icon,
            type: descriptor.type,
            fn: () => this.performWorkflowAction(descriptor, order, callback),
        }));

        return [
            ...workflowItems,
            {
                text: this.storefrontOrderWorkflow.hasAssignedDriver(order) ? 'Unassign Driver' : 'Assign Driver',
                icon: this.storefrontOrderWorkflow.hasAssignedDriver(order) ? 'user-minus' : 'id-card',
                disabled: isTerminal,
                fn: () => (this.storefrontOrderWorkflow.hasAssignedDriver(order) ? this.unassignDriver(order, callback) : this.assignDriver(order, callback)),
            },
            {
                separator: true,
            },
            {
                text: 'Cancel order',
                icon: 'ban',
                class: 'text-danger',
                disabled: isTerminal,
                fn: () => this.cancelOrder(order, callback),
            },
        ].filter(Boolean);
    }

    performWorkflowAction(descriptor, order, callback) {
        switch (descriptor.action) {
            case 'accept':
                return this.acceptOrder(order, callback);

            case 'mark_ready':
                return this.markAsReady(order, callback);

            case 'mark_completed':
                return this.markAsCompleted(order, callback);

            case 'update_activity':
                return this.updateActivity(order, descriptor.activity, callback);
        }
    }

    statusFor(order) {
        return this.storefrontOrderWorkflow.statusFor(order);
    }

    isPickupOrder(order) {
        return this.storefrontOrderWorkflow.isPickupOrder(order);
    }

    isPickupReadyStatus(order) {
        const status = this.statusFor(order);

        return this.isPickupOrder(order) && ['ready', 'pickup_ready'].includes(status);
    }

    hasAssignedDriver(order) {
        return this.storefrontOrderWorkflow.hasAssignedDriver(order);
    }

    isDispatchedOrder(order) {
        return Boolean(order?.dispatched || this.statusFor(order) === 'dispatched');
    }

    isTerminalStatus(status) {
        return this.storefrontOrderWorkflow.isTerminal(status);
    }

    setOrderStatus(order, status) {
        if (!order || !status) {
            return;
        }

        if (typeof order.set === 'function') {
            order.set('status', status);
        } else {
            order.status = status;
        }

        if (status === 'dispatched') {
            this.setOrderProperty(order, 'dispatched', true);
        }
    }

    setOrderStateFromResponse(order, response, fallbackStatus = null, callback = null) {
        const responseOrder = response?.order && typeof response.order === 'object' ? response.order : null;

        if (responseOrder) {
            this.applyOrderProperties(order, responseOrder);
            this.refreshOrderActions(order, callback);

            if (typeof callback === 'function') {
                callback(order);
            }

            return order;
        }

        this.didMutateOrder(order, response?.status ?? fallbackStatus, callback);
        return order;
    }

    applyOrderProperties(order, properties = {}) {
        if (!order || !properties) {
            return;
        }

        Object.entries(properties).forEach(([key, value]) => {
            if (key === 'id') {
                return;
            }

            if (
                ['customer', 'payload', 'driver_assigned', 'order_config', 'tracking_number', 'tracking_statuses', 'transaction', 'purchase_rate', 'files', 'comments'].includes(key) &&
                value !== null
            ) {
                return;
            }

            this.setOrderProperty(order, key, value);
        });
    }

    setOrderProperty(order, property, value) {
        if (!order || !property) {
            return;
        }

        if (typeof order.set === 'function') {
            order.set(property, value);
        } else {
            order[property] = value;
        }
    }

    didDispatchOrder(order, status, callback) {
        const responseStatus = String(status ?? '').toLowerCase();
        const nextStatus = ['accepted', 'preparing', 'started'].includes(responseStatus) ? 'dispatched' : responseStatus || 'dispatched';

        this.setOrderProperty(order, 'dispatched', true);
        this.didMutateOrder(order, nextStatus, callback);
    }

    refreshOrderActions(order, callback) {
        const overlayId = `storefront-order:${order?.id}`;
        const overlay = this.resourceContextPanel.overlays?.find((overlay) => overlay.id === overlayId);

        if (overlay) {
            this.resourceContextPanel.update(overlayId, {
                resource: order,
                actionButtons: this.actionButtonsFor(order, callback),
            });
        }
    }

    didMutateOrder(order, status, callback) {
        this.setOrderStatus(order, status);
        this.refreshOrderActions(order, callback);

        if (typeof callback === 'function') {
            callback(order);
        }
    }

    cancelOrder(order, callback) {
        this.modalsManager.confirm({
            title: this.intl.t('storefront.component.widget.orders.cancel-order-modal-title'),
            body: this.intl.t('storefront.component.widget.orders.cancel-order-modal-body'),
            acceptButtonText: this.intl.t('storefront.component.widget.orders.cancel-order'),
            acceptButtonScheme: 'danger',
            order,
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    const response = await this.fetch.patch('orders/cancel', { order: order.id }, { namespace: 'storefront/int/v1' });
                    this.setOrderStateFromResponse(order, response, 'canceled', callback);
                    this.notifications.success(this.intl.t('storefront.component.widget.orders.cancel-order-success', { orderId: order.public_id }));
                    modal.done();
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
                    const driver = order.driver_assigned;
                    this.setOrderProperty(order, 'driver_assigned_uuid', driver?.id ?? driver?.uuid ?? null);
                    this.setOrderProperty(order, 'has_driver_assigned', Boolean(driver));
                    await order.save();
                    this.refreshOrderActions(order, callback);
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

    unassignDriver(order, callback) {
        const driverName = order?.driver_assigned?.name ?? order?.driver_name ?? 'this driver';

        this.modalsManager.confirm({
            title: `Unassign ${driverName}?`,
            body: 'The driver will no longer be assigned to this order.',
            acceptButtonText: 'Unassign Driver',
            acceptButtonScheme: 'danger',
            acceptButtonIcon: 'user-minus',
            order,
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    this.setOrderProperty(order, 'driver_assigned', null);
                    this.setOrderProperty(order, 'driver_assigned_uuid', null);
                    this.setOrderProperty(order, 'driver_name', null);
                    this.setOrderProperty(order, 'has_driver_assigned', false);
                    const response = await this.fetch.post('orders/unassign-driver', { order: order.id }, { namespace: 'storefront/int/v1' });
                    this.setOrderStateFromResponse(order, response, null, callback);
                    this.notifications.success('Driver unassigned from order.');
                    modal.done();
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
                    const response = await this.fetch.post('orders/accept', { order: order.id }, { namespace: 'storefront/int/v1' });
                    this.setOrderStateFromResponse(order, response, 'accepted', callback);
                    modal.done();
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
        if (this.storefrontOrderWorkflow.isPickupOrder(order)) {
            return this.modalsManager.confirm({
                title: this.intl.t('storefront.component.widget.orders.mark-as-ready-modal-pickup-title'),
                body: this.intl.t('storefront.component.widget.orders.mark-as-ready-modal-pickup-body'),
                acceptButtonText: this.intl.t('storefront.component.widget.orders.mark-as-ready-modal-pickup-accept-button-text'),
                acceptButtonIcon: 'check',
                acceptButtonScheme: 'success',
                confirm: async (modal) => {
                    modal.startLoading();

                    try {
                        const response = await this.fetch.post('orders/ready', { order: order.id }, { namespace: 'storefront/int/v1' });
                        this.setOrderStateFromResponse(order, response, 'pickup_ready', callback);
                        modal.done();
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

        if (!order.adhoc && this.hasAssignedDriver(order)) {
            return this.modalsManager.confirm({
                title: this.intl.t('storefront.component.widget.orders.mark-as-ready-modal-title'),
                body: 'Marking the order as ready will dispatch the order to the assigned driver.',
                acceptButtonText: this.intl.t('storefront.component.widget.orders.mark-as-ready-modal-accept-button-text'),
                acceptButtonIcon: 'paper-plane',
                acceptButtonScheme: 'success',
                confirm: async (modal) => {
                    modal.startLoading();
                    try {
                        const response = await this.fetch.post('orders/ready', { order: order.id }, { namespace: 'storefront/int/v1' });
                        this.setOrderStateFromResponse(order, response, 'preparing', callback);
                        modal.done();
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
                driver: order.driver_assigned ?? null,
                order,
                confirm: async (modal) => {
                    modal.startLoading();

                    try {
                        const driver = modal.getOption('driver');
                        this.setOrderProperty(order, 'driver_assigned', driver ?? null);
                        this.setOrderProperty(order, 'driver_assigned_uuid', driver?.id ?? driver?.uuid ?? null);
                        this.setOrderProperty(order, 'has_driver_assigned', Boolean(driver));
                        const response = await this.fetch.post(
                            'orders/ready',
                            { order: order.id, driver: modal.getOption('driver.id'), adhoc: modal.getOption('adhoc') },
                            { namespace: 'storefront/int/v1' }
                        );
                        this.setOrderStateFromResponse(order, response, this.storefrontOrderWorkflow.isPickupOrder(order) ? 'pickup_ready' : 'preparing', callback);
                        modal.done();
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
                    const response = await this.fetch.post('orders/ready', { order: order.id }, { namespace: 'storefront/int/v1' });
                    this.setOrderStateFromResponse(order, response, 'preparing', callback);
                    modal.done();
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
                    const response = await this.fetch.post('orders/preparing', { order: order.id }, { namespace: 'storefront/int/v1' });
                    this.setOrderStateFromResponse(order, response, 'preparing', callback);
                    modal.done();
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

    updateActivity(order, activity, callback) {
        if (!activity) {
            return;
        }

        this.modalsManager.confirm({
            title: 'Update Order Activity',
            body: `Update this order to ${activity._resolved_status ?? activity.status ?? activity.code}?`,
            acceptButtonText: 'Update Activity',
            acceptButtonIcon: 'signal',
            acceptButtonScheme: 'success',
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    const response = await this.fetch.patch(`orders/update-activity/${order.id}`, { activity }, { namespace: 'storefront/int/v1' });
                    this.setOrderStateFromResponse(order, response, activity.code, callback);
                    modal.done();
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
                    const response = await this.fetch.post('orders/completed', { order: order.id }, { namespace: 'storefront/int/v1' });
                    this.setOrderStateFromResponse(order, response, this.storefrontOrderWorkflow.isPickupOrder(order) ? 'picked_up' : 'completed', callback);
                    modal.done();
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
