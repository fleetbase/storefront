import Service from '@ember/service';
import toBoolean from '@fleetbase/ember-core/utils/to-boolean';

const DEFAULT_STOREFRONT_CONFIG_NAMESPACE = 'system:order-config:storefront';
const TERMINAL_STATUSES = ['canceled', 'cancel', 'order_canceled', 'completed', 'picked_up'];
const DEFAULT_STOREFRONT_QUICK_ACTIONS = {
    created: ['accept'],
    accepted: ['mark_ready'],
    pickup_ready: ['mark_picked_up'],
    ready: ['mark_picked_up'],
};

export default class StorefrontOrderWorkflowService extends Service {
    statusFor(order) {
        return String(order?.status ?? '').toLowerCase();
    }

    isPickupOrder(order) {
        return toBoolean(order?.meta?.is_pickup) === true;
    }

    /** A service booking (at the customer's address, or at the store when it is a pickup). */
    isBookingOrder(order) {
        return toBoolean(order?.meta?.is_booking) === true;
    }

    isDefaultStorefrontConfig(order) {
        const orderConfig = order?.order_config;

        if (orderConfig) {
            return orderConfig.namespace === DEFAULT_STOREFRONT_CONFIG_NAMESPACE || orderConfig.key === 'storefront';
        }

        return order?.type === 'storefront';
    }

    isTerminal(orderOrStatus) {
        const status = typeof orderOrStatus === 'string' ? orderOrStatus : this.statusFor(orderOrStatus);
        return TERMINAL_STATUSES.includes(status);
    }

    hasAssignedDriver(order) {
        return Boolean(order?.driver_assigned || order?.driver_assigned_uuid || order?.has_driver_assigned);
    }

    orderTypeLabel(order) {
        return order?.type ?? order?.order_config?.name ?? order?.order_config?.key;
    }

    nextActivityCodesFor(order) {
        const status = this.statusFor(order);
        const flow = order?.order_config?.flow;
        const activities = flow?.[status]?.activities;

        if (Array.isArray(activities)) {
            return activities;
        }

        return [];
    }

    nextActivitiesFor(order) {
        const flow = order?.order_config?.flow;

        return this.nextActivityCodesFor(order)
            .map((code) => flow?.[code] ?? { code, key: code, status: code })
            .filter(Boolean);
    }

    primaryActionDescriptorsFor(order) {
        if (this.isTerminal(order)) {
            return [];
        }

        if (this.isDefaultStorefrontConfig(order)) {
            return this.defaultStorefrontActionDescriptorsFor(order);
        }

        return this.customConfigActionDescriptorsFor(order);
    }

    defaultStorefrontActionDescriptorsFor(order) {
        const status = this.statusFor(order);
        const actionCodes = DEFAULT_STOREFRONT_QUICK_ACTIONS[status] ?? [];

        return actionCodes.map((action) => this.descriptorFor(action, order)).filter(Boolean);
    }

    customConfigActionDescriptorsFor(order) {
        const status = this.statusFor(order);

        if (status === 'created') {
            return [this.descriptorFor('accept', order)];
        }

        if (status === 'accepted') {
            // An at-store booking starts when the customer comes in; a home visit is dispatched to the provider.
            if (this.isBookingOrder(order) && this.isPickupOrder(order)) {
                const flow = order?.order_config?.flow ?? {};
                return [this.descriptorFor('update_activity', order, flow.in_progress ?? { code: 'in_progress', key: 'in_progress', status: 'Service in progress' })];
            }

            return [this.descriptorFor('mark_ready', order)];
        }

        return this.nextActivitiesFor(order)
            .map((activity) => {
                const code = String(activity.code ?? activity.key ?? '').toLowerCase();

                if (this.isPickupOrder(order) && ['pickup_ready', 'ready'].includes(code)) {
                    return this.descriptorFor('mark_ready', order);
                }

                if (['completed', 'picked_up'].includes(code)) {
                    if (this.isBookingOrder(order)) {
                        return this.descriptorFor('mark_completed', order);
                    }

                    return this.descriptorFor(this.isPickupOrder(order) ? 'mark_picked_up' : 'mark_completed', order);
                }

                return this.descriptorFor('update_activity', order, activity);
            })
            .filter(Boolean);
    }

    descriptorFor(action, order, activity = null) {
        switch (action) {
            case 'accept':
                return {
                    action,
                    text: this.isBookingOrder(order) ? 'Confirm booking' : 'Accept order',
                    icon: 'check',
                    type: 'success',
                };

            case 'mark_ready':
                if (this.isBookingOrder(order)) {
                    return { action, text: 'Dispatch provider', icon: 'paper-plane', type: 'magic' };
                }

                return {
                    action,
                    text: 'Mark as Ready',
                    icon: this.isPickupOrder(order) ? 'bell-concierge' : 'paper-plane',
                    type: this.isPickupOrder(order) ? 'success' : 'magic',
                };

            case 'mark_picked_up':
                return {
                    action: 'mark_completed',
                    text: 'Mark Picked Up',
                    icon: 'check',
                    type: 'success',
                };

            case 'mark_completed':
                return {
                    action,
                    text: this.isBookingOrder(order) ? 'Complete booking' : 'Complete order',
                    icon: 'check',
                    type: 'success',
                };

            case 'update_activity':
                return {
                    action,
                    activity,
                    text: activity?._resolved_status ?? activity?.status ?? activity?.code ?? 'Update activity',
                    icon: 'signal',
                    type: 'default',
                };

            default:
                return null;
        }
    }
}
