import Model, { attr, belongsTo } from '@ember-data/model';
import { computed } from '@ember/object';
import { format, formatDistanceToNow, parse, isValid } from 'date-fns';

export default class CatalogHourModel extends Model {
    /** @ids */
    @attr('string') catalog_uuid;

    /** @relationships */
    @belongsTo('catalog') catalog;

    /** @attributes */
    @attr('string') day_of_week;
    @attr('string') start;
    @attr('string') end;

    /** @dates */
    @attr('date') created_at;
    @attr('date') updated_at;

    /** @methods */
    toJSON() {
        return this.serialize();
    }

    /** @computed */
    @computed('start') get startDateInstance() {
        if (!this.start) {
            return null;
        }

        const includesSeconds = this.start.split(':').length === 3;
        const format = includesSeconds ? 'k:mm:ss' : 'k:mm';

        return parse(this.start, format, new Date());
    }

    @computed('end') get endDateInstance() {
        if (!this.end) {
            return null;
        }

        const includesSeconds = this.end.split(':').length === 3;
        const format = includesSeconds ? 'k:mm:ss' : 'k:mm';

        return parse(this.end, format, new Date());
    }

    @computed('end', 'endDateInstance', 'start', 'startDateInstance') get humanReadableHours() {
        if (!isValid(this.startDateInstance) || !isValid(this.endDateInstance)) {
            return `${this.start} - ${this.end}`;
        }

        return `${format(this.startDateInstance, 'p')} - ${format(this.endDateInstance, 'p')}`;
    }

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
