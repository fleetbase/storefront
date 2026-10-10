import BaseController from '@fleetbase/storefront-engine/controllers/base-controller';
import { inject as controller } from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { alias } from '@ember/object/computed';
import { dasherize } from '@ember/string';
import { isBlank } from '@ember/utils';
import { timeout, task } from 'ember-concurrency';

export default class ProductsIndexController extends BaseController {
    @controller('products.index.index') productsIndexIndexController;
    @controller('products.index.category') productsIndexCategoryController;
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
    @tracked viewMode = 'grid';
    @tracked summary = null;
    @tracked activeGroup = null;
    @tracked selected = [];

    groupFilters = {
        'out-of-stock': { available: false },
        drafts: { status: 'draft' },
        recommended: { recommended: true },
        'on-sale': { on_sale: true },
        uncategorized: { uncategorized: true },
    };

    filterKeys = ['status', 'available', 'on_sale', 'recommended', 'uncategorized'];

    get categoryCounts() {
        return this.summary?.by_category ?? {};
    }

    get statusTab() {
        const controller = this.activeProductsController;

        if (this.activeGroup) {
            return null;
        }

        if (controller?.status === 'published') return 'published';
        if (controller?.status === 'draft') return 'draft';
        if (controller?.on_sale) return 'on-sale';

        return 'all';
    }

    get statusTabs() {
        const summary = this.summary ?? {};

        return [
            { id: 'all', label: this.intl.t('storefront.common.all'), count: summary.total },
            { id: 'published', label: this.intl.t('storefront.products.statuses.published'), count: summary.published },
            { id: 'draft', label: this.intl.t('storefront.products.statuses.draft'), count: summary.draft },
            { id: 'on-sale', label: this.intl.t('storefront.products.groups.on-sale'), count: summary.on_sale },
        ].map((tab) => ({ ...tab, isActive: tab.id === this.statusTab }));
    }

    get selectedIds() {
        return new Set(this.selected.map((product) => product.id));
    }

    get hasSelection() {
        return this.selected.length > 0;
    }

    applyFilters(filters = {}) {
        const controller = this.activeProductsController;

        if (!controller) {
            return;
        }

        this.filterKeys.forEach((key) => {
            controller[key] = filters[key] ?? null;
        });
        controller.page = 1;
    }

    @action selectStatusTab(tab) {
        const id = tab.id ?? tab;
        this.activeGroup = null;

        switch (id) {
            case 'published':
                return this.applyFilters({ status: 'published' });
            case 'draft':
                return this.applyFilters({ status: 'draft' });
            case 'on-sale':
                return this.applyFilters({ on_sale: true });
            default:
                return this.applyFilters({});
        }
    }

    @action selectGroup(group) {
        const id = group.id ?? group;

        if (this.category) {
            this.category = null;
            this.transitionToRoute('products.index');
        }

        this.activeGroup = id;
        this.applyFilters(this.groupFilters[id] ?? {});
    }

    @action toggleSelect(product) {
        if (this.selectedIds.has(product.id)) {
            this.selected = this.selected.filter((item) => item.id !== product.id);
        } else {
            this.selected = [...this.selected, product];
        }
    }

    @action clearSelection() {
        this.selected = [];
    }

    async bulkUpdate(changes, successKey) {
        const products = this.selected;

        if (!products.length) {
            return;
        }

        try {
            await Promise.all(
                products.map((product) => {
                    product.setProperties(changes);
                    return product.save();
                })
            );
            this.notifications.success(this.intl.t(successKey, { count: products.length }));
            this.clearSelection();
            return this.hostRouter.refresh();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action bulkPublish() {
        return this.bulkUpdate({ status: 'published' }, 'storefront.products.bulk.published');
    }

    @action bulkUnpublish() {
        return this.bulkUpdate({ status: 'draft' }, 'storefront.products.bulk.unpublished');
    }

    @action bulkMarkOnSale() {
        return this.bulkUpdate({ is_on_sale: true }, 'storefront.products.bulk.on-sale');
    }

    @action bulkMoveToCategory() {
        const products = this.selected;
        const categories = this.model?.toArray?.() ?? Array.from(this.model ?? []);

        this.modalsManager.show('modals/add-store-to-category', {
            title: this.intl.t('storefront.products.bulk.move-title', { count: products.length }),
            acceptButtonText: this.intl.t('storefront.products.bulk.move'),
            acceptButtonIcon: 'folder-tree',
            categories,
            category: null,
            setCategory: (category) => this.modalsManager.setOption('category', category),
            confirm: async (modal) => {
                const category = modal.getOption('category');

                if (!category) {
                    return this.notifications.warning(this.intl.t('storefront.networks.invitations.pick-a-category'));
                }

                modal.startLoading();

                try {
                    await Promise.all(
                        products.map((product) => {
                            product.setProperties({ category, category_uuid: category.id });
                            return product.save();
                        })
                    );
                    this.notifications.success(this.intl.t('storefront.products.bulk.moved', { count: products.length, category: category.name }));
                    this.clearSelection();
                    modal.done();
                    return this.hostRouter.refresh();
                } catch (error) {
                    modal.stopLoading();
                    this.notifications.serverError(error);
                }
            },
        });
    }

    @action bulkDelete() {
        const products = this.selected;

        this.modalsManager.confirm({
            title: this.intl.t('storefront.products.bulk.delete-title', { count: products.length }),
            body: this.intl.t('storefront.products.index.body-warning'),
            acceptButtonScheme: 'danger',
            acceptButtonIcon: 'trash',
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await Promise.all(products.map((product) => product.destroyRecord()));
                    this.clearSelection();
                    modal.done();
                    return this.hostRouter.refresh();
                } catch (error) {
                    modal.stopLoading();
                    this.notifications.serverError(error);
                }
            },
        });
    }

    get activeProductsController() {
        return this.category ? this.productsIndexCategoryController : this.productsIndexIndexController;
    }

    get query() {
        return this.activeProductsController?.query;
    }

    @task({ restartable: true }) *search({ target: { value } }) {
        const controller = this.activeProductsController;

        if (!controller) {
            return;
        }

        if (isBlank(value)) {
            controller.query = null;
            return;
        }

        yield timeout(250);

        if (controller.page > 1) {
            controller.page = 1;
        }

        controller.query = value;
    }

    @action setViewMode(viewMode) {
        this.viewMode = viewMode;
    }

    @action createNewProduct() {
        return this.transitionToRoute('products.index.category.new');
    }

    /**
     * Toggles a dialog which allows user to manage addon categories and options.
     *
     * @memberof ProductsIndexController
     */
    @action manageAddons() {
        this.modalsManager.show('modals/manage-addons', {
            title: this.intl.t('storefront.products.index.manage-addons-dialog.manage-addons-title'),
            acceptButtonText: this.intl.t('storefront.products.index.manage-addons-dialog.manage-addons-accept-button'),
            acceptButtonIcon: 'save',
            modalClass: 'modal-lg',
            store: this.activeStore,
        });
    }

    @action viewAllProducts() {
        this.category = null;
        this.activeGroup = null;
        this.applyFilters({});
        this.transitionToRoute('products.index');
    }

    @action switchCategory(category) {
        this.category = category;
        this.activeGroup = null;
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
