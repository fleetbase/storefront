import Service, { inject as service } from '@ember/service';
import { getOwner } from '@ember/application';
import { tracked } from '@glimmer/tracking';
import { isArray } from '@ember/array';
import formatCurrency from '@fleetbase/ember-ui/utils/format-currency';

class CancelDraft {
    @tracked reason = '';
    // refund | store_credit | none
    @tracked giveBack = 'none';
    @tracked amount = 0;
}

class RescheduleDraft {
    @tracked start = null;
    @tracked end = null;
    @tracked notifyCustomer = true;
    @tracked notifyProvider = true;
}

/**
 * State the order panel's header and body share: whether the messages drawer is open, on which
 * thread, with what draft, and the order's overview (payment, alerts, unread counts).
 */
class OrderPanelState {
    @tracked chatOpen = false;
    @tracked chatKind = 'customer';
    @tracked chatDraft = '';
    @tracked overview = null;
    @tracked flow = null;
    @tracked printingLabel = false;
    @tracked orderId = null;
    printLabel = null;
    refresh = null;

    openChat = (kind = 'customer', draft = '') => {
        this.chatKind = kind;
        this.chatDraft = draft ?? '';
        this.chatOpen = true;
    };

    closeChat = () => {
        this.chatOpen = false;
    };

    setOverview(orderId, overview) {
        if (this.orderId !== orderId) {
            this.chatOpen = false;
            this.chatDraft = '';
        }
        this.orderId = orderId;
        this.overview = overview;
    }

    get unread() {
        return this.overview?.chat?.unread ?? {};
    }

    get unreadTotal() {
        return this.overview?.chat?.unread_total ?? 0;
    }
}

export default class StorefrontOrderActionsService extends Service {
    /** The open order panel's shared state (see OrderPanelState). */
    panel = new OrderPanelState();

    @service intl;
    @service notifications;
    @service fetch;
    @service modalsManager;
    @service storefront;
    @service resourceContextPanel;
    @service storefrontOrderWorkflow;
    @service('universe/menu-service') menuService;

