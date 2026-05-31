import Component from '@glimmer/component';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

const storefrontRecordCache = new Map();

export default class StorefrontOrderDetailsStoreComponent extends Component {
    @service store;

    @tracked storefrontRecord;
    @tracked isLoadingStorefront = false;

    get storefrontId() {
        return this.args.resource?.meta?.storefront_id;
    }

    get storefrontDisplay() {
        return this.storefrontRecord ?? {};
    }

    get fallbackStorefrontName() {
        const storefront = this.args.resource?.meta?.storefront;

        if (typeof storefront === 'string') {
            return storefront;
        }

        return storefront?.name;
    }

    get storeName() {
        return this.storefrontDisplay.name ?? this.fallbackStorefrontName ?? this.storefrontId ?? 'Store';
    }

    get storeLogoUrl() {
        return this.storefrontDisplay.logo_url;
    }

    get storePhone() {
        return this.storefrontDisplay.phone;
    }

    get storeEmail() {
        return this.storefrontDisplay.email;
    }

    get storeWebsite() {
        return this.storefrontDisplay.website;
    }

    @action setupComponent() {
        this.loadStorefront();
    }

    async loadStorefront() {
        const storefrontId = this.storefrontId;
        const modelName = this.getModelNameFromPublicId(storefrontId);

        if (!storefrontId || !modelName) {
            this.storefrontRecord = null;
            return;
        }

        const cacheKey = `${modelName}:${storefrontId}`;

        if (storefrontRecordCache.has(cacheKey)) {
            this.storefrontRecord = await storefrontRecordCache.get(cacheKey);
            return;
        }

        this.isLoadingStorefront = true;

        const promise = this.store.findRecord(modelName, storefrontId).catch(() => null);
        storefrontRecordCache.set(cacheKey, promise);

        this.storefrontRecord = await promise;
        this.isLoadingStorefront = false;
    }

    getModelNameFromPublicId(publicId) {
        if (typeof publicId !== 'string') {
            return null;
        }

        if (publicId.startsWith('store_')) {
            return 'store';
        }

        if (publicId.startsWith('network_')) {
            return 'network';
        }

        return null;
    }
}
