import BaseController from '@fleetbase/storefront-engine/controllers/base-controller';
import { inject as controller } from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { isArray } from '@ember/array';
import { action } from '@ember/object';
import { alias } from '@ember/object/computed';
import { underscore } from '@ember/string';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class ProductsIndexCategoryNewController extends BaseController {
    @controller('products.index.category') productsIndexCategoryController;
    @service notifications;
    @service modalsManager;
    @service currentUser;
    @service store;
    @service intl;
    @service storefront;
    @service fetch;
    @service loader;
    @service crud;
    @service hostRouter;
    @alias('storefront.activeStore') activeStore;
    @tracked product = this.store.createRecord('product', { store_uuid: this.activeStore.id, currency: this.activeStore.currency, tags: [], meta_array: [], status: 'published' });
    @tracked uploadQueue = [];
    @tracked uploadedFiles = [];
    @tracked primaryFile = null;
    @tracked isSaving = false;
    @tracked statusOptions = ['published', 'draft'];
    abilityPermission = 'storefront create product';

    /** overlay options */
    @tracked overlayTitle = 'New Product';
    @tracked overlayActionButtonTitle = 'Create Product';
    @tracked overlayActionButtonIcon = 'check'; // box-check
    @tracked overlayExitButtonTitle = 'Cancel';
    @tracked metadataButtons = [
        {
            type: 'default',
            text: 'Add Metafield',
            icon: 'plus',
            iconPrefix: 'fas',
            onClick: this.addMetaField,
        },
    ];

    @tracked acceptedFileTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/x-flv', 'video/x-ms-wmv'];

    @action reset() {
        this.product = this.store.createRecord('product', { store_uuid: this.activeStore.id, currency: this.activeStore.currency });
        this.uploadQueue = [];
        this.uploadedFiles = [];
    }

    @task *saveProduct() {
        const loader = this.loader.showLoader('body', { loadingMessage: 'Creating new product...' });
        const { category } = this.productsIndexCategoryController;
        if (category) {
            this.product.set('category_uuid', category.id);
        }

        try {
            yield this.product.serializeMeta().save();
        } catch (error) {
            this.loader.removeLoader(loader);
            return this.notifications.serverError(error);
        }

        this.loader.removeLoader(loader);
        this.notifications.success(this.intl.t('storefront.products.index.new.new-product-created-success'));

        try {
            yield this.transitionToRoute('products.index.category', category.slug);
        } catch (error) {
            this.notifications.serverError(error);
        }

        this.reset();
    }

    @action addTag(tag) {
        if (!isArray(this.product.tags)) {
            this.product.tags = [];
        }

        this.product.tags.pushObject(tag);
    }

    @action removeTag(index) {
        this.product.tags?.removeAt(index);
    }

    @action queueFile(file) {
        // since we have dropzone and upload button within dropzone validate the file state first
        // as this method can be called twice from both functions
        if (['queued', 'failed', 'timed_out', 'aborted'].indexOf(file.state) === -1) {
            return;
        }

        // Queue and upload immediatley
        this.uploadQueue.pushObject(file);
        this.fetch.uploadFile.perform(
            file,
            {
                path: `uploads/storefront/${this.activeStore.id}/products`,
                subject_uuid: this.product.id,
                subject_type: 'storefront:product',
                type: 'storefront_product',
            },
            (uploadedFile) => {
                this.product.files.pushObject(uploadedFile);

                // if no primary image make it
                if (!this.product.primary_image_uuid) {
                    this.product.primary_image_uuid = uploadedFile.id;
                }

                this.uploadQueue.removeObject(file);
            },
            () => {
                this.uploadQueue.removeObject(file);
                // remove file from queue
                if (file.queue && typeof file.queue.remove === 'function') {
                    file.queue.remove(file);
                }
            }
        );
    }

    @action removeFile(file) {
        if (file.queue) {
            file.queue.remove(file);
        }

        if (file.model) {
            this.uploadedFiles.removeObject(file.model);
            file.model.destroyRecord();
        }

        this.uploadQueue.removeObject(file);
    }

    @action makePrimaryFile(file) {
        if (file.isNotImage) {
            return this.notifications.warning(this.intl.t('storefront.products.index.new.warning-only-select-an-image-file-to-be-primary'));
        }

        this.notifications.success(this.intl.t('storefront.products.index.new.made-the-primary-success-image', { fileName: file.original_filename }));
        this.product.primary_image_uuid = file.id;
        this.product.primary_image_url = file.url;
        this.product.primary_image = file;
    }

    @action transitionBack({ closeOverlay }) {
        if (this.product.hasDirtyAttributes) {
            // details have been added warn user it will lost
            return this.modalsManager.confirm({
                title: this.intl.t('storefront.products.index.new.title'),
                body: this.intl.t('storefront.products.index.new.body'),
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
            return this.transitionToRoute('products.index.category').then(() => {
                this.reset();
            });
        });
    }

    @task *promptSelectAddonCategories() {
        const addonCategories = yield this.store.findAll('addon-category');
        const selectedAddonCategories = this.product.addon_categories;
        this.modalsManager.show('modals/select-addon-category', {
            title: this.intl.t('storefront.products.index.new.select-addon-categories'),
            selectedAddonCategories,
            addonCategories,
            updateProductAddonCategories: (addonCategories) => {
                this.product.syncProductAddonCategories(addonCategories);
            },
        });
    }

    // @action selectAddonCategory() {
    //     this.store.findAll('addon-category')
    //     const addonCategories = await this.store.findAll('addon-category');

    //     await this.modalsManager.done();
    //     this.modalsManager.show('modals/select-addon-category', {
    //         title: this.intl.t('storefront.products.index.new.select-addon-categories'),
    //         addonCategories,
    //         selectedAddonCategories: this.product.addon_categories,
    //         updateProductAddonCategories: (addonCategories) => {
    //             this.product.syncProductAddonCategories(addonCategories);
    //         },
    //     });
    // }

    @action createProductVariant() {
        const { product } = this;
        const productVariant = this.store.createRecord('product-variant');

        return this.modalsManager.show('modals/create-new-variant', {
            title: this.intl.t('storefront.products.index.new.add-new-product-variant'),
            productVariant,
            confirm: (modal) => {
                modal.startLoading();
                // add variant to product
                product.variants.pushObject(productVariant);
                modal.done();
            },
        });
    }

    @action editProductVariant(productVariant) {
        return this.modalsManager.show('modals/create-new-variant', {
            title: this.intl.t('storefront.products.index.new.edit-product-variant'),
            productVariant,
        });
    }

    @action removeProductVariant(productVariant) {
        return this.crud.delete(productVariant, {
            onConfirm: () => {
                this.product.variants.removeObject(productVariant);
            },
        });
    }

    @action addVariantOption(productVariant) {
        const variantOption = this.store.createRecord('product-variant-option');
        productVariant.options.pushObject(variantOption);
    }

    @action removeVariantOption(productVariant, index) {
        const option = productVariant.options.objectAt(index);
        productVariant.options.removeObject(option);

        if (option.id) {
            option.destroyRecord();
        }
    }

    @action removeAddonCategory(index) {
        const productAddonCategory = this.product.addon_categories.objectAt(index);

        this.product.addon_categories.removeObject(productAddonCategory);

        if (productAddonCategory.id) {
            productAddonCategory.destroyRecord();
        }
    }

    @action excludeAddon(index, addon) {
        const productAddonCategory = this.product.addon_categories.objectAt(index);
        const id = addon.id;

        if (!productAddonCategory.excluded_addons) {
            productAddonCategory.excluded_addons = [];
        }

        if (productAddonCategory.excluded_addons.includes(id)) {
            productAddonCategory.excluded_addons.removeObject(id);
        } else {
            productAddonCategory.excluded_addons.pushObject(id);
        }
    }

    @action addMetaField() {
        let { meta_array } = this.product;

        if (!isArray(meta_array)) {
            meta_array = [];
        }

        const label = this.intl.t('storefront.products.index.new.untitled-field');

        meta_array.pushObject({
            key: underscore(label),
            label,
            value: null,
        });

        this.product.meta_array = meta_array;
    }

    @action removeMetaField(index) {
        this.product.meta_array.removeAt(index);
    }
}
