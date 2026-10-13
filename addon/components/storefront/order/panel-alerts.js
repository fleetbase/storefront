import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { format, isValid, parseISO, differenceInMinutes } from 'date-fns';
import formatCurrency from '@fleetbase/ember-ui/utils/format-currency';

function parse(value) {
    const date = value ? parseISO(value) : null;
    return date && isValid(date) ? date : null;
}

/**
 * The 11a banners. The server raises the alerts (wrong address, driver not accepted, balance due,
 * late); a finished order gets its delivered banner here. Each banner carries its own actions.
 */
export default class StorefrontOrderPanelAlertsComponent extends Component {
    @service intl;
    @service fetch;
    @service abilities;
    @service notifications;
    @service hostRouter;
    @service storefrontOrderActions;

    get order() {
        return this.args.order;
    }

    get overview() {
        return this.args.overview ?? {};
    }

    get customerName() {
        return this.order?.customer?.name ?? this.intl.t('storefront.common.customer');
    }

    get firstName() {
        return String(this.customerName).split(' ')[0];
    }

    t(key, options) {
        return this.intl.t(`storefront.order.panel.alerts.${key}`, options);
    }

    get canUpdate() {
        return this.abilities.can('storefront update order');
    }

    get rows() {
        const rows = (this.overview.alerts ?? []).map((alert) => this.describe(alert)).filter(Boolean);
        const delivered = this.deliveredRow;

        return delivered ? [...rows, delivered] : rows;
    }

    describe(alert) {
        const at = parse(alert.at);
        const time = at ? format(at, 'HH:mm') : null;
        const edit = (focus, text, primary = true) => (this.canUpdate ? { text, primary, onClick: () => this.storefrontOrderActions.editOrder(this.order, { focus }) } : null);

        switch (alert.code) {
            case 'address_change_requested':
                return {
                    code: alert.code,
                    tone: 'warn',
                    icon: 'triangle-exclamation',
                    title: this.t('address-wrong', { name: this.firstName }),
                    detail: [alert.message ? `“${alert.message}”` : null, alert.address ? this.t('suggested', { address: alert.address }) : null, time].filter(Boolean).join(' · '),
                    actions: [
                        { text: this.t('reply'), onClick: () => this.storefrontOrderActions.panel.openChat('customer') },
                        this.canUpdate ? { text: this.t('dismiss'), onClick: () => this.dismissAddressChange() } : null,
                        edit('dropoff', this.t('update-address')),
                    ].filter(Boolean),
                };
            case 'balance_due': {
                const link = this.overview.payment?.payment_link;
                return {
                    code: alert.code,
                    tone: 'bad',
                    icon: 'credit-card',
                    title: this.t('balance-due', { amount: formatCurrency(alert.amount, this.overview.payment?.currency) }),
                    detail: link ? this.t('balance-link') : this.t('balance-collect'),
                    actions: link ? [{ text: this.t('copy-link'), primary: true, onClick: () => this.copy(link) }] : [],
                };
            }
            case 'late': {
                const primary = this.storefrontOrderActions.actionItemsFor(this.order, this.args.refresh).find((item) => item.id === 'perform-workflow-action');
                return {
                    code: alert.code,
                    tone: 'bad',
                    icon: 'clock',
                    title: this.t('late', { late: this.duration(alert.minutes) }),
                    detail: this.t('late-detail'),
                    actions: [
                        { text: this.t('tell', { name: this.firstName }), onClick: () => this.storefrontOrderActions.panel.openChat('customer', this.t('late-message')) },
                        primary && this.canUpdate ? { text: primary.text, primary: true, onClick: primary.fn } : null,
                    ].filter(Boolean),
                };
            }
            default:
                // Unavailable items are flagged on their line instead.
                return null;
        }
    }

    /** Delivered (or picked up): when, against the due time, and the proof captured. */
    get deliveredRow() {
        const at = parse(this.overview.delivery?.delivered_at);
        if (!at) {
            return null;
        }

        const due = parse(this.overview.due_at);
        const diff = due ? differenceInMinutes(at, due) : null;
        const timing = diff === null ? '' : diff <= 0 ? this.t('early', { minutes: Math.abs(diff) }) : this.t('late-by', { minutes: diff });
        const proofs = this.overview.delivery?.proofs ?? 0;

        return {
            code: 'delivered',
            tone: 'good',
            icon: 'check',
            title: `${this.order?.meta?.is_pickup ? this.t('picked-up', { time: format(at, 'HH:mm') }) : this.t('delivered', { time: format(at, 'HH:mm') })}${timing ? `, ${timing}` : ''}.`,
            detail: proofs ? this.t('proof-captured', { count: proofs }) : null,
            actions: proofs ? [{ text: this.t('view-proof'), onClick: () => this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index.details', this.order.public_id) }] : [],
        };
    }

    duration(minutes) {
        const t = (key, options) => this.intl.t(`storefront.order.overview.duration.${key}`, options);
        if (minutes >= 1440) {
            return t('days', { count: Math.floor(minutes / 1440) });
        }
        return minutes >= 60 ? t('hours', { hours: Math.floor(minutes / 60), minutes: minutes % 60 }) : t('minutes', { count: minutes });
    }

    async dismissAddressChange() {
        try {
            await this.fetch.delete(`orders/${this.order.public_id}/address-change-request`, {}, { namespace: 'storefront/int/v1' });
            this.args.refresh?.();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    async copy(value) {
        try {
            await navigator.clipboard.writeText(value);
            this.notifications.success(this.intl.t('storefront.order.alerts.link-copied'));
        } catch {
            this.notifications.info(value);
        }
    }
}
