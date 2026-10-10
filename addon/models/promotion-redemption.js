import Model, { attr } from '@ember-data/model';
import { format, formatDistanceToNow, isValid } from 'date-fns';

export default class PromotionRedemptionModel extends Model {
    /** @ids */
    @attr('string') promotion_uuid;
    @attr('string') promotion_code_uuid;
    @attr('string') customer_uuid;
    @attr('string') checkout_uuid;
    @attr('string') order_uuid;

    /** @attributes */
    @attr('string') promotion_name;
    @attr('string') promotion_type;
    @attr('string') code;
    @attr('string') customer_name;
    @attr('string') customer_public_id;
    @attr('string') order_public_id;
    @attr('string') order_status;
    @attr('number') amount;
    @attr('string') currency;
    @attr('string') status;

    /** @dates */
    @attr('date') redeemed_at;
    @attr('date') created_at;
    @attr('date') updated_at;

    /** @computed */
    get redeemedAt() {
        const date = this.redeemed_at ?? this.created_at;

        return date && isValid(date) ? format(date, 'PP p') : null;
    }

    get redeemedAgo() {
        const date = this.redeemed_at ?? this.created_at;

        return date && isValid(date) ? formatDistanceToNow(date, { addSuffix: true }) : null;
    }
}
