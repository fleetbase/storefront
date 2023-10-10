import Model, { attr } from '@ember-data/model';
import { format, formatDistanceToNow } from 'date-fns';

export default class ProductAddonModel extends Model {
    /** @ids */
    @attr('string') created_by_uuid;
    @attr('string') category_uuid;

    /** @attributes */
    @attr('string', { defaultValue: '' }) name;
    @attr('string', { defaultValue: '' }) description;
    @attr('string') price;
    @attr('string') sale_price;
    @attr('boolean') is_on_sale;

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
