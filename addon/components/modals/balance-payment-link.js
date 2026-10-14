import Component from '@glimmer/component';
import formatCurrency from '@fleetbase/ember-ui/utils/format-currency';

/**
 * The payment link for a balance due: the link to copy, open or send, and what happens when the
 * customer pays it (the order is marked paid on its own).
 */
export default class ModalsBalancePaymentLinkComponent extends Component {
    get options() {
        return this.args.options ?? {};
    }

    get link() {
        return this.options.link;
    }

    get gateway() {
        return this.options.gateway;
    }

    get gatewayLabel() {
        return { qpay: 'QPay', stripe: 'Stripe' }[this.gateway] ?? null;
    }

    get name() {
        return this.options.name;
    }

    get amount() {
        return formatCurrency(this.options.amount ?? 0, this.options.currency);
    }
}
