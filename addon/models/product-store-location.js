import Model, { attr, hasMany } from '@ember-data/model';
import { format, formatDistanceToNow } from 'date-fns';

export default class ProductStoreLocationModel extends Model {
    /** @ids */
    @attr('string') created_by_uuid;
    @attr('string') company_uuid;

    /** @relationships */
    @hasMany('store') stores;

    /** @attributes */
    @attr('string', { defaultValue: '' }) name;
    @attr('string', { defaultValue: '' }) description;
    @attr('string') slug;

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
