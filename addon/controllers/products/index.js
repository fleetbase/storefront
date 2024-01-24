import BaseController from '@fleetbase/storefront-engine/controllers/base-controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { alias } from '@ember/object/computed';
import { dasherize } from '@ember/string';

export default class ProductsIndexController extends BaseController {
    @service store;
    @service modalsManager;
    @service currentUser;
    @service notifications;
    @service fetch;
    @service hostRouter;
    @service storefront;
    @service intl;

    /**
     * the current storefront store session.
     *
     * @memberof ProductsIndexController
     */
    @alias('storefront.activeStore') activeStore;

    /**
     * The current category.
     *
     * @var {CategoryModel}
     */
    @tracked category;

    @action createNewProduct() {
        return this.transitionToRoute('products.index.category.new');
    }

    @action manageAddons() {
        this.modalsManager.show('modals/manage-addons', {
            title: this.intl.t('storefront.products.index.aside-scroller.title'),
            modalClass: 'modal-lg',
            acceptButtonText: this.intl.t('storefront.products.index.done'),
            store: this.activeStore,
        });
    }

    @action viewAllProducts() {
        this.category = null;
        this.transitionToRoute('products.index');
    }

    @action switchCategory(category) {
        this.category = category;
        this.transitionToRoute('products.index.category', category.slug);
    }

    @action createNewProductCategory() {
        const category = this.store.createRecord('category', {
            company_uuid: this.currentUser.companyId,
            owner_uuid: this.currentUser.getOption('activeStorefront'),
            owner_type: 'storefront:store',
            for: 'storefront_product',
        });

        this.modalsManager.show('modals/create-product-category', {
            title: this.intl.t('storefront.products.index.create-new-product-category'),
            acceptButtonIcon: 'check',
            acceptButtonIconPrefix: 'fas',
            declineButtonIcon: 'times',
            declineButtonIconPrefix: 'fas',
            category,
            uploadNewPhoto: (file) => {
                this.fetch.uploadFile.perform(
                    file,
                    {
                        path: `uploads/${category.company_uuid}/product-category-icon/${dasherize(category.name ?? this.currentUser.companyId)}`,
                        subject_uuid: category.id,
                        subject_type: `category`,
                        type: `category_icon`,
                    },
                    (uploadedFile) => {
                        category.setProperties({
                            icon_file_uuid: uploadedFile.id,
                            icon_url: uploadedFile.url,
                            icon: uploadedFile,
                        });
                    }
                );
            },
            confirm: (modal) => {
                modal.startLoading();

                return category.save().then(() => {
                    this.notifications.success(this.intl.t('storefront.products.index.product-category-created-success'));
                    return this.hostRouter.refresh();
                });
            },
        });
    }

    @action importProducts() {
        const checkQueue = () => {
            const uploadQueue = this.modalsManager.getOption('uploadQueue');

            if (uploadQueue.length) {
                this.modalsManager.setOption('acceptButtonDisabled', false);
            } else {
                this.modalsManager.setOption('acceptButtonDisabled', true);
            }
        };

        this.modalsManager.show('modals/import-products', {
            title: this.intl.t('storefront.products.index.import-products-via-spreadsheets'),
            acceptButtonText: this.intl.t('storefront.products.index.start-upload'),
            acceptButtonScheme: 'magic',
            acceptButtonIcon: 'upload',
            acceptButtonDisabled: true,
            isProcessing: false,
            uploadQueue: [],
            selectedCategory: null,
            store: this.activeStore,
            fileQueueColumns: [
                { name: 'Type', valuePath: 'extension', key: 'type' },
                { name: 'File Name', valuePath: 'name', key: 'fileName' },
                { name: 'File Size', valuePath: 'size', key: 'fileSize' },
                { name: 'Upload Date', valuePath: 'blob.lastModifiedDate', key: 'uploadDate' },
                { name: '', valuePath: '', key: 'delete' },
            ],
            acceptedFileTypes: ['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/csv'],
            queueFile: (file) => {
                const uploadQueue = this.modalsManager.getOption('uploadQueue');

                uploadQueue.pushObject(file);
                checkQueue();
            },
            removeFile: (file) => {
                const { queue } = file;
                const uploadQueue = this.modalsManager.getOption('uploadQueue');

                uploadQueue.removeObject(file);
                queue.remove(file);
                checkQueue();
            },
            confirm: async (modal) => {
                const selectedCategory = this.modalsManager.getOption('selectedCategory');
                const uploadQueue = this.modalsManager.getOption('uploadQueue');
                const uploadedFiles = [];
                const uploadTask = (file) => {
                    return new Promise((resolve) => {
                        this.fetch.uploadFile.perform(
                            file,
                            {
                                path: `uploads/storefront-product-imports/${this.currentUser.companyId}`,
                                type: `storefront_order_import`,
                            },
                            (uploadedFile) => {
                                uploadedFiles.pushObject(uploadedFile);

                                resolve(uploadedFile);
                            }
                        );
                    });
                };

                if (!uploadQueue.length) {
                    return this.notifications.warning(this.intl.t('storefront.products.index.warning-no-file-upload'));
                }

                modal.startLoading();
                modal.setOption('acceptButtonText', 'Uploading...');

                for (let i = 0; i < uploadQueue.length; i++) {
                    const file = uploadQueue.objectAt(i);

                    await uploadTask(file);
                }

                this.modalsManager.setOption('acceptButtonText', 'Processing...');
                this.modalsManager.setOption('isProcessing', true);

                const files = uploadedFiles.map((file) => file.id);
                const results = await this.fetch
                    .post('products/process-imports', { files, category: selectedCategory?.id, store: this.activeStore.id }, { namespace: 'storefront/int/v1' })
                    .catch((error) => {
                        this.notifications.serverError(error);
                    });

                modal.done().then(() => {
                    if (results?.length) {
                        this.notifications.success(this.intl.t('storefront.products.index.import-products-success-message', { resultsLength: results.length }));
                        return this.hostRouter.refresh();
                    }
                });
            },
            decline: (modal) => {
                this.modalsManager.setOption('uploadQueue', []);
                this.fileQueue?.flush();

                modal.done();
            },
        });
    }
}
