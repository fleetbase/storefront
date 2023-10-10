import Model, { attr, belongsTo } from '@ember-data/model';
import { format, formatDistanceToNow } from 'date-fns';

export default class NotificationChannelModel extends Model {
    /** @ids */
    @attr('string') created_by_uuid;
    @attr('string') company_uuid;
    @attr('string') owner_uuid;
    @attr('string') certificate_uuid;

    /** @relationships */
    @belongsTo('file') certificate;

    /** @attributes */
    @attr('string', { defaultValue: '' }) name;
    @attr('string') scheme;
    @attr('string') app_key;
    @attr('string') owner_type;
    @attr('object') config;
    @attr('object') options;

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
