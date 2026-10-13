import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { getOwner } from '@ember/application';
import { action } from '@ember/object';
import { format, isValid, parseISO } from 'date-fns';

const DRIVER_STATUS = {
    dispatched: 'heading-to-store',
    driver_enroute_to_store: 'heading-to-store',
    started: 'heading-to-store',
    driver_picked_up: 'picked-up',
    driver_enroute: 'on-the-way',
    enroute: 'on-the-way',
    arrived: 'arrived',
    completed: 'delivered',
};

/**
 * The 11a people cards: the customer (their history with the store and latest message) and the
 * driver (vehicle, online, what they are doing), with call and message on each.
 */
export default class StorefrontOrderPanelPeopleComponent extends Component {
    @service intl;
    @service abilities;
    @service storefrontOrderActions;

    /**
     * The router: the engine's host router inside Storefront, the console's own router when the
     * panel opens from the console (the resource context panel), where engines' services are not.
     */
    get hostRouter() {
        const owner = getOwner(this);
        return owner.lookup('service:hostRouter') ?? owner.lookup('service:router');
    }

    get order() {
        return this.args.order;
    }

    get customer() {
        return this.order?.customer;
    }

    get driver() {
        return this.order?.driver_assigned;
    }

    get isPickup() {
        return Boolean(this.order?.meta?.is_pickup);
    }

    /** A service booking: the driver is the provider who does the job. */
    get isBooking() {
        return Boolean(this.order?.meta?.is_booking);
    }

    get driverLabel() {
        return this.isBooking ? this.intl.t('storefront.order.panel.provider') : this.intl.t('storefront.order.panel.driver');
    }

    get customerPhoto() {
        const url = this.customer?.photo_url;
        return url && !String(url).includes('default') ? url : null;
    }

    get customerFirstName() {
        return String(this.customer?.name ?? '').split(' ')[0];
    }

    get driverFirstName() {
        return String(this.driver?.name ?? '').split(' ')[0];
    }

    get customerHistory() {
        const summary = this.args.overview?.customer_summary;
        if (!summary) {
            return null;
        }

        const since = summary.since ? parseISO(summary.since) : null;
        const orders = this.intl.t('storefront.order.panel.orders-count', { count: summary.orders_count ?? 0 });

        return since && isValid(since) ? `${orders} · ${this.intl.t('storefront.order.panel.since', { date: format(since, 'MMM yyyy') })}` : orders;
    }

    get lastMessage() {
        const message = this.args.overview?.customer_summary?.last_message;
        if (!message?.content) {
            return null;
        }

        const at = message.at ? parseISO(message.at) : null;
        return { content: message.content, time: at && isValid(at) ? format(at, 'HH:mm') : '' };
    }

    get driverStatus() {
        const key = DRIVER_STATUS[this.order?.status] ?? 'assigned';
        return this.intl.t(`storefront.order.panel.driver-status.${key}`);
    }

    get canAssign() {
        return this.abilities.can('storefront assign-driver-for order') && !['completed', 'canceled', 'picked_up'].includes(this.order?.status);
    }

    initials = (name) =>
        String(name ?? '?')
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((part) => part[0].toUpperCase())
            .join('');

    @action call(phone) {
        window.location.href = `tel:${phone}`;
    }

    @action openChat(kind) {
        this.storefrontOrderActions.panel.openChat(kind);
    }

    @action openCustomer() {
        return this.hostRouter.transitionTo('console.storefront.customers.index.view', this.customer.public_id);
    }

    @action openDriver() {
        return this.hostRouter.transitionTo('console.fleet-ops.management.drivers.index.details', this.driver.public_id);
    }

    @action track() {
        return this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index.details', this.order.public_id);
    }

    @action assign() {
        return this.storefrontOrderActions.assignDriver(this.order, this.args.refresh);
    }
}
