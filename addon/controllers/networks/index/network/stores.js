import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action, set } from '@ember/object';
import { isBlank } from '@ember/utils';
import { timeout } from 'ember-concurrency';
import { task } from 'ember-concurrency-decorators';
import createShareableLink from '../../../../utils/create-shareable-link';
import isEmail from '@fleetbase/ember-core/utils/is-email';
import isModel from '@fleetbase/ember-core/utils/is-model';

export default class NetworksIndexNetworkStoresController extends Controller {
    /**
     * Inject the `notifications` service
     *
     * @var {Service}
     * @memberof NetworksIndexNetworkStoresController
     */
    @service notifications;

    /**
     * Inject the `intl` service
     *
     * @var {Service}
     * @memberof NetworksIndexNetworkStoresController
     */
    @service intl;

    /**
     * Inject the `modals-manager` service
     *
     * @var {Service}
     * @memberof NetworksIndexNetworkStoresController
     */
    @service modalsManager;

    /**
     * Inject the `crud` service
     *
     * @var {Service}
     * @memberof NetworksIndexNetworkStoresController
     */
    @service crud;

    /**
     * Inject the `fetch` service
     *
     * @var {Service}
     * @memberof NetworksIndexNetworkStoresController
     */
    @service fetch;

    /**
     * Inject the `store` service
     *
     * @var {Service}
     * @memberof NetworksIndexNetworkStoresController
     */
    @service store;

    /**
     * Inject the `hostRouter` service
     *
     * @var {Service}
     * @memberof NetworksIndexNetworkStoresController
     */
    @service hostRouter;

    /**
     * Queryable parameters for this controller's model
     *
     * @var {Array}
     * @memberof NetworksIndexNetworkStoresController
     */
    queryParams = ['category', 'status', 'storeQuery'];

    /**
     * The current page of data being viewed
     *
     * @var {Integer}
     * @memberof NetworksIndexNetworkStoresController
     */
    @tracked page = 1;

    /**
     * The maximum number of items to show per page
     *
     * @var {Integer}
     */
    @tracked limit;

    /**
     * The search query
     *
     * @var {String}
     * @memberof NetworksIndexNetworkStoresController
     */
    @tracked storeQuery;

    /**
     * The param to sort the data on, the param with prepended `-` is descending
     *
     * @var {String}
     * @memberof NetworksIndexNetworkStoresController
     */
    @tracked sort;

    /**
     * The param to filter stores by category.
     *
     * @var {String}
     * @memberof NetworksIndexNetworkStoresController
     */
    @tracked category;

    /**
     * The current network.
     *
     * @var {NetworkModel}
     * @memberof NetworksIndexNetworkStoresController
     */
    @tracked network;

    /**
     * The loading state.
     *
     * @var {Boolean}
     * @memberof NetworksIndexNetworkStoresController
     */
    @tracked isLoading = false;

    /**
     * All columns applicable for network stores
     *
     * @var {Array}
     */
    @tracked columns = [
        {
            label: this.intl.t('storefront.networks.index.network.stores.title'),
            valuePath: 'name',
            width: '130px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
            showOnlineIndicator: true,
        },
        {
            label: this.intl.t('storefront.common.id'),
            valuePath: 'public_id',
            cellComponent: 'click-to-copy',
            width: '120px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('storefront.common.category'),
            valuePath: 'category.name',
            cellComponent: 'table/cell/base',
            width: '100px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('storefront.common.currency'),
            valuePath: 'currency',
            cellComponent: 'table/cell/base',
            width: '100px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('storefront.networks.index.network.stores.created-at'),
            valuePath: 'createdAtShort',
            sortParam: 'created_at',
            width: '100px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/date',
        },
        {
            label: '',
            cellComponent: 'table/cell/dropdown',
            ddButtonText: false,
            ddButtonIcon: 'ellipsis-h',
            ddButtonIconPrefix: 'fas',
            ddMenuLabel: 'Store Actions',
            cellClassNames: 'overflow-visible',
            wrapperClass: 'flex items-center justify-end mx-2',
            width: '50px',
            actions: [
                {
                    label: this.intl.t('storefront.networks.index.network.stores.view-store-details'),
                    fn: this.viewStoreDetails,
                },
                {
                    label: this.intl.t('storefront.networks.index.network.stores.edit-store'),
                    fn: this.editStore,
                },
                {
                    label: this.intl.t('storefront.networks.index.network.stotes.assign-category'),
                    fn: this.assignStoreToCategory,
                },
                {
                    separator: true,
                },
                {
                    label: this.intl.t('storefront.networks.index.network.stores.remove-store-from-network'),
                    fn: this.removeStore,
                },
            ],
            sortable: false,
            filterable: false,
            resizable: false,
            searchable: false,
        },
    ];