    /**
     * The router: the engine's host router inside Storefront, the console's own router when the
     * service is used from the console (the resource context panel), where engines' services are not.
     */
    get hostRouter() {
        const owner = getOwner(this);
        return owner.lookup('service:hostRouter') ?? owner.lookup('service:router');
    }

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
            onChange: options.onChange,
            actionButtons: this.actionButtonsFor(hydratedOrder, options.onChange),
            registry: 'storefront:order:details',
            width: '640px',
            size: 'sm',
            dismissible: false,
            bodyClass: 'scrollable',
            headerClass: 'storefront-order-panel-header no-bottom-border',
            panelContentClass: 'storefront-order-panel-content',
        });

        return hydratedOrder;
    }

    /** The order's own view, then tabs other extensions register on `storefront:component:order:details`. */
    tabsFor() {
        const registered = this.menuService.getMenuItems('storefront:component:order:details');

        return [
            { label: this.intl.t('storefront.order.panel.details-tab'), key: 'details', icon: 'receipt', component: 'storefront/order/overview' },
            ...(isArray(registered) ? registered : []).map((tab) => ({
                label: tab.label ?? tab.title,
                key: tab.slug,
                icon: tab.icon,
                component: 'storefront/order/details/registered-tab',
                class: tab.slug,
            })),
        ];
    }

    actionButtonsFor(order, callback) {
        const items = this.actionItemsFor(order, callback);
        const primary = items.find((item) => item.id === 'perform-workflow-action');
        const rest = items.filter((item) => item !== primary);
        const buttons = [];

        if (primary) {
            buttons.push({ text: primary.text, icon: primary.icon, type: primary.type ?? 'primary', size: 'sm', fn: primary.fn, permission: 'storefront update order' });
        }

        buttons.push({
            text: this.intl.t('common.edit'),
            icon: 'pencil',
            size: 'sm',
            permission: 'storefront update order',
            fn: () => this.editOrder(order),
        });

        buttons.push({ items: rest });

        return buttons;
    }

    /**
     * Opens the order's edit form; `focus` scrolls it to a section (dropoff, items, schedule, contact).
     */
    editOrder(order, { focus } = {}) {
        if (!order?.public_id) {
            return;
        }

        try {
            return this.hostRouter.transitionTo('console.storefront.orders.index.edit', order.public_id, { queryParams: { focus: focus ?? null } });
        } catch {
            return null;
        }
    }

    actionItemsFor(order, callback) {
        const isTerminal = this.storefrontOrderWorkflow.isTerminal(order);
        const workflowItems = this.storefrontOrderWorkflow.primaryActionDescriptorsFor(order).map((descriptor) => ({
            id: 'perform-workflow-action',
            text: descriptor.text,
            icon: descriptor.icon,
            type: descriptor.type,
            fn: () => this.performWorkflowAction(descriptor, order, callback),
        }));

        return [
            ...workflowItems,
            {
                text: this.storefrontOrderWorkflow.hasAssignedDriver(order)
                    ? this.intl.t('storefront.orders.index.view.unassign-driver')
                    : this.intl.t('storefront.orders.index.view.assign-driver'),
                icon: this.storefrontOrderWorkflow.hasAssignedDriver(order) ? 'user-minus' : 'id-card',
                disabled: isTerminal,
                fn: () => (this.storefrontOrderWorkflow.hasAssignedDriver(order) ? this.unassignDriver(order, callback) : this.assignDriver(order, callback)),
            },
            {
                separator: true,
            },
            {
                id: 'cancel-order',
                text: this.intl.t('storefront.orders.index.view.cancel-order'),
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

    /**
     * Cancel with a reason and give back what was paid: refunded to the gateway (in part where it
     * can refund part of a payment), given as store credit, or kept. The customer is told why.
     */
    async cancelOrder(order, callback) {
        let overview = null;
        try {
            overview = await this.fetch.get(`orders/${order.public_id}/overview`, {}, { namespace: 'storefront/int/v1' });
        } catch {
            // The dialog still cancels; it just cannot offer a refund.
        }

        const plan = overview?.cancel ?? null;
        const draft = new CancelDraft();
        if (plan?.refundable > 0) {
            draft.giveBack = plan.default ?? 'none';
            draft.amount = plan.refundable;
        }

        const confirmKey = { refund: 'confirm-refund', store_credit: 'confirm-credit' }[draft.giveBack] ?? 'confirm';

        this.modalsManager.show('modals/cancel-order', {
            title: this.intl.t('storefront.order.cancel.title'),
            acceptButtonText: this.intl.t(`storefront.order.cancel.${confirmKey}`, { amount: formatCurrency(draft.amount, plan?.currency) }),
            acceptButtonScheme: 'danger',
            acceptButtonIcon: 'ban',
            declineButtonText: this.intl.t('storefront.order.cancel.keep-order'),
            order,
            payment: overview?.payment ?? null,
            plan,
            draft,
            confirm: async (modal) => {
                modal.startLoading();

                const giveBack = plan?.refundable > 0 && draft.giveBack !== 'none';
                try {
                    const response = await this.fetch.patch(
                        'orders/cancel',
                        {
                            order: order.id,
                            reason: draft.reason?.trim() || null,
                            refund: giveBack,
                            ...(giveBack ? { settlement: draft.giveBack, amount: draft.amount } : {}),
                        },
                        { namespace: 'storefront/int/v1' }
                    );
                    this.setOrderStateFromResponse(order, response, 'canceled', callback);
                    this.notifications.success(this.intl.t('storefront.order.cancel.success', { orderId: order.public_id }));
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

    /**
     * Show the balance's payment link: copy it, open it, or send it to the customer in the order
     * chat. The order is marked paid by itself once the customer pays.
     */
    showPaymentLink(order, payment = {}) {
        const link = payment.payment_link;
        if (!link) {
            return;
        }

        const name = String(order?.customer?.name ?? this.intl.t('storefront.common.customer')).split(' ')[0];
        const amount = payment.balance_due ?? 0;

        this.modalsManager.show('modals/balance-payment-link', {
            title: this.intl.t('storefront.order.payment-link.title'),
            acceptButtonText: this.intl.t('storefront.order.payment-link.send', { name }),
            acceptButtonIcon: 'paper-plane',
            declineButtonText: this.intl.t('storefront.order.payment-link.close'),
            link,
            name,
            amount,
            currency: payment.currency,
            gateway: payment.gateway,
            confirm: (modal) => {
                modal.done();
                this.panel.openChat('customer', this.intl.t('storefront.order.panel.alerts.link-message', { amount: formatCurrency(amount, payment.currency), link }));
            },
        });
    }

    /**
     * Move a booking to another free time of its service: the week of free times, the customer
     * and the provider told. The time is changed through the order edit (which checks the time
     * is still free).
     */
    rescheduleBooking(order, callback) {
        const entities = order?.payload?.entities?.toArray?.() ?? Array.from(order?.payload?.entities ?? []);
        const line = entities.find((entity) => entity.meta?.scheduled_at) ?? entities.find((entity) => entity.meta?.product_id);
        const productId = line?.meta?.product_id;
        if (!productId) {
            return this.editOrder(order, { focus: 'schedule' });
        }

        const booking = order.meta?.booking ?? {};
        const firstName = (name) => String(name ?? '').split(' ')[0];
        const draft = new RescheduleDraft();
        const provider = order.driver_assigned;

        this.modalsManager.show('modals/reschedule-booking', {
            title: this.intl.t('storefront.order.reschedule.title'),
            acceptButtonText: this.intl.t('storefront.order.reschedule.move'),
            acceptButtonIcon: 'calendar-check',
            acceptButtonDisabled: true,
            declineButtonText: this.intl.t('storefront.order.reschedule.keep'),
            size: 'lg',
            order,
            product: { id: productId, name: line.name },
            current: booking.start ?? order.meta?.booking_at ?? null,
            currentEnd: booking.end ?? null,
            customerName: firstName(order.customer?.name) || this.intl.t('storefront.order.edit.the-customer'),
            providerName: provider ? firstName(provider.name) || this.intl.t('storefront.order.edit.the-driver') : null,
            draft,
            confirm: async (modal) => {
                if (!draft.start) {
                    return;
                }
                modal.startLoading();

                try {
                    await this.fetch.patch(
                        `orders/${order.public_id}/edit`,
                        { scheduled_at: draft.start, notify_customer: draft.notifyCustomer, notify_driver: Boolean(provider) && draft.notifyProvider },
                        { namespace: 'storefront/int/v1' }
                    );
                    await order.reload();
                    this.notifications.success(this.intl.t('storefront.order.reschedule.moved', { id: order.public_id }));
                    modal.done();
                    (this.panel.refresh ?? callback)?.(order);
                } catch (error) {
                    this.notifications.serverError(error);
                } finally {
                    modal.stopLoading();
                }
            },
        });
    }

    /**
     * Assign a driver to an order without the dialog (a booking's provider is its driver), the
     * same way the Assign driver dialog does.
     */
    async setDriver(order, driver) {
        this.setOrderProperty(order, 'driver_assigned', driver);
        this.setOrderProperty(order, 'driver_assigned_uuid', driver?.id ?? driver?.uuid ?? null);
        this.setOrderProperty(order, 'has_driver_assigned', Boolean(driver));
        await order.save();

        return order;
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
            modalClass: 'scrollable-height-dialog storefront-incoming-order-modal',
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

    /**
     * Move the order to a step of its own flow (`option` from the flow endpoint's `next` or
     * `completion`). The server takes the step from the order's config; proof the step asks for
     * cannot be captured from the console, so it is bypassed, and the dialog says so.
     */
    moveToActivity(order, option, callback) {
        if (!option?.code) {
            return;
        }

        const t = (key, options) => this.intl.t(`storefront.order.panel.step-dialog.${key}`, options);
        const isPickup = this.storefrontOrderWorkflow.isPickupOrder(order);
        const body = [option.complete ? t(isPickup ? 'complete-pickup-body' : 'complete-body') : t('move-body', { step: option.label }), option.require_pod ? t('no-proof') : null]
            .filter(Boolean)
            .join(' ');

        this.modalsManager.confirm({
            title: option.complete ? t(isPickup ? 'complete-pickup-title' : 'complete-title') : t('move-title', { step: option.label }),
            body,
            acceptButtonText: option.complete ? t(isPickup ? 'complete-pickup' : 'complete') : t('move', { step: option.label }),
            acceptButtonIcon: option.complete ? 'check' : 'forward',
            acceptButtonScheme: option.complete ? 'success' : 'primary',
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await this.fetch.patch(
                        `orders/update-activity/${order.public_id ?? order.id}`,
                        { activity: { code: option.code }, bypass_proof: true },
                        { namespace: 'storefront/int/v1' }
                    );
                    order.set?.('status', option.code);
                    this.notifications.success(t('moved', { step: option.label }));
                    modal.done();
                    callback?.(order);
                } catch (error) {
                    this.notifications.serverError(error);
                } finally {
                    modal.stopLoading();
                }
            },
        });
    }

    updateActivity(order, activity, callback) {
        if (!activity) {
            return;
        }

        this.modalsManager.confirm({
            title: this.intl.t('storefront.order.update-activity'),
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
