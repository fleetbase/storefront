import Model, { attr, hasMany } from '@ember-data/model';
import { format, formatDistanceToNow } from 'date-fns';

export default class CatalogModel extends Model {
    /** @ids */
    @attr('string') store_uuid;
    @attr('string') created_by_uuid;
    @attr('string') company_uuid;

    /** @relationships */
    @hasMany('catalog-category', { async: false }) categories;
    @hasMany('catalog-hour', { async: false }) hours;

    /** @attributes */
    @attr('string') name;
    @attr('string') description;
    @attr('raw') meta;
    @attr('string') status;

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
