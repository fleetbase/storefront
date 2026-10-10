import BaseController from '@fleetbase/storefront-engine/controllers/base-controller';
import { tracked, cached } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action, set } from '@ember/object';
import { isBlank } from '@ember/utils';
import { timeout, task } from 'ember-concurrency';
import isModel from '@fleetbase/ember-core/utils/is-model';
import { buildIdentityStub } from '@fleetbase/fleetops-data/utils/identity-stub';
import { formatDistanceToNow } from 'date-fns';

export default class NetworksIndexNetworkStoresController extends BaseController {
    @service notifications;
    @service intl;
    @service modalsManager;
    @service crud;
    @service fetch;
    @service store;
    @service hostRouter;

    /**
     * Queryable parameters for this controller's model
     *
     * @var {Array}
     * @memberof NetworksIndexNetworkStoresController
     */
    queryParams = this.registeredQueryParams('network-store', ['category', 'status', 'storeQuery']);

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
     * The stores loaded.
     *
     * @memberof NetworksIndexNetworkStoresController
     */
    @tracked stores = [];

    /**
     * The category picker component context.
     *
     * @memberof NetworksIndexNetworkStoresController
     */
    @tracked categoryPicker;

    /**
     * The current category model instance.
     *
     * @memberof NetworksIndexNetworkStoresController
     */
    @tracked categoryModel;

    /**
     * Invitations this network has sent, loaded by the route.
     *
     * @var {Array}
     */
    @tracked invitations = [];

    /**
     * Which membership state the table shows: all, online, offline or invited.
     *
     * @var {String}
     */
    @tracked statusTab = 'all';

    /**
     * The table instance, for bulk actions on the selected rows.
     *
     * @var {Object}
     */
    @tracked table;

    /**
     * Whether the invite panel is open and which tab it starts on.
     */
    @tracked isInvitePanelOpen = false;
    @tracked invitePanelTab = 'email';

    get openInvitations() {
        return this.invitations
            .filter((invitation) => ['pending', 'declined', 'expired'].includes(invitation.status))
            .map((invitation) => ({ ...invitation, sentAgo: this.sentAgo(invitation) }));
    }

    get pendingInvitations() {
        return this.openInvitations.filter((invitation) => invitation.status === 'pending');
    }

    get members() {
        const members = this.model?.toArray?.() ?? Array.from(this.model ?? []);
        members.forEach((store) => set(store, 'status_label', store.online ? 'active' : 'offline'));

        return members;
    }

    get statusTabs() {
        const members = this.members;

        return [
            { id: 'all', label: this.intl.t('storefront.common.all'), count: members.length + this.openInvitations.length },
            { id: 'online', label: this.intl.t('storefront.common.active'), count: members.filter((store) => store.online).length },
            { id: 'offline', label: this.intl.t('storefront.common.offline'), count: members.filter((store) => !store.online).length },
            { id: 'invited', label: this.intl.t('storefront.networks.invitations.invited'), count: this.openInvitations.length },
        ].map((tab) => ({ ...tab, isActive: tab.id === this.statusTab }));
    }

    get visibleStores() {
        switch (this.statusTab) {
            case 'online':
                return this.members.filter((store) => store.online);
            case 'offline':
                return this.members.filter((store) => !store.online);
            case 'invited':
                return this.invitationRows;
            default:
                return [...this.members, ...this.invitationRows];
        }
    }

    /**
     * Open invitations shown as rows of the same table: an invited store is a member in waiting.
     */
    get invitationRows() {
        return this.openInvitations.map((invitation) => ({
            ...invitation,
            isInvitation: true,
            name: invitation.email,
            status_label: invitation.status === 'pending' ? 'invited' : invitation.status,
            orders_7d: null,
            share: null,
            currency: null,
            createdAtShort: null,
        }));
    }

    get selectedStores() {
        return (this.table?.selectedRows ?? []).filter((row) => !row.isInvitation);
    }

    get hasSelection() {
        return this.selectedStores.length > 0;
    }

    sentAgo(invitation) {
        const sentAt = invitation.resent_at ?? invitation.created_at;

        return sentAt ? formatDistanceToNow(new Date(sentAt), { addSuffix: true }) : null;
    }

