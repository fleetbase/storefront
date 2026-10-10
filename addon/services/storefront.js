import Service from '@ember/service';
import Evented from '@ember/object/evented';
import { inject as service } from '@ember/service';
import { get } from '@ember/object';
import { getOwner } from '@ember/application';
import { tracked } from '@glimmer/tracking';

/**
 * Service to manage storefront operations.
 */
export default class StorefrontService extends Service.extend(Evented) {
    @service store;
    @service intl;
    @service fetch;
    @service notifications;
    @service currentUser;
    @service modalsManager;
    @service abilities;
    @service socket;
    @tracked activeStoreId;
    @tracked activeNetworkId;
    @tracked pendingInvitations = [];

    get hostRouter() {
        const owner = getOwner(this);

        return owner.hasRegistration('service:hostRouter') ? owner.lookup('service:hostRouter') : null;
    }

    /**
     * Gets the active store.
     * @returns {Object|null} The active store object.
     */
    get activeStore() {
        const activeStoreId = this.activeStoreId ?? this.currentUser.getOption('activeStorefront');

        return activeStoreId ? this.store.peekRecord('store', activeStoreId) : null;
    }

    /**
     * Gets the active network. A network is active only while the user is inside a
     * network page: the context is derived from the route, not persisted.
     * @returns {Object|null} The active network record.
     */
    get activeNetwork() {
        return this.activeNetworkId ? (this.store.peekRecord('network', this.activeNetworkId) ?? null) : null;
    }

    /**
     * Whether the console is scoped to a network instead of a store.
     * @returns {boolean}
     */
    get isNetworkContext() {
        return Boolean(this.activeNetwork);
    }

    /**
     * The active context type: a store or a network, never both at once.
     * @returns {'store'|'network'}
     */
    get activeContextType() {
        return this.isNetworkContext ? 'network' : 'store';
    }

    /**
     * The record the console is currently scoped to.
     * @returns {Object|null} the active network, otherwise the active store
     */
    get activeContext() {
        return this.activeNetwork ?? this.activeStore;
    }

    /**
     * Sets the active storefront.
     * @param {Object} store - The store to set as active.
     */
    setActiveStorefront(store) {
        this.currentUser.setOption('activeStorefront', store.id);
        this.activeStoreId = store.id;
        this.trigger('storefront.changed', store);
    }

    /**
     * Enters the network context for a network, or leaves it when given nothing.
     * @param {Object|null} network - The network to scope the console to.
     */
    setActiveNetwork(network = null) {
        const nextNetworkId = network?.id ?? null;

        if ((this.activeNetworkId ?? null) === nextNetworkId) {
            return;
        }

        this.activeNetworkId = nextNetworkId;
        this.trigger('storefront.context.changed', this.activeContextType, this.activeContext);
    }

    /**
     * Leaves the network context and returns to the active store.
     */
    clearActiveNetwork() {
        this.setActiveNetwork(null);
    }

    /**
     * Gets the active network or a specific property of it.
     * @param {string|null} property - The property to retrieve from the active network.
     * @returns {Object|null} The active network or its specific property.
     */
    getActiveNetwork(property = null) {
        if (this.activeNetwork) {
            if (typeof property === 'string') {
                return get(this.activeNetwork, property);
            }

            return this.activeNetwork;
        }

        return null;
    }

    /**
     * Gets the active store or a specific property of it.
     * @param {string|null} property - The property to retrieve from the active store.
     * @returns {Object|null} The active store or its specific property.
     */
    getActiveStore(property = null) {
        if (this.activeStore) {
            if (typeof property === 'string') {
                return get(this.activeStore, property);
            }

            return this.activeStore;
        }

        return null;
    }

    /**
     * Finds the active store based on the current user's settings.
     * @returns {Object|null} The active store object or null if not found.
     */
    findActiveStore() {
        return this.activeStore;
    }

    /**
     * Synchronizes active storefront state after the store collection has loaded.
     * All tracked writes happen here instead of inside render-time getters.
     *
     * @param {Array|Object|null} stores loaded store collection
     * @returns {Object|null} the selected store
     */
    synchronizeActiveStore(stores = this.store.peekAll('store')) {
        const activeStoreId = this.activeStoreId ?? this.currentUser.getOption('activeStorefront');
        const activeStore = activeStoreId ? this.store.peekRecord('store', activeStoreId) : null;
        const firstStore = stores?.firstObject ?? Array.from(stores ?? [])[0] ?? null;
        const nextStore = activeStore ?? firstStore;
        const nextStoreId = nextStore?.id;

        this.currentUser.setOption('activeStorefront', nextStoreId);
        this.activeStoreId = nextStoreId;

        return nextStore;
    }

    /**
     * Alerts about an incoming order.
     * @param {string} orderId - The ID of the incoming order.
     * @param {Object} store - The store associated with the order.
     */
    async alertIncomingOrder(orderId, store) {
        const order = await this.store.queryRecord('order', {
            public_id: orderId,
            single: true,
            with: ['customer', 'payload', 'trackingNumber'],
        });

        this.playAlert();
        this.trigger('order.incoming', order, store);

        const alreadyAccepted = !['created', 'pending'].includes(order.status);

        this.modalsManager.show('modals/incoming-order', {
            title: this.intl.t('storefront.service.storefront.new-incoming-order'),
            acceptButtonText: alreadyAccepted ? this.intl.t('common.ok') : this.intl.t('storefront.service.storefront.accept-order'),
            acceptButtonIcon: 'check',
            acceptButtonIconPrefix: 'fas',
            hideDeclineButton: alreadyAccepted,
            declineButtonText: this.intl.t('storefront.service.storefront.decline-order'),
            declineButtonScheme: 'danger',
            closeButton: false,
            backdropClose: false,
            modalClass: 'scrollable-height-dialog',
            order,
            store,
            confirm: async (modal) => {
                modal.startLoading();

                if (alreadyAccepted) return modal.done();

                try {
                    await this.fetch.post('orders/accept', { order: order.id }, { namespace: 'storefront/int/v1' });
                    this.trigger('order.accepted', order);
                    modal.done();
                } catch (error) {
                    modal.stopLoading();
                    this.notifications.serverError(error);
                }
            },
        });
    }

