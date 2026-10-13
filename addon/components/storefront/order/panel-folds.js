import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';
import { format, isValid, parseISO } from 'date-fns';
import formatCurrency from '@fleetbase/ember-ui/utils/format-currency';

/**
 * The 11a folded rows under the notes.
 */
export default class StorefrontOrderPanelFoldsComponent extends Component {
    @service fetch;
    @service intl;
    @service storefrontOrderActions;
    @tracked siblings = [];

    constructor() {
        super(...arguments);
        this.loadSiblings.perform();
    }

    get payment() {
        return this.args.overview?.payment ?? {};
    }

    get reference() {
        return this.args.overview?.payment_reference ?? {};
    }

    get paymentSummary() {
        return [
            this.payment.method_label,
            this.reference.invoice ? this.intl.t('storefront.order.panel.invoice-short', { id: String(this.reference.invoice).slice(0, 8) }) : null,
            this.reference.transaction,
        ]
            .filter(Boolean)
            .join(' · ');
    }

    /** The payment as label/value pairs for the grid; references can be copied. */
    get paymentFacts() {
        const payment = this.payment;
        const reference = this.reference;
        const t = (key) => this.intl.t(`storefront.order.panel.${key}`);
        const money = (amount) => formatCurrency(amount ?? 0, payment.currency);
        const paidAt = reference.paid_at ? parseISO(reference.paid_at) : null;

        return [
            { label: t('method'), value: payment.method_label ?? '—' },
            { label: t('payment-state'), value: payment.state ? this.intl.t(`storefront.order.panel.payment-states.${payment.state}`) : '—' },
            { label: t('paid'), value: money(payment.amount_paid) },
            { label: t('paid-at'), value: paidAt && isValid(paidAt) ? format(paidAt, 'd MMM HH:mm') : '—' },
            reference.transaction ? { label: t('transaction'), value: reference.transaction, mono: true, copy: true } : null,
            reference.reference ? { label: t('gateway-reference'), value: reference.reference, mono: true, copy: true } : null,
            reference.invoice ? { label: t('invoice'), value: reference.invoice, mono: true, copy: true } : null,
            payment.amount_refunded ? { label: t('refunded'), value: money(payment.amount_refunded) } : null,
            payment.amount_credited ? { label: t('credited'), value: money(payment.amount_credited) } : null,
            payment.store_credit_code ? { label: t('credit-code'), value: payment.store_credit_code, mono: true, copy: true } : null,
            payment.balance_due ? { label: t('balance'), value: money(payment.balance_due) } : null,
            payment.cod_amount ? { label: t('cash-to-collect'), value: money(payment.cod_amount) } : null,
        ].filter(Boolean);
    }

    get adjustments() {
        return (this.payment.adjustments ?? []).map((adjustment) => {
            const at = adjustment.at ? parseISO(adjustment.at) : null;
            return {
                text:
                    this.intl.t(`storefront.order.panel.activity.payment-${adjustment.type}`, { amount: formatCurrency(Math.abs(adjustment.amount ?? 0), this.payment.currency) }) +
                    (adjustment.reference ? ` · ${adjustment.reference}` : ''),
                time: at && isValid(at) ? format(at, 'd MMM HH:mm') : '',
            };
        });
    }

    get checkoutSummary() {
        if (!this.siblings.length) {
            return this.intl.t('storefront.order.no-other-orders');
        }
        const stores = [...new Set(this.siblings.map((sibling) => sibling.meta?.storefront).filter(Boolean))];
        return `${this.intl.t('storefront.order.panel.other-stores', { count: this.siblings.length, checkout: this.args.order.meta.checkout_id })}${stores.length ? ` · ${stores.join(', ')}` : ''}`;
    }

    @task *loadSiblings() {
        const checkoutId = this.args.order?.meta?.checkout_id;
        if (!checkoutId) {
            return;
        }
        try {
            const orders = yield this.fetch.get('orders', { checkout: checkoutId, limit: 25 }, { namespace: 'storefront/int/v1', normalizeToEmberData: true, normalizeModelType: 'order' });
            const list = orders?.toArray?.() ?? Array.from(orders ?? []);
            this.siblings = list.filter((order) => order.meta?.checkout_id === checkoutId && order.id !== this.args.order.id);
        } catch {
            this.siblings = [];
        }
    }

    get detailsSummary() {
        const files = this.args.order?.files?.length ?? 0;
        return this.intl.t('storefront.order.panel.details-summary-count', { files });
    }
}
