import ProductsIndexCategoryNewController from './new';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';
import { filterHasManyForNewRecords } from '../../../../serializers/product';

export default class ProductsIndexCategoryEditController extends ProductsIndexCategoryNewController {
    @service intl;
    @tracked product;
    @tracked overlayActionButtonTitle = 'Save Changes';
    @tracked overlayActionButtonIcon = 'save';
    @tracked overlayExitButtonTitle = 'Done';
    abilityPermission = 'storefront update product';

    get overlayTitle() {
        return `Edit ${this.product.name}`;
    }

    @task *saveProduct() {
        let savedProduct;
        try {
            savedProduct = yield this.product.serializeMeta().save();
        } catch (error) {
            return this.notifications.serverError(error);
        }
        this.product = filterHasManyForNewRecords(savedProduct, ['variants', 'addon_categories', 'hours']);
        this.notifications.success(this.intl.t('storefront.products.index.edit.changes-saved'));
    }

    @action transitionBack({ closeOverlay }) {
        if (this.isSaving) {
            return;
        }

        if (this.product.hasDirtyAttributes) {
            // details have been added warn user it will lost
            return this.modalsManager.confirm({
                title: this.intl.t('storefront.products.index.edit.title'),
                body: this.intl.t('storefront.products.index.edit.body'),
                confirm: (modal) => {
                    modal.done();
                    return this.exit(closeOverlay);
                },
            });
        }

        return this.exit(closeOverlay);
    }

    @action exit(closeOverlay) {
        return closeOverlay(() => {
            window.history.back();
        });
    }

    @action removeFile(file) {
        this.product.files.removeObject(file);
    }
}
