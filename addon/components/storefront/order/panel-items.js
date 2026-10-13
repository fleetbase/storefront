import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { format, isValid, parseISO } from 'date-fns';
import formatCurrency from '@fleetbase/ember-ui/utils/format-currency';

function cents(value) {
    const parsed = parseFloat(String(value ?? '').replace(/,/g, ''));
    return Number.isFinite(parsed) ? Math.round(parsed) : 0;
}

/** A tip stored as an amount or a percentage of the subtotal. */
function tipAmount(tip, subtotal) {
    if (!tip) {
        return 0;
    }
    const text = String(tip);
    return text.endsWith('%') ? Math.round((parseFloat(text) / 100) * subtotal) : cents(text);
}

/**
 * The 11a items section with totals and the payment line. Everything on the order is taken as packed unless staff mark an item unavailable.
 */
export default class StorefrontOrderPanelItemsComponent extends Component {
    @service intl;
    @service storefrontOrderActions;

    get order() {
        return this.args.order;
    }

    get meta() {
        return this.order?.meta ?? {};
    }

    get currency() {
        return this.meta.currency ?? this.args.overview?.payment?.currency;
    }

    get entities() {
        const entities = this.order?.payload?.entities;
        return entities?.toArray?.() ?? Array.from(entities ?? []);
    }

    get rows() {
        return this.entities.map((entity) => {
            const meta = entity.meta ?? {};
            const quantity = Number(meta.quantity) || 1;
            const total = cents(meta.subtotal);
            const options = [
                ...(meta.variants ?? []).map((variant) => variant?.name ?? variant?.label ?? variant?.option),
                ...(meta.addons ?? []).map((addon) => `+ ${addon?.name ?? addon?.label}`),
            ].filter(Boolean);

            return {
                id: entity.public_id,
                entity,
                quantity,
                single: quantity === 1,
                name: entity.name,
                image: entity.photo_url ?? meta.image_url,
                options,
                note: meta.note,
                unavailable: Boolean(meta.unavailable),
                total,
                unit: Math.round(total / quantity),
            };
        });
    }

    get countLine() {
        const items = this.rows.reduce((sum, row) => sum + row.quantity, 0);
        return this.intl.t('storefront.order.overview.items-count', { count: items, products: this.rows.length });
    }

    get promotions() {
        const promotions = Array.isArray(this.meta.promotions) ? this.meta.promotions : [];
        const rows = promotions.map((promotion) => ({
            label: promotion.code ? this.intl.t('storefront.order.panel.promotion', { code: promotion.code }) : (promotion.name ?? this.intl.t('storefront.order.sections.discount')),
            amount: (Number(promotion.amount) || 0) + (Number(promotion.delivery_amount) || 0),
        }));
        const discount = Number(this.meta.discount) || 0;

        return rows.length ? rows : discount > 0 ? [{ label: this.intl.t('storefront.order.sections.discount'), amount: discount }] : [];
    }

    get tip() {
        return Number(this.meta.tip_amount) || tipAmount(this.meta.tip, cents(this.meta.subtotal));
    }

    get deliveryTip() {
        return this.meta.is_pickup ? 0 : Number(this.meta.delivery_tip_amount) || tipAmount(this.meta.delivery_tip, cents(this.meta.subtotal));
    }

    /** The payment in one line: paid / to collect / due / refunded, from the payment summary. */
    get payment() {
        const payment = this.args.overview?.payment;
        if (!payment?.state) {
            return null;
        }

        const t = (key, options) => this.intl.t(`storefront.order.panel.payment.${key}`, options);
        const money = (amount) => formatCurrency(amount ?? 0, payment.currency ?? this.currency);
        const paidAt = this.args.overview?.payment_reference?.paid_at ? parseISO(this.args.overview.payment_reference.paid_at) : null;
        const at = paidAt && isValid(paidAt) ? format(paidAt, 'HH:mm') : null;
        const method = payment.method_label;

        switch (payment.state) {
            case 'to_collect':
                return { tone: 'info', icon: 'money-bill', headline: t('to-collect', { amount: money(payment.cod_amount || payment.total) }), detail: t('cash-on-delivery') };
            case 'unpaid':
                return { tone: 'warn', icon: 'circle-exclamation', headline: t('unpaid'), detail: money(payment.total) };
            case 'balance_due':
                return {
                    tone: 'warn',
                    icon: 'circle-exclamation',
                    headline: t('balance-due', { amount: money(payment.balance_due) }),
                    detail: t('paid-by', { amount: money(payment.amount_paid), method }),
                };
            case 'refunded':
                return { tone: 'gray', icon: 'rotate-left', headline: t('refunded'), detail: t('refunded-to', { amount: money(payment.amount_refunded), method }) };
            case 'partially_refunded':
                return payment.amount_credited > 0 && !payment.amount_refunded
                    ? { tone: 'info', icon: 'rotate-left', headline: t('paid'), detail: t('credited', { method, amount: money(payment.amount_credited) }), code: payment.store_credit_code }
                    : {
                          tone: 'info',
                          icon: 'rotate-left',
                          headline: t('paid'),
                          detail: t('partly-refunded', { method, amount: money(payment.amount_refunded) }),
                          code: payment.store_credit_code,
                      };
            default:
                return { tone: 'green', icon: 'check', headline: t('paid'), detail: at ? t('paid-at', { method, time: at }) : t('paid-with', { method }) };
        }
    }

    @action substitute() {
        return this.storefrontOrderActions.editOrder(this.order, { focus: 'items' });
    }
}
