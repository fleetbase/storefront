import Component from '@glimmer/component';

function isAboveZero(value) {
    return (parseFloat(String(value ?? '').replace('%', '')) || 0) > 0;
}

/**
 * An order's items and totals, shared by the order panel and the incoming order modal.
 */
export default class StorefrontOrderLineItemsComponent extends Component {
    get order() {
        return this.args.order ?? this.args.resource;
    }

    /** Each promotion applied at checkout, with what it took off the order (items and delivery). */
    get promotions() {
        const promotions = this.order?.meta?.promotions;
        if (!Array.isArray(promotions)) {
            return [];
        }

        return promotions.map((promotion) => ({
            name: promotion.name || promotion.code || 'Promotion',
            code: promotion.code,
            isFreeDelivery: promotion.type === 'free_delivery',
            amount: (Number(promotion.amount) || 0) + (Number(promotion.delivery_amount) || 0),
        }));
    }

    /** The order's discount when no promotion breakdown was recorded. */
    get unattributedDiscount() {
        const discount = Number(this.order?.meta?.discount) || 0;

        return this.promotions.length === 0 && discount > 0 ? discount : 0;
    }

    /** A tip line shows only when there is a tip: an amount or a percentage above zero. */
    get hasTip() {
        return isAboveZero(this.order?.meta?.tip);
    }

    get hasDeliveryTip() {
        return isAboveZero(this.order?.meta?.delivery_tip);
    }
}
