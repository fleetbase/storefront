import Model, { attr } from '@ember-data/model';

export default class PromotionCodeModel extends Model {
    /** @ids */
    @attr('string') public_id;
    @attr('string') promotion_uuid;
    @attr('string') customer_uuid;

    /** @attributes */
    @attr('string') code;
    @attr('string', { defaultValue: 'active' }) status;
    @attr('number') usage_limit;
    @attr('number') times_used;
    @attr('date') expires_at;

    /** @dates */
    @attr('date') created_at;
    @attr('date') updated_at;
}
