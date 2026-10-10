import Model, { attr, hasMany } from '@ember-data/model';
import { format, formatDistanceToNow } from 'date-fns';
import { getOwner } from '@ember/application';

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
    @attr('raw') subjects;
    @attr('string') status;

    /** @dates */
    @attr('date') created_at;
    @attr('date') updated_at;

    /** @methods */
    toJSON() {
        return this.serialize();
    }

    /**
     * Replace the stores and trucks that serve this catalog.
     * @param {{ stores?: Array, food_trucks?: Array }} subjects uuids or records
     */
    assignSubjects({ stores = [], food_trucks = [] } = {}) {
        const owner = getOwner(this);
        const fetch = owner.lookup('service:fetch');
        const ids = (list) => list.map((item) => (typeof item === 'string' ? item : item?.id)).filter(Boolean);

        return fetch.put(`catalogs/${this.id}/subjects`, { stores: ids(stores), food_trucks: ids(food_trucks) }, { namespace: 'storefront/int/v1' }).then((response) => {
            const subjects = response?.catalog?.subjects;

            if (Array.isArray(subjects)) {
                this.subjects = subjects;
            }

            return this.subjects ?? [];
        });
    }

    /** @computed */
    get storeSubjects() {
        return (this.subjects ?? []).filter((subject) => subject.type === 'store');
    }

    get truckSubjects() {
        return (this.subjects ?? []).filter((subject) => subject.type === 'food-truck');
    }

    get productsCount() {
        return (this.categories ?? []).reduce((sum, category) => sum + (category.products?.length ?? 0), 0);
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