    /**
     * Listens for incoming orders and handles them.
     */
    async listenForIncomingOrders() {
        const store = this.findActiveStore();

        if (!store) {
            return;
        }

        // create socketcluster client
        const socket = this.socket.instance();

        // listen on company channel
        const channel = socket.subscribe(`storefront.${store.public_id}`);

        // listen to channel for events
        await channel.listener('subscribe').once();

        // get incoming data and console out
        for await (let broadcast of channel) {
            if (broadcast.event === 'order.created') {
                console.log('[new order]', broadcast);
                this.trigger('order.broadcasted', broadcast);
                this.alertIncomingOrder(broadcast.data.id, store);
            }
        }

        // disconnect when transitioning
        this.hostRouter?.on('routeWillChange', channel.close);
    }

    /**
     * Creates the first store with given options.
     * @param {Object} [options={}] - Options for creating the first store.
     */
    createFirstStore(options = {}) {
        const store = this.store.createRecord('store');
        const currency = this.currentUser.getWhoisProperty('currency.code');

        if (currency) {
            store.setProperties({ currency });
        }

        this.modalsManager.show('modals/create-first-store', {
            title: this.intl.t('storefront.service.storefront.create-first-storefront'),
            acceptButtonIcon: 'check',
            acceptButtonIconPrefix: 'fas',
            acceptButtonDisabled: this.abilities.cannot('storefront create store'),
            closeButton: false,
            backdropClose: false,
            keyboard: true,
            hideDeclineButton: false,
            declineButtonDisabled: false,
            declineButtonIcon: 'times',
            declineButtonIconPrefix: 'fas',
            store,
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await store.save();
                    this.notifications.success(this.intl.t('storefront.service.storefront.storefront-has-been-create-success'));
                    this.setActiveStorefront(store);
                    modal.done();
                } catch (error) {
                    modal.stopLoading();
                    this.notifications.serverError(error);
                }
            },
            decline: () => {
                this.hostRouter?.transitionTo('console');
            },
            ...options,
        });
    }

    /**
     * Creates a new storefront with given options.
     * @param {Object} [options={}] - Options for creating the new storefront.
     */
    createNewStorefront(options = {}) {
        const store = this.store.createRecord('store');
        const currency = this.currentUser.getWhoisProperty('currency.code');

        if (currency) {
            store.setProperties({ currency });
        }

        this.modalsManager.show('modals/create-store', {
            title: this.intl.t('storefront.service.storefront.create-new-storefront'),
            acceptButtonIcon: 'check',
            acceptButtonIconPrefix: 'fas',
            declineButtonIcon: 'times',
            declineButtonIconPrefix: 'fas',
            store,
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await store.save();
                    this.notifications.success(this.intl.t('storefront.service.storefront.storefront-create-success'));
                    // this.currentUser.setOption('activeStorefront', store.id);
                    this.setActiveStorefront(store);
                    if (typeof options?.onSuccess === 'function') {
                        options.onSuccess(store);
                    }
                    modal.done();
                } catch (error) {
                    modal.stopLoading();
                    this.notifications.serverError(error);
                }
            },
            ...options,
        });
    }

    /**
     * Open network invitations addressed to the active store, for the dashboard banner and the switcher badge.
     */
    async loadPendingInvitations() {
        const storeId = this.getActiveStore('public_id');

        if (!storeId) {
            this.pendingInvitations = [];
            return [];
        }

        try {
            const invitations = await this.fetch.get('networks/invitations/pending', { storefront: storeId }, { namespace: 'storefront/int/v1' });
            this.pendingInvitations = Array.isArray(invitations) ? invitations : [];
        } catch {
            this.pendingInvitations = [];
        }

        return this.pendingInvitations;
    }

    /**
     * Creates a new network with given options.
     * @param {Object} [options={}] - Options for creating the network; `onSuccess(network)` runs after it saves.
     */
    createNewNetwork(options = {}) {
        const network = this.store.createRecord('network');
        const currency = this.currentUser.getWhoisProperty('currency.code');

        if (currency) {
            network.setProperties({ currency });
        }

        this.modalsManager.show('modals/create-network', {
            title: this.intl.t('storefront.networks.index.create-new-network'),
            acceptButtonIcon: 'check',
            acceptButtonIconPrefix: 'fas',
            declineButtonIcon: 'times',
            declineButtonIconPrefix: 'fas',
            network,
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await network.save();
                    this.notifications.success(this.intl.t('storefront.networks.index.success-message'));
                    if (typeof options?.onSuccess === 'function') {
                        options.onSuccess(network);
                    }
                    modal.done();
                } catch (error) {
                    modal.stopLoading();
                    this.notifications.serverError(error);
                }
            },
            decline: (modal) => {
                network.destroyRecord();
                modal.done();
            },
            ...options,
        });
    }

    /**
     * Plays an alert sound.
     */
    playAlert() {
        // eslint-disable-next-line no-undef
        const alert = new Audio('/sounds/storefront_order_alert.mp3');

        try {
            alert.play();
        } catch (error) {
            // do nothing with error
        }
    }
}
