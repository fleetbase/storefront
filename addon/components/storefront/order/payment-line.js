import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import formatCurrency from '@fleetbase/ember-ui/utils/format-currency';

const PRESENTATION = {
    paid: { icon: 'circle-check', tone: 'success' },
    to_collect: { icon: 'money-bill', tone: 'info' },
    unpaid: { icon: 'circle-exclamation', tone: 'warning' },
    balance_due: { icon: 'circle-exclamation', tone: 'warning' },
    partially_refunded: { icon: 'rotate-left', tone: 'info' },
    refunded: { icon: 'rotate-left', tone: 'default' },
};

/**
 * The order's payment in one line, from the payment summary the server keeps.
 */
export default class StorefrontOrderPaymentLineComponent extends Component {
    @service intl;

    get payment() {
        return this.args.payment;
    }

    get presentation() {
        return PRESENTATION[this.payment?.state] ?? PRESENTATION.paid;
    }

    get icon() {
        return this.presentation.icon;
    }

    get tone() {
        return this.presentation.tone;
    }

    money(amount) {
        return formatCurrency(amount ?? 0, this.payment?.currency ?? this.args.order?.meta?.currency);
    }

    get text() {
        const payment = this.payment;
        if (!payment?.state) {
            return null;
        }

        const t = (key, options) => this.intl.t(`storefront.order.payment.${key}`, options);
        const method = payment.method_label;

        switch (payment.state) {
            case 'to_collect':
                return t('to-collect', { amount: this.money(payment.cod_amount || payment.total) });
            case 'unpaid':
                return t('unpaid', { amount: this.money(payment.total) });
            case 'balance_due':
                return t('balance-due', { method, paid: this.money(payment.amount_paid), amount: this.money(payment.balance_due) });
            case 'refunded':
                return t('refunded', { amount: this.money(payment.amount_refunded), method });
            case 'partially_refunded':
                return payment.amount_credited > 0 && !payment.amount_refunded
                    ? t('credited', { method, paid: this.money(payment.amount_paid), amount: this.money(payment.amount_credited) })
                    : t('partially-refunded', { method, paid: this.money(payment.amount_paid), amount: this.money(payment.amount_refunded) });
            default:
                return t('paid', { method, amount: this.money(payment.amount_paid) });
        }
    }
}
