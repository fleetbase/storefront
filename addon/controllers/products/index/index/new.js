import ProductsIndexCategoryNewController from '../category/new';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

/**
 * The category-scoped create form, started from "All products": no preselected
 * category, and saving returns to the product list instead of a category.
 */
export default class ProductsIndexIndexNewController extends ProductsIndexCategoryNewController {
    get category() {
        return null;
    }

    @task *saveProduct() {
        const loader = this.loader.showLoader('body', { loadingMessage: 'Creating new product...' });

        try {
            yield this.product.serializeMeta().save();
        } catch (error) {
            this.loader.removeLoader(loader);
            return this.notifications.serverError(error);
        }

        this.loader.removeLoader(loader);
        this.notifications.success(this.intl.t('storefront.products.index.new.new-product-created-success'));

        try {
            yield this.transitionToRoute('products.index.index');
        } catch (error) {
            this.notifications.serverError(error);
        }

        this.reset();
    }

    @action exit(closeOverlay) {
        return closeOverlay(() => {
            return this.transitionToRoute('products.index.index').then(() => {
                this.reset();
            });
        });
    }
}
