import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { getOwner } from '@ember/application';
import { action } from '@ember/object';
import { formatDistanceToNowStrict, isValid } from 'date-fns';

/**
 * The order panel's header, as the 11a design draws it. The primary flow action lives in the
 * next-step bar under it; the More menu holds the rest of the order's actions.
 */
export default class StorefrontOrderPanelHeaderComponent extends Component {
    @service intl;
    @service abilities;
    @service notifications;
    @service fetch;
    @service storefrontOrderActions;

    /**
     * The router: the engine's host router inside Storefront, the console's own router when the
     * panel opens from the console (the resource context panel), where engines' services are not.
     */
    /** A time on the order's store clock (date-fns pattern), not the browser's. */
    storeTime(value, pattern) {
        return this.storefrontOrderActions.formatForOrder(this.args.order ?? this.order, value, pattern);
    }

    get hostRouter() {
        const owner = getOwner(this);
        return owner.lookup('service:hostRouter') ?? owner.lookup('service:router');
    }

    get panel() {
        return this.storefrontOrderActions.panel;
    }

    get order() {
        return this.args.resource ?? this.args.order;
    }

    get trackingNumber() {
        return this.order?.tracking_number?.tracking_number ?? this.order?.tracking ?? this.order?.public_id;
    }

    get fulfillmentLabel() {
        const meta = this.order?.meta ?? {};
        if (meta.is_booking) {
            return this.intl.t('storefront.order.panel.booking');
        }

        return meta.is_pickup ? this.intl.t('storefront.common.pickup') : this.intl.t('storefront.networks.orders.delivery');
    }

    get sourceLabel() {
        return this.panel.overview?.source === 'console' ? this.intl.t('storefront.order.panel.console-order') : this.intl.t('storefront.order.panel.app-order');
    }

    get storeName() {
        const meta = this.order?.meta ?? {};
        return meta.storefront_network && meta.storefront ? meta.storefront : (meta.storefront ?? meta.storefront_network);
    }

    get placedLine() {
        const created = this.order?.created_at ? new Date(this.order.created_at) : null;
        if (!created || !isValid(created)) {
            return null;
        }

        return this.intl.t('storefront.order.panel.placed', { time: this.storeTime(created, 'd MMM HH:mm'), ago: formatDistanceToNowStrict(created, { addSuffix: true }) });
    }

    get canEdit() {
        return this.abilities.can('storefront update order') && this.panel.overview?.edit?.stage !== 'closed';
    }

    /** Everything but the flow's next step (in the next-step bar) and Edit (its own button). */
    get moreItems() {
        const t = (key) => this.intl.t(`storefront.order.panel.${key}`);
        const rest = this.storefrontOrderActions.actionItemsFor(this.order, this.args.refresh).filter((item) => item.id !== 'perform-workflow-action' && !item.separator);
        const links = [
            { id: 'print-label', text: t('print-label'), icon: 'print', fn: () => this.panel.printLabel?.() },
            this.panel.overview?.tracking_url
                ? { id: 'tracking-page', text: t('tracking-page'), icon: 'location-arrow', fn: () => window.open(this.panel.overview.tracking_url, '_blank', 'noopener') }
                : null,
            {
                id: 'open-fleet-ops',
                text: t('open-in-fleet-ops'),
                icon: 'external-link-alt',
                fn: () => this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index.details', this.order.public_id),
            },
        ].filter(Boolean);

        return [...links, ...(rest.length ? [{ separator: true }, ...rest] : [])];
    }

    @action printLabel() {
        return this.panel.printLabel?.();
    }

    @action runMore(item, dd) {
        dd?.actions?.close?.();
        return item.fn?.();
    }

    @action openMessages() {
        const unread = this.panel.unread ?? {};
        const kind = unread.customer ? 'customer' : unread.dispatch ? 'dispatch' : unread.group ? 'group' : this.panel.chatKind;
        this.panel.openChat(kind);
    }

    @action edit() {
        return this.storefrontOrderActions.editOrder(this.order);
    }

    /** A booking not yet under way can move to another free slot (the edit form, at its time). */
    get isBooking() {
        return Boolean(this.order?.meta?.is_booking);
    }

    get canReschedule() {
        return this.storefrontOrderActions.canReschedule(this.order, this.panel.overview);
    }

    @action reschedule() {
        return this.storefrontOrderActions.rescheduleBooking(this.order, this.args.refresh);
    }

    @action async copyTracking() {
        try {
            await navigator.clipboard.writeText(this.trackingNumber);
            this.notifications.success(this.intl.t('storefront.order.panel.copied', { value: this.trackingNumber }));
        } catch {
            this.notifications.info(this.trackingNumber);
        }
    }
}
