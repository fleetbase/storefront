import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import formatCurrency from '@fleetbase/ember-ui/utils/format-currency';

const PRESENTATION = {
    address_change_requested: { icon: 'map-marker-alt', tone: 'warning' },
    driver_not_accepted: { icon: 'user-clock', tone: 'warning' },
    balance_due: { icon: 'credit-card', tone: 'warning' },
    item_unavailable: { icon: 'box-open', tone: 'danger' },
    late: { icon: 'clock', tone: 'danger' },
};

/**
 * The order's alerts as banners. The server decides what is wrong; each code maps to the
 * actions that fix it here.
 */
export default class StorefrontOrderAlertsComponent extends Component {
    @service intl;
    @service fetch;
    @service notifications;
    @service storefrontOrderActions;

    get rows() {
        const alerts = Array.isArray(this.args.alerts) ? this.args.alerts : [];

        return alerts.filter((alert) => PRESENTATION[alert.code]).map((alert) => ({ ...alert, ...PRESENTATION[alert.code], ...this.describe(alert) }));
    }

    describe(alert) {
        const t = (key, options) => this.intl.t(`storefront.order.alerts.${key}`, options);
        const edit = (focus, text, icon = 'pen') => ({ text, icon, permission: 'storefront update order', onClick: () => this.args.onEdit?.(focus) });

        switch (alert.code) {
            case 'address_change_requested':
                return {
                    title: t('address-change-requested'),
                    detail: [alert.message ? `“${alert.message}”` : null, alert.address ? t('suggested-address', { address: alert.address }) : null].filter(Boolean).join(' · ') || null,
                    actions: [
                        edit('dropoff', t('update-address'), 'map-marker-alt'),
                        { text: t('dismiss'), icon: 'times', permission: 'storefront update order', onClick: () => this.dismissAddressChange() },
                    ],
                };
            case 'driver_not_accepted':
                return {
                    title: t('driver-not-accepted'),
                    actions: [
                        {
                            text: t('reassign'),
                            icon: 'user-plus',
                            permission: 'storefront assign-driver-for order',
                            onClick: () => this.storefrontOrderActions.assignDriver(this.args.order, this.args.refresh),
                        },
                    ],
                };
            case 'balance_due': {
                const link = this.args.payment?.payment_link;
                return {
                    title: t('balance-due', { amount: formatCurrency(alert.amount, this.args.payment?.currency) }),
                    detail: link ? t('balance-link-sent') : t('balance-collect'),
                    actions: link ? [{ text: t('copy-link'), icon: 'copy', onClick: () => this.copy(link) }] : [],
                };
            }
            case 'item_unavailable':
                return {
                    title: t('item-unavailable', { items: (alert.items ?? []).join(', ') }),
                    detail: t('item-unavailable-detail'),
                    actions: [edit('items', t('substitute-or-remove'))],
                };
            case 'late':
                return {
                    title: t('late', { late: this.duration(alert.minutes) }),
                    actions: [edit('schedule', t('change-time'), 'clock')],
                };
            default:
                return {};
        }
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
            await this.fetch.delete(`orders/${this.args.order.public_id}/address-change-request`, {}, { namespace: 'storefront/int/v1' });
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
