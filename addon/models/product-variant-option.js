import Model, { attr } from '@ember-data/model';
import { format, formatDistanceToNow } from 'date-fns';

export default class ProductVariantOptionModel extends Model {
    /** @ids */
    @attr('string') product_variant_uuid;

    /** @attributes */
    @attr('string', { defaultValue: '' }) name;
    @attr('string', { defaultValue: '' }) description;
    @attr('string') additional_cost;
    @attr('raw') translations;

    /** @dates */
    @attr('date') created_at;
    @attr('date') updated_at;

    /** @methods */
    toJSON() {
        return {
            uuid: this.id,
            product_variant_uuid: this.product_variant_uuid,
            name: this.name,
            description: this.description,
            additional_cost: this.additional_cost,
            translations: this.additional_cost,
        };
    }

    /** @computed */
    get updatedAgo() {
        return formatDistanceToNow(this.updated_at);
    }

    get updatedAt() {
        return format(this.updated_at, 'PPP');
    }

    get createdAgo() {
        return formatDistanceToNow(this.created_at);
    }

    get createdAt() {
        return format(this.created_at, 'PPP p');
    }
}
