import Model, { attr } from '@ember-data/model';
import { format, isValid } from 'date-fns';

export default class CampaignModel extends Model {
    /** @ids */
    @attr('string') public_id;
    @attr('string') owner_uuid;
    @attr('string') owner_type;
    @attr('string') segment_uuid;
    @attr('string') promotion_uuid;
    @attr('string') image_uuid;

    /** @attributes */
    @attr('string') name;
    @attr('string', { defaultValue: 'draft' }) status;
    @attr('raw', { defaultValue: () => ['push', 'inbox'] }) channels;
    @attr('raw') recipients;
    @attr('string') title;
    @attr('string') body;
    @attr('string') image_url;
    @attr('raw') action;
    @attr('string') segment_name;
    @attr('string') promotion_name;
    @attr('date') send_at;
    @attr('date') started_at;
    @attr('date') sent_at;
    @attr('raw') stats;
    @attr('raw') meta;

    /** @dates */
    @attr('date') created_at;
    @attr('date') updated_at;

    /** @computed */
    get isEditable() {
        return ['draft', 'scheduled'].includes(this.status);
    }

    get targeted() {
        return this.stats?.targeted ?? null;
    }

    get sendAtFormatted() {
        return this.send_at && isValid(this.send_at) ? format(this.send_at, 'PP p') : null;
    }

    get sentAtFormatted() {
        return this.sent_at && isValid(this.sent_at) ? format(this.sent_at, 'PP p') : null;
    }
}
