import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import formatCurrency from '@fleetbase/ember-ui/utils/format-currency';

/**
 * Why the order is canceled, and whether what was paid goes back (refund, or store credit where
 * the gateway cannot refund it).
 */
export default class ModalsCancelOrderComponent extends Component {
    @service intl;

    get draft() {
        return this.args.options?.draft;
    }

    get payment() {
        return this.args.options?.payment;
    }

    get remaining() {
        const payment = this.payment ?? {};

        return Math.max(0, (payment.amount_paid ?? 0) - (payment.amount_refunded ?? 0) - (payment.amount_credited ?? 0));
    }

    get paid() {
        return this.remaining > 0;
    }

    get refundLabel() {
        const amount = formatCurrency(this.remaining, this.payment?.currency);
        const method = this.payment?.method_label ?? '';

        // QPay refunds whole payments only; it can refund here because everything goes back.
        return this.intl.t('storefront.order.cancel.refund', { amount, method });
    }

    @action toggleRefund(event) {
        this.draft.refund = event.target.checked;
    }
}