    /**
     * All columns applicable for network stores
     *
     * @var {Array}
     */
    @tracked columns = [
        {
            id: 'name',
            label: this.intl.t('storefront.networks.index.network.stores.store'),
            valuePath: 'name',
            cellComponent: 'table/cell/identity',
            resourceType: 'store',
            resourcePath: (row) => (row.isInvitation ? buildIdentityStub(row, { type: 'store', nameKey: 'email', icon: 'at' }) : row),
            popover: true,
            width: '160px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
            showOnlineIndicator: true,
            cellClassNames: 'network-store-name-column',
        },
        {
            id: 'category-name',
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
            id: 'status',
            label: this.intl.t('storefront.common.status'),
            valuePath: 'status_label',
            cellComponent: 'table/cell/status',
            width: '110px',
            sortable: false,
            filterable: false,
            resizable: true,
        },
        {
            id: 'orders-7d',
            label: this.intl.t('storefront.networks.index.network.stores.orders-7d'),
            valuePath: 'orders_7d',
            cellComponent: 'table/cell/base',
            width: '100px',
            sortable: true,
            filterable: false,
            resizable: true,
        },
        {
            id: 'share',
            label: this.intl.t('storefront.networks.index.network.stores.share'),
            valuePath: 'share',
            cellComponent: 'storefront/network/share-cell',
            width: '150px',
            sortable: true,
            filterable: false,
            resizable: true,
        },
        {
            id: 'currency',
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
            id: 'created-at-short',
            label: this.intl.t('storefront.networks.index.network.stores.joined'),
            valuePath: 'createdAtShort',
            sortParam: 'created_at',
            width: '100px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/date',
        },
        {
            id: 'row-actions',
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
                    id: 'resend-invitation',
                    label: this.intl.t('storefront.networks.invitations.resend'),
                    fn: this.resendInvitation,
                    isVisible: (row) => row.isInvitation && row.status === 'pending',
                },
                {
                    id: 'invite-again',
                    label: this.intl.t('storefront.networks.invitations.invite-again'),
                    fn: this.resendInvitation,
                    isVisible: (row) => row.isInvitation && row.status !== 'pending',
                },
                {
                    id: 'revoke-invitation',
                    label: this.intl.t('storefront.networks.invitations.revoke'),
                    fn: this.revokeInvitation,
                    isVisible: (row) => row.isInvitation && row.status === 'pending',
                },
                {
                    id: 'view-store-details',
                    label: this.intl.t('storefront.networks.index.network.stores.view-store-details'),
                    fn: this.viewStoreDetails,
                    isVisible: (row) => !row.isInvitation,
                },
                {
                    id: 'edit-store',
                    label: this.intl.t('storefront.networks.index.network.stores.edit-store'),
                    fn: this.editStore,
                    isVisible: (row) => !row.isInvitation,
                },
                {
                    id: 'assign-store-to-category',
                    label: this.intl.t('storefront.networks.index.network.stores.assign-category'),
                    fn: this.assignStoreToCategory,
                    isVisible: (row) => !row.isInvitation,
                },
                {
                    id: 'remove-store-category',
                    label: this.intl.t('storefront.networks.index.network.stores.remove-category'),
                    fn: this.removeStoreCategory,
                    isVisible: (row) => !row.isInvitation && row.category,
                },
                {
                    separator: true,
                    isVisible: (row) => !row.isInvitation,
                },
                {
                    id: 'remove-store',
                    label: this.intl.t('storefront.networks.index.network.stores.remove-store-from-network'),
                    fn: this.removeStore,
                    isVisible: (row) => !row.isInvitation,
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

    @action setCategoryPickerContext(context) {
        this.categoryPicker = context;
    }

    /**
     * Selects a category and assigns its ID to the current category property.
     * If the selected category is null, the category property is set to null.
     *
     * @action
     * @param {CategoryModel|null} selectedCategory - The selected category object containing the ID.
     */
    @action selectCategory(selectedCategory) {
        this.categoryModel = selectedCategory;

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
     * Displays a confirmation modal to remove a specified store from the network.
     * Allows the user to confirm the removal.
     *
     * @action
     * @param {StoreModel} store - The store object to be removed.
     */
    @action async removeStoreCategory(store) {
        this.modalsManager.confirm({
            title: this.intl.t('storefront.networks.index.network.stores.remove-store-category'),
            body: this.intl.t('storefront.networks.index.network.stores.remove-store-category-body', { storeName: store.name, categoryName: store.category?.get('name') }),
            acceptButtonIcon: 'check',
            acceptButtonIconPrefix: 'fas',
            declineButtonIcon: 'times',
            declineButtonIconPrefix: 'fas',
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await this.fetch.post(`networks/${this.network.id}/remove-store-category`, { store: store.id }, { namespace: 'storefront/int/v1' });
                    await this.hostRouter.refresh();
                    modal.done();
                } catch (error) {
                    modal.stopLoading();
                    this.notifications.serverError(error);
                }
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
            title: this.intl.t('storefront.networks.index.network.stores.edit-category', { categoryName: category.name }),
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
            title: this.intl.t('storefront.networks.index.network.stores.remove-this-store', { storeName: store.name, networkName: this.network.name }),
            body: this.intl.t('storefront.networks.invitations.remove-body'),
            acceptButtonText: this.intl.t('storefront.networks.index.network.stores.remove-store-from-network'),
            acceptButtonScheme: 'danger',
            acceptButtonIcon: 'trash',
            acceptButtonIconPrefix: 'fas',
            declineButtonIcon: 'times',
            declineButtonIconPrefix: 'fas',
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await this.fetch.post(`networks/${this.network.id}/remove-stores`, { stores: [store.id] }, { namespace: 'storefront/int/v1' });
                    await this.hostRouter.refresh();
                    modal.done();
                } catch (error) {
                    modal.stopLoading();
                    this.notifications.serverError(error);
                }
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
            title: this.intl.t('storefront.networks.index.network.stores.viewing-storefront', { storeName: store.name }),
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
            title: this.intl.t('storefront.networks.index.network.stores.editing-storefront', { storeName: store.name }),
            acceptButtonText: this.intl.t('storefront.networks.index.network.stores.save-change'),
            hideDeclineButton: true,
            store,
            confirm: (modal) => {
                modal.startLoading();

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
    @action invite(network, tab = 'email') {
        if (isModel(network) && network !== this.network) {
            this.network = network;
        }

        this.invitePanelTab = typeof tab === 'string' ? tab : 'email';
        this.isInvitePanelOpen = true;
    }

    @action closeInvitePanel() {
        this.isInvitePanelOpen = false;
    }

    @action selectStatusTab(tab) {
        this.statusTab = tab.id ?? tab;
    }

    @action async refreshInvitations() {
        try {
            this.invitations = await this.network.loadInvitations();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action async afterInvitationsSent() {
        await this.refreshInvitations();
        return this.hostRouter.refresh();
    }

    @action async resendInvitation(invitation) {
        try {
            await this.network.resendInvitation(invitation);
            this.notifications.success(this.intl.t('storefront.networks.invitations.resent', { email: invitation.email }));
            await this.refreshInvitations();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action revokeInvitation(invitation) {
        return this.modalsManager.confirm({
            title: this.intl.t('storefront.networks.invitations.revoke-title', { email: invitation.email }),
            body: this.intl.t('storefront.networks.invitations.revoke-body'),
            acceptButtonText: this.intl.t('storefront.networks.invitations.revoke'),
            acceptButtonScheme: 'danger',
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await this.network.revokeInvitation(invitation);
                    await this.refreshInvitations();
                    modal.done();
                } catch (error) {
                    modal.stopLoading();
                    this.notifications.serverError(error);
                }
            },
        });
    }

    /**
     * Assign one category to every selected store.
     */
    @action assignCategoryToSelected() {
        const stores = this.selectedStores;

        if (!stores.length) {
            return;
        }

        this.modalsManager.show('modals/add-store-to-category', {
            title: this.intl.t('storefront.networks.invitations.assign-category-count', { count: stores.length }),
            acceptButtonText: this.intl.t('storefront.networks.index.network.stores.assign-category'),
            acceptButtonIcon: 'check',
            network: this.network,
            store: stores[0],
            category: null,
            setCategory: (category) => this.modalsManager.setOption('category', category),
            confirm: async (modal) => {
                const category = modal.getOption('category');

                if (!category) {
                    return this.notifications.warning(this.intl.t('storefront.networks.invitations.pick-a-category'));
                }

                modal.startLoading();

                try {
                    for (const store of stores) {
                        await this.fetch.post(`networks/${this.network.id}/set-store-category`, { store: store.id, category: category.id }, { namespace: 'storefront/int/v1' });
                    }
                    await this.hostRouter.refresh();
                    modal.done();
                } catch (error) {
                    modal.stopLoading();
                    this.notifications.serverError(error);
                }
            },
        });
    }

    /**
     * Remove every selected store from the network.
     */
    @action removeSelected() {
        const stores = this.selectedStores;

        if (!stores.length) {
            return;
        }

        this.modalsManager.confirm({
            title: this.intl.t('storefront.networks.invitations.remove-count-title', { count: stores.length, networkName: this.network.name }),
            body: this.intl.t('storefront.networks.invitations.remove-body'),
            acceptButtonText: this.intl.t('storefront.networks.index.network.stores.remove-store-from-network'),
            acceptButtonScheme: 'danger',
            acceptButtonIcon: 'trash',
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await this.network.removeStores(stores);
                    await this.hostRouter.refresh();
                    modal.done();
                } catch (error) {
                    modal.stopLoading();
                    this.notifications.serverError(error);
                }
            },
        });
    }

    @action clearSelection() {
        this.selectedStores.forEach((row) => set(row, 'checked', false));
    }

    /**
     * The columns with what extensions registered under `storefront:network-store:table` merged in.
     *
     * @var {Array}
     */
    @cached get registeredColumns() {
        return this.mergeRegisteredColumns('network-store', this.columns);
    }

    /**
     * Toolbar buttons extensions registered under `storefront:network-store:table:actions`.
     *
     * @var {Array}
     */
    get registeredActionButtons() {
        return this.registeredTableActions('network-store');
    }
}
