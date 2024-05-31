import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';
import { pluralize } from 'ember-inflector';

export default class ModalsSelectProductComponent extends Component {
    @service fetch;
    @service notifications;
    @service modalsManager;
    @tracked stores = [];
    @tracked productCategories = [];
    @tracked products = [];
    @tracked selectedProducts = [];
    @tracked selectedStorefront;
    @tracked selectedCategory;
    @tracked storefrontSelectAPI;

    constructor() {
        super(...arguments);
        this.fetchStorefronts.perform();
    }

    @action toggleProductSelection(product) {
        if (this.selectedProducts.includes(product)) {
            this.selectedProducts.removeObject(product);
        } else {
            this.selectedProducts.pushObject(product);
        }

        this.updateSelectedProducts();
    }

    updateSelectedProducts() {
        this.modalsManager.setOption('selectedProducts', this.selectedProducts);
        this.modalsManager.setOption('acceptButtonDisabled', this.selectedProducts.length === 0);
        this.modalsManager.setOption('acceptButtonText', this.selectedProducts.length ? `Add ${pluralize(this.selectedProducts.length, 'Product')}` : 'Add Products');
        this.modalsManager.setOption('selectedStorefront', this.selectedStorefront);
    }

    @action setStorefrontSelectApi(storefrontSelectAPI) {
        this.storefrontSelectAPI = storefrontSelectAPI;
    }

    @action onStorefrontSelect(storefront) {
        this.selectedStorefront = storefront;
        this.selectedCategory = null;
        this.selectedProducts = [];
        this.updateSelectedProducts();
        this.fetchCategoriesForStorefront.perform(storefront);
        this.fetchProductsForStorefront.perform(storefront);
    }

    @action onSelectProductCategory(category) {
        this.selectedCategory = category;
        this.fetchProductsForStorefront.perform(this.selectedStorefront, { category_slug: category.slug });
    }

    @task *fetchStorefronts(queryParams = {}) {
        try {
            this.stores = yield this.fetch.get('stores', queryParams, { namespace: 'storefront/int/v1', normalizeToEmberData: true });
        } catch (error) {
            this.notifications.serverError(error);
            return;
        }

        if (this.stores && this.storefrontSelectAPI) {
            this.storefrontSelectAPI.actions.select(this.stores[0]);
        }
    }

    @task *fetchProductsForStorefront(storefront, queryParams = {}) {
        try {
            this.products = yield this.fetch.get('products', { store_uuid: storefront.id, ...queryParams }, { namespace: 'storefront/int/v1', normalizeToEmberData: true });
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *fetchCategoriesForStorefront(storefront, queryParams = {}) {
        try {
            this.productCategories = yield this.fetch.get('categories', { for: 'storefront_product', owner_uuid: storefront.id, limit: -1, ...queryParams }, { normalizeToEmberData: true });
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
