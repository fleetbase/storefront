import Model, { attr, belongsTo, hasMany } from '@ember-data/model';
import { tracked } from '@glimmer/tracking';
import { getOwner } from '@ember/application';
import { setProperties } from '@ember/object';
import { isEmpty } from '@ember/utils';
import { underscore } from '@ember/string';
import { format, formatDistanceToNow } from 'date-fns';

export default class ProductModel extends Model {
    /** @ids */
    @attr('string') created_by_uuid;
    @attr('string') company_uuid;
    @attr('string') store_uuid;
    @attr('string') category_uuid;
    @attr('string') primary_image_uuid;
    @attr('string') public_id;

    /** @relationships */
    @belongsTo('category') category;
    @belongsTo('file') primary_image;
    @hasMany('file') files;
    @hasMany('product-variant', { async: false }) variants;
    @hasMany('product-addon-category', { async: false }) addon_categories;
    @hasMany('product-hour') hours;

    /** @attributes */
    @attr('string', { defaultValue: '' }) name;
    @attr('string', { defaultValue: '' }) description;
    @attr('string') primary_image_url;
    @attr('string') sku;
    @attr('string') currency;
    @attr('string') price;
    @attr('string') sale_price;
    @attr('raw') tags;
    @attr('raw') youtube_urls;
    @attr('raw') translations;
    @attr('raw') meta;
    @attr('raw') meta_array;
    @attr('boolean') is_on_sale;
    @attr('boolean') is_recommended;
    @attr('boolean') is_service;
    @attr('boolean') is_available;
    @attr('boolean') is_bookable;
    @attr('string') status;
    @attr('string') slug;

    /** @tracked */
    @tracked isLoadingVariants = false;
    @tracked isLoadingAddons = false;
    @tracked isLoadingFiles = false;
    @tracked isLoadingHours = false;

    /** @dates */
    @attr('date') created_at;
    @attr('date') updated_at;

    /** @methods */
    toJSON() {
        return {
            uuid: this.id,
            created_by_uuid: this.created_by_uuid,
            company_uuid: this.company_uuid,
            store_uuid: this.store_uuid,
            category_uuid: this.category_uuid,
            primary_image_uuid: this.primary_image_uuid,
            public_id: this.public_id,
            name: this.name,
            category: this.category,
            primary_image: this.primary_image,
            files: this.files,
            variants: this.variants,
            addon_categories: this.addon_categories,
            hours: this.hours,
            description: this.description,
            primary_image_url: this.primary_image_url,
            sku: this.sku,
            currency: this.currency,
            price: this.price,
            sale_price: this.sale_price,
            tags: this.tags,
            youtube_urls: this.youtube_urls,
            translations: this.translations,
            is_on_sale: this.is_on_sale,
            is_recommended: this.is_recommended,
            is_service: this.is_service,
            is_available: this.is_available,
            is_bookable: this.is_bookable,
            status: this.status,
            slug: this.slug,
            created_at: this.created_at,
            updated_at: this.updated_at
        };
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

    /** @methods */
    serializeMeta() {
        let { meta_array } = this;

        if (isEmpty(meta_array)) {
            return this;
        }

        const serialized = {};

        for (let i = 0; i < meta_array.length; i++) {
            const metaField = meta_array.objectAt(i);
            const { label, value } = metaField;

            if (!label) {
                continue;
            }

            serialized[underscore(label)] = value;
        }

        setProperties(this, { meta: serialized });

        return this;
    }

    loadAddonCategories() {
        const owner = getOwner(this);
        const store = owner.lookup(`service:store`);

        this.isLoadingAddons = true;

        return new Promise((resolve) => {
            return store
                .query('product-addon-category', { product_uuid: this.id, with: ['category'] })
                .then((productAddonCategories) => {
                    this.addon_categories = productAddonCategories;
                    this.isLoadingAddons = false;

                    resolve(productAddonCategories);
                })
                .catch((error) => {
                    this.isLoadingAddons = false;
                    resolve([]);
                    throw error;
                });
        });
    }

    loadVariants() {
        const owner = getOwner(this);
        const store = owner.lookup(`service:store`);

        this.isLoadingVariants = true;

        return new Promise((resolve) => {
            return store
                .query('product-variant', { product_uuid: this.id, with: ['options'] })
                .then((variants) => {
                    this.variants = variants;
                    this.isLoadingVariants = false;

                    resolve(variants);
                })
                .catch((error) => {
                    this.isLoadingVariants = false;
                    resolve([]);
                    throw error;
                });
        });
    }

    loadHours() {
        const owner = getOwner(this);
        const store = owner.lookup(`service:store`);

        this.isLoadingHours = true;

        return new Promise((resolve) => {
            return store
                .query('product-hour', { product_uuid: this.id })
                .then((hours) => {
                    this.hours = hours;
                    this.isLoadingHours = false;

                    resolve(hours);
                })
                .catch((error) => {
                    this.isLoadingHours = false;
                    resolve([]);
                    throw error;
                });
        });
    }

    loadFiles() {
        const owner = getOwner(this);
        const store = owner.lookup(`service:store`);

        this.isLoadingFiles = true;

        return new Promise((resolve) => {
            return store
                .query('file', { subject_uuid: this.id, type: 'storefront_product' })
                .then((files) => {
                    this.files = files;

                    // set the primary image if applicable
                    for (let i = 0; i < files.length; i++) {
                        const file = files.objectAt(i);

                        if (file.id === this.primary_image_uuid) {
                            this.primary_image = file;
                            break;
                        }
                    }

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
