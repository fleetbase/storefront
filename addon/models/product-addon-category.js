import Model, { attr, belongsTo } from '@ember-data/model';
import { format, formatDistanceToNow } from 'date-fns';

export default class ProductAddonCategoryModel extends Model {
    /** @ids */
    @attr('string') product_uuid;
    @attr('string') category_uuid;

    /** @relationships */
    @belongsTo('addon-category') category;

    /** @attributes */
    @attr('string') name;
    @attr('raw') excluded_addons;

    /** @dates */
    @attr('date') created_at;
    @attr('date') updated_at;

    /** @methods */
    toJSON() {
        return this.serialize();
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