    /**
     * The search task.
     *
     * @void
     */
    @task({ restartable: true }) *search({ target: { value } }) {
        // if no query don't search
        if (isBlank(value)) {
            this.storeQuery = null;
            return;
        }

        // timeout for typing
        yield timeout(250);

        // reset page for results
        if (this.page > 1) {
            this.page = 1;
        }

        // update the query param
        this.storeQuery = value;
    }

    /**
     * Selects a category and assigns its ID to the current category property.
     * If the selected category is null, the category property is set to null.
     *
     * @action
     * @param {CategoryModel|null} selectedCategory - The selected category object containing the ID.
     */
    @action selectCategory(selectedCategory) {
        if (selectedCategory) {
            this.category = selectedCategory.id;
        } else {
            this.category = null;
        }
    }

    /**
     * Deletes a specified category and moves all stores inside to the top level.
     * A confirmation modal is displayed before deletion.
     *
     * @action
     * @param {CategoryModel} category - The category object containing the ID to be deleted.
     */
    @action deleteCategory(category) {
        this.modalsManager.confirm({
            title: this.intl.t('storefront.networks.index.network.stores.delete-network-category'),
            body: this.intl.t('storefront.networks.index.network.stores.deleting-category-move-all-stores-inside-on-top-level'),
            confirm: (modal) => {
                modal.startLoading();

                this.fetch.delete(`networks/${this.network.id}/remove-category`, { category: category.id }, { namespace: 'storefront/int/v1' }).then(() => {
                    this.categories.removeObject(category);
                    this.leaveCategory();
                    modal.done();
                });
            },
        });
    }

    /**
     * Displays a modal to assign a store to a category or create a new category.
     * Allows the user to select a category, create a new one, or confirm the assignment.
     *
     * @action
     * @param {StoreModel} store - The store object to be assigned to a category.
     * @param {Object} [options={}] - Additional options for the modal.
     */
    @action assignStoreToCategory(store, options = {}) {
        this.modalsManager.show('modals/add-store-to-category', {
            title: this.intl.t('storefront.networks.index.network.stores.add-store-to-category'),
            acceptButtonText: this.intl.t('storefront.networks.index.network.stores.save-change'),
            acceptButtonIcon: 'save',
            selectedCategory: null,
            network: this.network,
            onSelectCategory: (category) => {
                this.modalsManager.setOption('selectedCategory', category);
            },
            createNewCategory: (networkCategoriesPicker, parentCategory) => {
                this.modalsManager.done();

                return this.createNewCategory(networkCategoriesPicker, parentCategory, {
                    onFinish: () => {
                        return this.assignStoreToCategory(store);
                    },
                });
            },
            confirm: (modal) => {
                modal.startLoading();
                const selectedCategory = this.modalsManager.getOption('selectedCategory');

                if (selectedCategory) {
                    return this.addStoreToCategory(store, selectedCategory).then(() => {
                        this.notifications.success(`${store.name} category was changed to ${selectedCategory.name}`);
                        this.hostRouter.refresh();
                    });
                }

                modal.done();
            },
            ...options,
        });
    }

    /**
     * Sends a POST request to assign a store to a specified category.
     * The category and store IDs are sent in the request body.
     *
     * @action
     * @param {StoreModel} store - The store object containing the ID.
     * @param {CategoryModel} category - The category object containing the ID.
     * @returns {Promise} A promise that resolves when the request is complete.
     */
    @action addStoreToCategory(store, category) {
        return this.fetch.post(
            `networks/${this.network.id}/set-store-category`,
            {
                category: category.id,
                store: store.id,
            },
            { namespace: 'storefront/int/v1' }
        );
    }

