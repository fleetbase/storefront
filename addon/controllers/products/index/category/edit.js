import ProductsIndexCategoryNewController from './new';
import { tracked } from '@glimmer/tracking';
import { alias } from '@ember/object/computed';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

export default class ProductsIndexCategoryEditController extends ProductsIndexCategoryNewController {
    @alias('model') product;
    @service intl;
    @tracked overlayActionButtonTitle = 'Save Changes';
    @tracked overlayActionButtonIcon = 'save';
    @tracked overlayExitButtonTitle = 'Done';

    get overlayTitle() {
        return `Edit ${this.product.name}`;
    }

    @action saveProduct() {
        this.isSaving = true;

        this.product
            .serializeMeta()
            .save()
            .then(() => {
                this.isSaving = false;
                this.notifications.success(this.intl.t('storefront.products.index.edit.changes-saved'));
            })
            .catch((error) => {
                this.isSaving = false;
                this.notifications.serverError(error);
            });
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
