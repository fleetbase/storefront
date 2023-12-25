import BaseController from '@fleetbase/storefront-engine/controllers/base-controller';
import { inject as controller } from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { isArray } from '@ember/array';
import { action } from '@ember/object';
import { alias } from '@ember/object/computed';
import { underscore } from '@ember/string';
import { inject as service } from '@ember/service';

export default class ProductsIndexCategoryNewController extends BaseController {
    @controller('products.index.category') productsIndexCategoryController;
    @service notifications;
    @service modalsManager;
    @service currentUser;
    @service store;
    @service storefront;
    @service fetch;
    @service loader;
    @service crud;
    @alias('storefront.activeStore') activeStore;
    @tracked product = this.store.createRecord('product', { store_uuid: this.activeStore.id, currency: this.activeStore.currency, tags: [], meta_array: [] });
    @tracked uploadQueue = [];
    @tracked uploadedFiles = [];
    @tracked primaryFile = null;
    @tracked isSaving = false;

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

    @action saveProduct() {
        const { category } = this.productsIndexCategoryController;
        const loader = this.loader.showLoader('body', { loadingMessage: 'Creating new product...' });
        this.isSaving = true;

        if (category) {
            this.product.set('category_uuid', category.id);
        }

        this.product
            .serializeMeta()
            .save()
            .then((product) => {
                this.loader.removeLoader(loader);
                this.isSaving = false;
                this.notifications.success('New product created successfully!');

                return this.transitionToRoute('products.index.category').then(() => {
                    console.log(this.productsIndexCategoryController);
                    console.log(this.productsIndexCategoryController.products);
                    this.productsIndexCategoryController?.products?.pushObject(product);
                });
            })
            .catch((error) => {
                this.loader.removeLoader(loader);
                this.isSaving = false;
                this.notifications.serverError(error);
            });
    }

    @action addTag(tag) {
        if (!isArray(this.product.tags)) {
            this.product.tags = [];
        }

        this.product.tags?.pushObject(tag);
        console.log('this.product.tags', this.product.tags);
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

        this.uploadQueue.pushObject(file);
        this.fetch.uploadFile.perform(
            file,
            {
                path: `uploads/storefront/${this.activeStore.id}/products`,
                subject_uuid: this.product.id,
                subject_type: `storefront:product`,
                type: `storefront_product`,
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
            return this.notifications.warning('You can only select an image file to be primary!');
        }

        this.notifications.success(`${file.original_filename} was made the primary image.`);
        this.product.primary_image_uuid = file.id;
        this.product.primary_image_url = file.url;
        this.product.primary_image = file;
    }

    @action transitionBack({ closeOverlay }) {
        if (this.product.hasDirtyAttributes) {
            // details have been added warn user it will lost
            return this.modalsManager.confirm({
                title: 'Product is not saved!',
                body: 'Going back will cancel this product creation, if you continue all input fields will be cleared!',
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

    @action async selectAddonCategory() {
        this.modalsManager.displayLoader();

        const { product } = this;
        const addonCategories = await this.store.findAll('addon-category');

        return this.modalsManager.done().then(() => {
            this.modalsManager.show('modals/select-addon-category', {
                title: 'Select addon categories',
                addonCategories,
                product,
                updateProductAddonCategories: (categories) => {
                    const productAddonCategories = categories.map((category) => {
                        return this.store.createRecord('product-addon-category', {
                            product_uuid: product.id,
                            category_uuid: category.id,
                            name: category.name,
                            excluded_addons: [],
                            category,
                        });
                    });

                    product.addon_categories = productAddonCategories;
                },
            });
        });
    }

    @action createProductVariant() {
        const { product } = this;
        const productVariant = this.store.createRecord('product-variant');

        return this.modalsManager.show('modals/create-new-variant', {
            title: 'Add new product variant',
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
            title: 'Edit product variant',
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

        console.log(productAddonCategory);
    }

    @action addMetaField() {
        let { meta_array } = this.product;

        if (!isArray(meta_array)) {
            meta_array = [];
        }

        const label = `Untitled Field`;

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