    /**
     * Creates a new category with specified attributes and displays a modal for editing.
     * Allows the user to confirm the creation and save the category.
     *
     * @action
     * @param {NetworkCategoryPickerComponent} networkCategoriesPicker - Picker for network categories.
     * @param {ParentCategory} parentCategory - The parent category object, if any.
     * @param {Object} [options={}] - Additional options for the modal.
     * @returns {Promise} A promise that resolves when the category is created.
     */
    @action createNewCategory(networkCategoriesPicker, parentCategory, options = {}) {
        const categoryAttrs = {
            owner_uuid: this.network.id,
            owner_type: 'storefront:network',
            for: 'storefront_network',
        };

        if (isModel(parentCategory)) {
            categoryAttrs.parent_uuid = parentCategory.id;
            categoryAttrs.owner_uuid = parentCategory.owner_uuid;
        }

        const category = this.store.createRecord('category', categoryAttrs);

        return this.editCategory(category, {
            title: this.intl.t('storefront.networks.index.network.stores.add-new-network-category'),
            acceptButtonIcon: 'check',
            acceptButtonText: this.intl.t('storefront.networks.index.network.stores.create-new-category'),
            successMessage: this.intl.t('storefront.networks.index.network.stores.new-category-created'),
            parentCategory,
            category,
            confirm: (modal) => {
                modal.startLoading();

                category
                    .save()
                    .then((category) => {
                        this.notifications.success(this.intl.t('storefront.networks.index.network.stores.network-category-create'));
                        networkCategoriesPicker.categories.pushObject(category);
                        modal.done();
                    })
                    .catch((error) => {
                        this.notifications.serverError(error);
                    });
            },
            ...options,
        });
    }

    /**
     * Displays a modal to edit a specified category.
     * Allows the user to set or clear the parent category, upload an icon, and confirm the changes.
     *
     * @action
     * @param {CategoryModel} category - The category object to be edited.
     * @param {Object} [options={}] - Additional options for the modal.
     */
    @action editCategory(category, options = {}) {
        this.modalsManager.show('modals/create-network-category', {
            title: this.intl.t('storefront.networks.index.network.stores.edit-category', {categoryName: category.name}),
            acceptButtonText: this.intl.t('storefront.networks.index.network.stores.save-change'),
            acceptButtonIcon: 'save',
            iconType: category.icon_file_uuid ? 'image' : 'svg',
            network: this.network,
            category,
            parentCategory: null,
            setParentCategory: (parentCategory) => {
                this.modalsManager.setOption('parentCategory', parentCategory);

                // update on category
                category.setProperties({
                    parent_uuid: parentCategory.id,
                });
            },
            clearImage: () => {
                category.setProperties({
                    icon_file_uuid: null,
                    icon_url: null,
                    icon_file: null,
                });
            },
            uploadIcon: (file) => {
                this.fetch.uploadFile.perform(
                    file,
                    {
                        path: `uploads/${category.company_uuid}/icons/${category.slug}`,
                        key_uuid: category.id,
                        key_type: `category`,
                        type: `category_icon`,
                    },
                    (uploadedFile) => {
                        category.setProperties({
                            icon_file_uuid: uploadedFile.id,
                            icon_url: uploadedFile.url,
                            icon_file: uploadedFile,
                        });
                    }
                );
            },
            confirm: (modal) => {
                modal.startLoading();

                return category.save().then(() => {
                    this.notifications.success(options.successMessage ?? 'Category changes saved.');
                });
            },
            ...options,
        });
    }

    /**
     * Displays a loader and shows a modal to add stores to the network.
     * Allows the user to select stores, update the selection, and confirm the addition.
     *
     * @action
     * @returns {Promise} A promise that resolves when the stores are added.
     */
    @action async addStores() {
        this.modalsManager.displayLoader();

        const { network } = this;
        const stores = await this.store.findAll('store');
        const members = await network.loadStores();

        return this.modalsManager.done().then(() => {
            this.modalsManager.show('modals/add-stores-to-network', {
                title: this.intl.t('storefront.networks.index.network.stores.add-stores-to-network'),
                acceptButtonIcon: 'check',
                stores,
                members,
                selected: members.toArray(),
                network,
                updateSelected: (selected) => {
                    this.modalsManager.setOption('selected', selected);
                },
                confirm: (modal) => {
                    modal.startLoading();

                    const stores = modal.getOption('selected');
                    const allStores = modal.getOption('stores');
                    const remove = allStores.filter((store) => !stores.includes(store)); // stores to be removed

                    return network.addStores(stores, remove).then(() => {
                        return this.hostRouter.refresh().then(() => {
                            this.notifications.success(this.intl.t('storefront.networks.index.network.stores.network-stores-update'));
                        });
                    });
                },
            });
        });
    }

