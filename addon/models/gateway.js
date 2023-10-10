import Model, { attr } from '@ember-data/model';
import { format, formatDistanceToNow } from 'date-fns';

export default class GatewayModel extends Model {
    /** @ids */
    @attr('string') created_by_uuid;
    @attr('string') company_uuid;
    @attr('string') owner_uuid;

    /** @attributes */
    @attr('string', { defaultValue: 'storefront:store' }) owner_type;
    @attr('string', { defaultValue: '' }) name;
    @attr('string', { defaultValue: '' }) code;
    @attr('string') type;
    @attr('boolean') sandbox;
    @attr('raw') meta;
    @attr('raw') config;
    @attr('string') return_url;
    @attr('string') callback_url;

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
