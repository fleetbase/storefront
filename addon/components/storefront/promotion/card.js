import Component from '@glimmer/component';
import { inject as service } from '@ember/service';

/**
 * A promotion on the shared card anatomy: what it gives, when it applies, how it is doing.
 */
export default class StorefrontPromotionCardComponent extends Component {
    @service intl;

    get promotion() {
        return this.args.promotion ?? this.args.resource;
    }

    /**
     * Row actions whose `isVisible` passes for this promotion, separators kept.
     */
    get menuItems() {
        const items = this.args.actions ?? [];

        return items.filter((item) => item.separator || typeof item.isVisible !== 'function' || item.isVisible(this.promotion));
    }

    get isScheduled() {
        const promotion = this.promotion;

        return promotion?.status === 'active' && promotion.starts_at && new Date(promotion.starts_at) > new Date();
    }

    get displayStatus() {
        return this.isScheduled ? 'scheduled' : (this.promotion?.status ?? 'draft');
    }

    get valueLabel() {
        const promotion = this.promotion;

        switch (promotion?.type) {
            case 'percentage':
                return `${promotion.value ?? 0}%`;
            case 'fixed_amount':
                return this.intl.formatNumber((promotion.value ?? 0) / 100, { style: 'currency', currency: promotion.currency ?? 'USD' });
            case 'free_delivery':
                return this.intl.t('storefront.promotions.types.free_delivery');
            case 'bogo': {
                const bogo = promotion.bogo_config ?? {};
                return this.intl.t('storefront.promotions.card.bogo-summary', { buy: bogo.buy_quantity ?? 1, get: bogo.get_quantity ?? 1 });
            }
            default:
                return null;
        }
    }

    get triggerLabel() {
        const promotion = this.promotion;

        if (promotion?.first_order_only) {
            return this.intl.t('storefront.promotions.card.first-order');
        }

        if (promotion?.trigger === 'code') {
            return this.intl.t('storefront.promotions.card.with-code');
        }

        if (promotion?.min_subtotal) {
            return this.intl.t('storefront.promotions.card.cart-total-above', {
                amount: this.intl.formatNumber(promotion.min_subtotal / 100, { style: 'currency', currency: promotion.currency ?? 'USD' }),
            });
        }

        return this.intl.t('storefront.promotions.triggers.automatic');
    }

    get windowLabel() {
        const promotion = this.promotion;

        if (promotion?.runsFrom && promotion?.runsUntil) {
            return `${promotion.runsFrom} – ${promotion.runsUntil}`;
        }

        if (promotion?.runsFrom) {
            return this.intl.t('storefront.promotions.card.starts', { date: promotion.runsFrom });
        }

        if (promotion?.runsUntil) {
            return this.intl.t('storefront.promotions.card.ends', { date: promotion.runsUntil });
        }

        return this.intl.t('storefront.promotions.card.always');
    }

    get targetsLabel() {
        const applies = this.promotion?.applies_to ?? {};
        const parts = [];

        if (applies.products?.length) {
            parts.push(this.intl.t('storefront.promotions.card.products-count', { count: applies.products.length }));
        }

        if (applies.categories?.length) {
            parts.push(this.intl.t('storefront.promotions.card.categories-count', { count: applies.categories.length }));
        }

        return parts.length ? parts.join(' · ') : this.intl.t('storefront.promotions.card.whole-store');
    }
}
