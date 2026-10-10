import Service, { inject as service } from '@ember/service';
import isModel from '@fleetbase/ember-core/utils/is-model';

/**
 * Create, edit and delete a network's store categories through the shared modal.
 * Used by the network Categories page and by the Stores page's assign-category flows.
 */
export default class NetworkCategoriesService extends Service {
    @service store;
    @service fetch;
    @service intl;
    @service modalsManager;
    @service notifications;

    /** A new category owned by the network, or nested under `parentCategory`. */
    create(network, parentCategory = null, options = {}) {
        const attrs = { owner_uuid: network.id, owner_type: 'storefront:network', for: 'storefront_network' };

        if (isModel(parentCategory)) {
            attrs.parent_uuid = parentCategory.id;
            attrs.owner_uuid = parentCategory.owner_uuid;
        }

        const category = this.store.createRecord('category', attrs);
        const { onCreated, ...modalOptions } = options;

        return this.edit(network, category, {
            title: this.intl.t('storefront.networks.index.network.stores.add-new-network-category'),
            acceptButtonIcon: 'check',
            acceptButtonText: this.intl.t('storefront.networks.index.network.stores.create-new-category'),
            parentCategory,
            confirm: (modal) => {
                modal.startLoading();

                return category
                    .save()
                    .then((saved) => {
                        this.notifications.success(this.intl.t('storefront.networks.index.network.stores.network-category-create'));
                        onCreated?.(saved);
                        modal.done();
                    })
                    .catch((error) => {
                        modal.stopLoading();
                        this.notifications.serverError(error);
                    });
            },
            ...modalOptions,
        });
    }

    edit(network, category, options = {}) {
        const { onSaved, successMessage, ...modalOptions } = options;

        return this.modalsManager.show('modals/create-network-category', {
            title: this.intl.t('storefront.networks.index.network.stores.edit-category', { categoryName: category.name }),
            acceptButtonText: this.intl.t('storefront.networks.index.network.stores.save-change'),
            acceptButtonIcon: 'save',
            iconType: category.icon_file_uuid ? 'image' : 'svg',
            network,
            category,
            parentCategory: null,
            setParentCategory: (parentCategory) => {
                this.modalsManager.setOption('parentCategory', parentCategory);
                category.setProperties({ parent_uuid: parentCategory?.id ?? null });
            },
            clearImage: () => {
                category.setProperties({ icon_file_uuid: null, icon_url: null, icon_file: null });
            },
            uploadIcon: (file) => {
                this.fetch.uploadFile.perform(
                    file,
                    { path: `uploads/${category.company_uuid}/icons/${category.slug}`, key_uuid: category.id, key_type: 'category', type: 'category_icon' },
                    (uploadedFile) => {
                        category.setProperties({ icon_file_uuid: uploadedFile.id, icon_url: uploadedFile.url, icon_file: uploadedFile });
                    }
                );
            },
            confirm: (modal) => {
                modal.startLoading();

                return category
                    .save()
                    .then((saved) => {
                        this.notifications.success(successMessage ?? this.intl.t('storefront.networks.categories.saved'));
                        onSaved?.(saved);
                        modal.done();
                    })
                    .catch((error) => {
                        modal.stopLoading();
                        this.notifications.serverError(error);
                    });
            },
            ...modalOptions,
        });
    }

    /** Removes the category; the stores inside move to the top level. */
    remove(network, category, options = {}) {
        return this.modalsManager.confirm({
            title: this.intl.t('storefront.networks.index.network.stores.delete-network-category'),
            body: this.intl.t('storefront.networks.index.network.stores.deleting-category-move-all-stores-inside-on-top-level'),
            acceptButtonText: this.intl.t('storefront.common.delete'),
            acceptButtonScheme: 'danger',
            confirm: (modal) => {
                modal.startLoading();

                return this.fetch
                    .delete(`networks/${network.id}/remove-category`, { category: category.id }, { namespace: 'storefront/int/v1' })
                    .then(() => {
                        this.notifications.success(this.intl.t('storefront.networks.categories.deleted'));
                        options.onDeleted?.(category);
                        modal.done();
                    })
                    .catch((error) => {
                        modal.stopLoading();
                        this.notifications.serverError(error);
                    });
            },
        });
    }
}
