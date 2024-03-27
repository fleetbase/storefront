import Model, { attr, hasMany } from '@ember-data/model';
import { format, formatDistanceToNow } from 'date-fns';
import { isArray } from '@ember/array';

export default class ProductVariantModel extends Model {
    /** @ids */
    @attr('string') product_uuid;

    /** @relationships */
    @hasMany('product-variant-option', { async: false }) options;

    /** @attributes */
    @attr('string', { defaultValue: '' }) name;
    @attr('string', { defaultValue: '' }) description;
    @attr('boolean') is_multiselect;
    @attr('boolean') is_required;
    @attr('raw') translations;

    /** @dates */
    @attr('date') created_at;
    @attr('date') updated_at;

    /** @methods */
    toJSON() {
        return {
            uuid: this.id,
            product_uuid: this.product_uuid,
            name: this.name,
            description: this.description,
            is_multiselect: this.is_multiselect,
            is_required: this.is_required,
            translations: this.translations,
            options: isArray(this.options) ? Array.from(this.options) : [],
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
