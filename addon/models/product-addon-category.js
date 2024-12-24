import Model, { attr, belongsTo } from '@ember-data/model';
import { format, formatDistanceToNow } from 'date-fns';
import getWithDefault from '@fleetbase/ember-core/utils/get-with-default';

export default class ProductAddonCategoryModel extends Model {
    /** @ids */
    @attr('string') product_uuid;
    @attr('string') category_uuid;

    /** @relationships */
    @belongsTo('addon-category') category;

    /** @attributes */
    @attr('string') name;
    @attr('number') max_selectable;
    @attr('boolean') is_required;
    @attr('raw') excluded_addons;

    /** @dates */
    @attr('date') created_at;
    @attr('date') updated_at;

    /** @methods */
    toJSON() {
        return {
            uuid: this.id,
            category_uuid: this.category_uuid,
            product_uuid: this.product_uuid,
            name: this.name,
            max_selectable: this.max_selectable,
            excluded_addons: getWithDefault(this, 'excluded_addons', []),
            updated_at: this.updated_at,
            created_at: this.created_at,
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
