import Model, { attr } from '@ember-data/model';
import { format, formatDistanceToNow, isValid } from 'date-fns';

export default class PromotionModel extends Model {
    /** @ids */
    @attr('string') public_id;
    @attr('string') company_uuid;
    @attr('string') owner_uuid;
    @attr('string') owner_type;
    @attr('string') image_uuid;

    /** @attributes */
    @attr('string') name;
    @attr('string') description;
    @attr('string', { defaultValue: 'draft' }) status;
    @attr('string', { defaultValue: 'automatic' }) trigger;
    @attr('string', { defaultValue: 'percentage' }) type;
    @attr('number') value;
    @attr('number') max_discount_amount;
    @attr('string') currency;
    @attr('number') min_subtotal;
    @attr('number') min_items;
    @attr('raw') applies_to;
    @attr('raw') bogo_config;
    @attr('boolean', { defaultValue: false }) first_order_only;
    @attr('number') usage_limit;
    @attr('number') usage_limit_per_customer;
    @attr('number') budget_amount;
    @attr('boolean', { defaultValue: false }) stackable;
    @attr('number', { defaultValue: 0 }) priority;
    @attr('boolean', { defaultValue: true }) is_public;
    @attr('date') starts_at;
    @attr('date') ends_at;
    @attr('raw') schedule;
    @attr('string') timezone;
    @attr('string') image_url;
    @attr('raw') translations;
    @attr('raw') stats;
    @attr('raw') meta;

    /** @dates */
    @attr('date') created_at;
    @attr('date') updated_at;

    /** @computed */
    get isCodeBased() {
        return this.trigger === 'code';
    }

    get redemptionsCount() {
        return this.stats?.redemptions ?? 0;
    }

    get discountGiven() {
        return this.stats?.discount_given ?? 0;
    }

    get runsFrom() {
        return this.starts_at && isValid(this.starts_at) ? format(this.starts_at, 'PP p') : null;
    }

    get runsUntil() {
        return this.ends_at && isValid(this.ends_at) ? format(this.ends_at, 'PP p') : null;
    }

    get updatedAgo() {
        return this.updated_at && isValid(this.updated_at) ? formatDistanceToNow(this.updated_at) : null;
    }
}
