import Model, { attr, belongsTo, hasMany } from '@ember-data/model';
import { computed } from '@ember/object';
import { isArray } from '@ember/array';
import { getOwner } from '@ember/application';
import { format, formatDistanceToNow } from 'date-fns';

export default class StoreLocationModel extends Model {
    /** @ids */
    @attr('string') store_uuid;
    @attr('string') created_by_uuid;
    @attr('string') place_uuid;

    /** @relationships */
    @belongsTo('place', { async: false }) place;
    @hasMany('store-hour') hours;

    /** @attributes */
    @attr('string') name;
    @attr('string') address;

    /** @dates */
    @attr('date') created_at;
    @attr('date') updated_at;

    /** @methods */
    toJSON() {
        return this.serialize();
    }

    loadPlace() {
        const owner = getOwner(this);
        const store = owner.lookup(`service:store`);

        return new Promise((resolve) => {
            if (!this.place_uuid) {
                return resolve(null);
            }

            if (this.place) {
                return resolve(this.place);
            }

            return store
                .findRecord('place', this.place_uuid)
                .then((place) => {
                    this.place = place;

                    resolve(place);
                })
                .catch(() => resolve(null));
        });
    }

    /** @computed */
    @computed('hours.[]') get schedule() {
        const schedule = {};
        const week = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

        for (let i = 0; i < week.length; i++) {
            const day = week.objectAt(i);

            schedule[day] = [];
        }

        for (let i = 0; i < this.hours.length; i++) {
            const hour = this.hours.objectAt(i);

            if (!isArray(schedule[hour.day_of_week])) {
                schedule[hour.day_of_week] = [];
            }

            schedule[hour.day_of_week].pushObject(hour);
        }

        return schedule;
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
