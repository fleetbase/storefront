import Component from '@glimmer/component';

export default class StorefrontOrderDetailsCommerceSummaryComponent extends Component {
    /** Each promotion applied at checkout, with what it took off the order (items and delivery). */
    get promotions() {
        const promotions = this.args.resource?.meta?.promotions;
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
        const discount = Number(this.args.resource?.meta?.discount) || 0;

        return this.promotions.length === 0 && discount > 0 ? discount : 0;
    }
}
