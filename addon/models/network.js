import Model, { attr, hasMany, belongsTo } from '@ember-data/model';
import { format, formatDistanceToNow } from 'date-fns';
import { getOwner } from '@ember/application';
import isEmail from '@fleetbase/console/utils/is-email';
import Store from './store';

export default class NetworkModel extends Model {
    /** @ids */
    @attr('string') created_by_uuid;
    @attr('string') company_uuid;
    @attr('string') logo_uuid;
    @attr('string') backdrop_uuid;
    @attr('string') order_config_uuid;

    /** @relationships */
    @hasMany('store') stores;
    @hasMany('notification-channel') notification_channels;
    @hasMany('gateway') gateways;
    @belongsTo('file') logo;
    @belongsTo('file') backdrop;

    /** @attributes */
    @attr('string', { defaultValue: '' }) name;
    @attr('string', { defaultValue: '' }) description;
    @attr('string', { defaultValue: '' }) website;
    @attr('string', { defaultValue: '' }) email;
    @attr('string', { defaultValue: '' }) phone;
    @attr('string', { defaultValue: '' }) facebook;
    @attr('string', { defaultValue: '' }) instagram;
    @attr('string', { defaultValue: '' }) twitter;
    @attr('raw') tags;
    @attr('raw') translations;
    @attr('raw') alertable;
    @attr('string') public_id;
    @attr('string') key;
    @attr('string') currency;
    @attr('string') timezone;
    @attr('boolean') online;
    @attr('number') stores_count;
    @attr('object') options;
    @attr('string') logo_url;
    @attr('string') backdrop_url;
    @attr('string') slug;

    /** @dates */
    @attr('date') created_at;
    @attr('date') updated_at;

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

    /** @methods */
    toJSON() {
        return this.serialize();
    }

    loadNotificationChannels() {
        const owner = getOwner(this);
        const store = owner.lookup(`service:store`);

        return new Promise((resolve, reject) => {
            return store
                .query('notification-channel', { owner_uuid: this.id })
                .then((notificationChannels) => {
                    this.notification_channels = notificationChannels.toArray();

                    resolve(notificationChannels);
                })
                .catch(reject);
        });
    }

    loadPaymentGateways() {
        const owner = getOwner(this);
        const store = owner.lookup(`service:store`);

        return new Promise((resolve, reject) => {
            return store
                .query('gateway', { owner_uuid: this.id })
                .then((gateways) => {
                    this.gateways = gateways.toArray();

                    resolve(gateways);
                })
                .catch(reject);
        });
    }

    loadStores() {
        const owner = getOwner(this);
        const store = owner.lookup(`service:store`);

        return new Promise((resolve, reject) => {
            return store
                .query('store', { network: this.id })
                .then((stores) => {
                    this.stores = stores.toArray();

                    resolve(stores);
                })
                .catch(reject);
        });
    }

    addStores(stores = [], remove = []) {
        const owner = getOwner(this);
        const fetch = owner.lookup(`service:fetch`);

        stores = stores.map((store) => {
            if (store instanceof Store) {
                return store.id;
            }

            return store;
        });

        remove = remove.map((store) => {
            if (store instanceof Store) {
                return store.id;
            }

            return store;
        });

        return fetch.post(`networks/${this.id}/add-stores`, { stores, remove }, { namespace: 'storefront/int/v1' });
    }

    removeStores(stores = []) {
        const owner = getOwner(this);
        const fetch = owner.lookup(`service:fetch`);

        stores = stores.map((store) => {
            if (store instanceof Store) {
                return store.id;
            }

            return store;
        });

        return fetch.post(`networks/${this.id}/remove-stores`, { stores }, { namespace: 'storefront/int/v1' });
    }

    sendInvites(recipients = []) {
        const owner = getOwner(this);
        const fetch = owner.lookup(`service:fetch`);

        // only send to valid recipients
        recipients = recipients.filter((email) => isEmail(email));

        return fetch.post(`networks/${this.id}/invite`, { recipients }, { namespace: 'storefront/int/v1' });
    }
}
