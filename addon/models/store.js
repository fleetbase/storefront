import Model, { attr, belongsTo, hasMany } from '@ember-data/model';
import { getOwner } from '@ember/application';
import { tracked } from '@glimmer/tracking';
import { computed } from '@ember/object';
import { isArray } from '@ember/array';
import { format as formatDate, isValid as isValidDate, formatDistanceToNow } from 'date-fns';

export default class StoreModel extends Model {
    /** @ids */
    @attr('string') created_by_uuid;
    @attr('string') company_uuid;
    @attr('string') logo_uuid;
    @attr('string') backdrop_uuid;

    /** @relationships */
    @hasMany('notification-channel') notification_channels;
    @hasMany('gateway') gateways;
    @belongsTo('category') category;
    @belongsTo('file') logo;
    @belongsTo('file') backdrop;
    @hasMany('file') files;

    /** @attributes */
    @attr('string', { defaultValue: '' }) name;
    @attr('string', { defaultValue: '' }) description;
    @attr('string', { defaultValue: '' }) website;
    @attr('string', { defaultValue: '' }) email;
    @attr('string', { defaultValue: '' }) phone;
    @attr('string', { defaultValue: '' }) facebook;
    @attr('string', { defaultValue: '' }) instagram;
    @attr('string', { defaultValue: '' }) twitter;
    @attr('string') public_id;
    @attr('string') key;
    @attr('boolean') online;
    @attr('string') currency;
    @attr('string') timezone;
    @attr('string') pod_method;
    @attr('object') options;
    @attr('raw') translations;
    @attr('raw') tags;
    @attr('raw') alertable;
    @attr('string') logo_url;
    @attr('string') backdrop_url;
    @attr('string') slug;

    /** @dates */
    @attr('date') created_at;
    @attr('date') updated_at;

    /** @tracked */
    @tracked isLoadingFiles = false;

    /** @computed */
    @computed('tags') get tagsList() {
        if (isArray(this.tags)) {
            this.tags.join(', ');
        }

        return '';
    }

    @computed('updated_at') get updatedAgo() {
        if (!isValidDate(this.updated_at)) {
            return null;
        }
        return formatDistanceToNow(this.updated_at);
    }

    @computed('updated_at') get updatedAt() {
        if (!isValidDate(this.updated_at)) {
            return null;
        }
        return formatDate(this.updated_at, 'PPP p');
    }

    @computed('updated_at') get updatedAtShort() {
        if (!isValidDate(this.updated_at)) {
            return null;
        }
        return formatDate(this.updated_at, 'PP');
    }

    @computed('created_at') get createdAgo() {
        if (!isValidDate(this.created_at)) {
            return null;
        }
        return formatDistanceToNow(this.created_at);
    }

    @computed('created_at') get createdAt() {
        if (!isValidDate(this.created_at)) {
            return null;
        }
        return formatDate(this.created_at, 'PPP p');
    }

    @computed('created_at') get createdAtShort() {
        if (!isValidDate(this.created_at)) {
            return null;
        }
        return formatDate(this.created_at, 'PP');
    }

    /** @methods */
    toJSON() {
        return {
            name: this.name,
            description: this.description,
            currency: this.currency,
            timezone: this.timezone,
            options: this.options,
        };
    }

    loadFiles() {
        const owner = getOwner(this);
        const store = owner.lookup(`service:store`);

        this.isLoadingFiles = true;

        return new Promise((resolve) => {
            return store
                .query('file', { subject_uuid: this.id, type: 'storefront_store_media' })
                .then((files) => {
                    this.files = files.toArray();
                    this.isLoadingFiles = false;

                    resolve(files);
                })
                .catch((error) => {
                    this.isLoadingFiles = false;
                    resolve([]);
                    throw error;
                });
        });
    }
}
