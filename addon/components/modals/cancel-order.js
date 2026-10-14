import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import formatCurrency from '@fleetbase/ember-ui/utils/format-currency';

const REASONS = ['customer-asked', 'out-of-stock', 'store-closed', 'cannot-deliver', 'payment-problem'];

/**
 * Cancel an order in two steps: why (a common reason or the store's own words, which the
 * customer is told), and what happens to what was paid: refunded to the gateway (part of it
 * where the gateway refunds part of a payment), given as store credit, or kept.
 */
export default class ModalsCancelOrderComponent extends Component {
    @service intl;
    @service modalsManager;

    get draft() {
        return this.args.options?.draft;
    }

    get plan() {
        return this.args.options?.plan ?? null;
    }

    get payment() {
        return this.args.options?.payment ?? null;
    }

    get refundable() {
        return this.plan?.refundable ?? 0;
    }

    get reasons() {
        return REASONS.map((key) => this.intl.t(`storefront.order.cancel.reasons.${key}`));
    }

    money = (amount) => formatCurrency(amount ?? 0, this.plan?.currency ?? this.payment?.currency);

    get paidLine() {
        const payment = this.payment ?? {};
        const already = (payment.amount_refunded ?? 0) + (payment.amount_credited ?? 0);
        const paid = this.intl.t('storefront.order.cancel.paid-line', { amount: this.money(payment.amount_paid), method: this.plan?.method_label ?? '' });

        return already > 0 ? `${paid} · ${this.intl.t('storefront.order.cancel.already-back', { amount: this.money(already) })}` : paid;
    }

    get nothingPaidLine() {
        return this.payment?.state === 'to_collect' ? this.intl.t('storefront.order.cancel.nothing-collected') : this.intl.t('storefront.order.cancel.nothing-paid');
    }

    get giveBackOptions() {
        const options = (this.plan?.options ?? []).map((id) => ({
            id,
            label: id === 'refund' ? this.intl.t('storefront.order.cancel.refund-to', { method: this.plan.method_label ?? '' }) : this.intl.t('storefront.order.cancel.store-credit'),
        }));

        return [...options, { id: 'none', label: this.intl.t('storefront.order.cancel.keep') }];
    }

    /** A gateway that refunds whole payments only (QPay) refunds all of it or nothing. */
    get amountLocked() {
        return this.draft.giveBack === 'refund' && !this.plan?.supports_partial_refund;
    }

    get amountHelp() {
        if (this.amountLocked) {
            return this.intl.t('storefront.order.cancel.whole-only', { method: this.plan?.method_label ?? '' });
        }
        if (this.draft.giveBack === 'store_credit') {
            return this.intl.t('storefront.order.cancel.credit-help');
        }

        return this.intl.t('storefront.order.cancel.up-to', { amount: this.money(this.refundable) });
    }

    get reviewLine() {
        if (!this.refundable || this.draft.giveBack === 'none') {
            return this.intl.t('storefront.order.cancel.review.none');
        }

        return this.intl.t(`storefront.order.cancel.review.${this.draft.giveBack}`, { amount: this.money(this.draft.amount), method: this.plan?.method_label ?? '' });
    }

    get acceptText() {
        if (!this.refundable || this.draft.giveBack === 'none') {
            return this.intl.t('storefront.order.cancel.confirm');
        }

        return this.intl.t(`storefront.order.cancel.confirm-${this.draft.giveBack === 'refund' ? 'refund' : 'credit'}`, { amount: this.money(this.draft.amount) });
    }

    syncAcceptText() {
        this.modalsManager.setOption('acceptButtonText', this.acceptText);
        this.modalsManager.setOption('acceptButtonDisabled', this.refundable > 0 && this.draft.giveBack !== 'none' && !(this.draft.amount > 0));
    }

    @action pickReason(reason) {
        this.draft.reason = reason;
    }

    @action setGiveBack(giveBack) {
        this.draft.giveBack = giveBack;
        if (this.amountLocked || !(this.draft.amount > 0)) {
            this.draft.amount = this.refundable;
        }
        this.syncAcceptText();
    }

    @action setAmount(amount) {
        this.draft.amount = Math.min(Math.max(0, Number(amount) || 0), this.refundable);
        this.syncAcceptText();
    }
}