    /**
     * Displays a confirmation modal to remove a specified store from the network.
     * Allows the user to confirm the removal.
     *
     * @action
     * @param {StoreModel} store - The store object to be removed.
     */
    @action async removeStore(store) {
        this.modalsManager.confirm({
            title: this.intl.t('storefront.networks.index.network.stores.remove-this-store', {storeName: store.name, networkName: this.network.name}),
            body: this.intl.t('storefront.networks.index.network.stores.longer-findable-by-this-network'),
            acceptButtonIcon: 'check',
            acceptButtonIconPrefix: 'fas',
            declineButtonIcon: 'times',
            declineButtonIconPrefix: 'fas',
            confirm: (modal) => {
                modal.startLoading();

                this.fetch
                    .post(`networks/${this.network.id}/remove-stores`, { stores: [store.id] }, { namespace: 'storefront/int/v1' })
                    .then(() => {
                        this.stores.removeObject(store);
                        modal.done();
                    })
                    .catch((error) => {
                        modal.stopLoading();
                        this.notifications.serverError(error);
                    });
            },
        });
    }

    /**
     * Displays a modal to view the details of a specified store.
     * The modal includes the store's name and a "Done" button.
     *
     * @action
     * @param {StoreModel} store - The store object whose details are to be viewed.
     * @param {Object} [options={}] - Additional options for the modal.
     */
    @action viewStoreDetails(store, options = {}) {
        this.modalsManager.show('modals/store-details', {
            title: this.intl.t('storefront.networks.index.network.stores.viewing-storefront', {storeName: store.name}),
            acceptButtonText: this.intl.t('storefront.networks.index.network.stores.done'),
            hideDeclineButton: true,
            store,
            ...options,
        });
    }

    /**
     * Displays a modal to edit a specified store's details.
     * Allows the user to make changes and confirm to save them.
     *
     * @action
     * @param {StoreModel} store - The store object to be edited.
     * @param {Object} [options={}] - Additional options for the modal.
     */
    @action editStore(store, options = {}) {
        this.modalsManager.show('modals/store-form', {
            title: this.intl.t('storefront.networks.index.network.stores.editing-storefront', {storeName: store.name}),
            acceptButtonText: this.intl.t('storefront.networks.index.network.stores.save-change'),
            hideDeclineButton: true,
            store,
            confirm: (modal) => {
                modal.startLoading();

                console.log('saving store', store);

                return store
                    .save()
                    .then(() => {
                        this.notifications.success(`Changes to ${store.name} saved.`);
                    })
                    .catch((error) => {
                        console.error(error);
                        this.notifications.serverError(error);
                    });
            },
            ...options,
        });
    }

    /**
     * Displays a modal to invite stores to the network via shareable link or email invitations.
     * Allows the user to add or remove recipients, toggle the shareable link, and confirm the invitations.
     *
     * @action
     */
    @action invite() {
        const shareableLink = createShareableLink(`join/network/${this.network.public_id}`);

        this.modalsManager.show('modals/share-network', {
            title: this.intl.t('storefront.networks.index.network.stores.add-stores-to-network'),
            acceptButtonText: this.intl.t('storefront.networks.index.network.stores.send-invitations'),
            acceptButtonIcon: 'paper-plane',
            acceptButtonDisabled: true,
            shareableLink,
            recipients: [],
            network: this.network,
            addRecipient: (email) => {
                const recipients = this.modalsManager.getOption('recipients');
                recipients.pushObject(email);

                if (recipients.length === 0) {
                    this.modalsManager.setOption('acceptButtonDisabled', true);
                } else {
                    this.modalsManager.setOption('acceptButtonDisabled', false);
                }
            },
            removeRecipient: (index) => {
                const recipients = this.modalsManager.getOption('recipients');
                recipients.removeAt(index);

                if (recipients.length === 0) {
                    this.modalsManager.setOption('acceptButtonDisabled', true);
                } else {
                    this.modalsManager.setOption('acceptButtonDisabled', false);
                }
            },
            toggleShareableLink: (enabled) => {
                set(this.network, 'options.shareable_link_enabled', enabled);
                this.network.save();
            },
            confirm: (modal) => {
                modal.startLoading();

                const recipients = modal.getOption('recipients');
                const isValid = recipients.every((email) => isEmail(email));

                if (!isValid) {
                    modal.stopLoading();

                    return this.notifications.error(this.intl.t('storefront.networks.index.network.stores.invalid-emails-provided-error'));
                }

                return this.network.sendInvites(recipients).then(() => {
                    modal.stopLoading();
                    this.notifications.success(this.intl.t('storefront.networks.index.network.stores.invitation-sent-recipients'));
                });
            },
        });
    }
}
