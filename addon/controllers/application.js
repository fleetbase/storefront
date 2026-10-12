import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class ApplicationController extends Controller {
    @service storefront;
    @service hostRouter;
    @service loader;
    @service fetch;
    @service intl;
    @service abilities;
    @service store;
    /**
     * Native getters so the sidebar re-renders when the service's tracked ids change;
     * a computed alias onto a native getter never invalidates.
     */
    get activeStore() {
        return this.storefront.activeStore;
    }

    get activeNetwork() {
        return this.storefront.activeNetwork;
    }

    get isNetworkContext() {
        return Boolean(this.activeNetwork?.id);
    }

    /**
     * The sidebar is rewritten for the active context: a network gets its own
     * navigation, everything else is scoped to the active store.
     */
    get navigationItems() {
        return this.isNetworkContext ? this.networkNavigationItems : this.storeNavigationItems;
    }

    get networkNavigationItems() {
        const publicId = this.activeNetwork.public_id;
        const networkItem = (key, item) => {
            const route = `console.storefront.networks.index.network.${key}`;

            return {
                id: `network:${publicId}:${key}`,
                ...item,
                // These routes share one dynamic segment, so route-name matching alone cannot tell networks apart.
                activeWhen: () => this.hostRouter.isActive(route, publicId),
                onClick: () => this.hostRouter.transitionTo(route, publicId),
            };
        };

        return [
            networkItem('index', {
                label: this.intl.t('storefront.networks.index.network.overview'),
                description: this.intl.t('storefront.sidebar.descriptions.network-overview'),
                icon: 'gauge-high',
                permission: 'storefront view network',
                keywords: ['overview', 'dashboard', 'network'],
            }),
            networkItem('stores', {
                label: this.intl.t('storefront.networks.index.network.stores.store'),
                description: this.intl.t('storefront.sidebar.descriptions.network-stores'),
                icon: 'store',
                badge: this.networkPendingCount || null,
                permission: 'storefront view network',
                keywords: ['stores', 'members', 'invite', 'categories'],
            }),
            networkItem('trucks', {
                label: this.intl.t('storefront.common.food-trucks'),
                description: this.intl.t('storefront.sidebar.descriptions.network-trucks'),
                icon: 'truck',
                permission: 'storefront list food-truck',
                visible: this.can('storefront see food-truck'),
                keywords: ['vehicles', 'mobile store', 'truck'],
            }),
            networkItem('orders', {
                label: this.intl.t('storefront.networks.index.network.orders'),
                description: this.intl.t('storefront.sidebar.descriptions.network-orders'),
                icon: 'file-invoice-dollar',
                permission: 'storefront list order',
                visible: this.can('storefront see order'),
                keywords: ['fulfillment', 'deliveries', 'checkout orders'],
            }),
            networkItem('customers', {
                label: this.intl.t('storefront.networks.index.network.customers'),
                description: this.intl.t('storefront.sidebar.descriptions.network-customers'),
                icon: 'users',
                permission: 'storefront list user',
                visible: this.can('storefront see user'),
                keywords: ['contacts', 'buyers', 'users'],
            }),
            networkItem('categories', {
                label: this.intl.t('storefront.networks.categories.title'),
                description: this.intl.t('storefront.sidebar.descriptions.network-categories'),
                icon: 'folder-tree',
                permission: 'storefront view network',
                keywords: ['categories', 'groups', 'sections'],
            }),
            networkItem('promotions', {
                label: this.intl.t('storefront.sidebar.promotions'),
                description: this.intl.t('storefront.sidebar.descriptions.network-promotions'),
                icon: 'bullhorn',
                permission: 'storefront view promotions',
                visible: this.can('storefront see promotions'),
                keywords: ['discounts', 'coupons', 'promo codes', 'campaigns', 'segments', 'redemptions'],
            }),
            networkItem('settings', {
                label: this.intl.t('storefront.networks.index.network.settings'),
                description: this.intl.t('storefront.sidebar.descriptions.network-settings'),
                icon: 'cog',
                permission: 'storefront update network',
                keywords: ['settings', 'general', 'gateways', 'notifications'],
            }),
        ];
    }

    get storeNavigationItems() {
        const hasActiveStore = Boolean(this.activeStore?.id);

        return [
            {
                label: this.intl.t('storefront.sidebar.dashboard'),
                description: this.intl.t('storefront.sidebar.descriptions.dashboard'),
                icon: 'home',
                route: 'console.storefront.home',
                disabled: false,
                keywords: ['dashboard', 'overview', 'metrics', 'sales'],
            },
            {
                label: this.intl.t('storefront.sidebar.orders'),
                description: this.intl.t('storefront.sidebar.descriptions.orders'),
                icon: 'file-invoice-dollar',
                route: 'console.storefront.orders',
                badge: this.ordersToConfirm || null,
                permission: 'storefront list order',
                visible: this.can('storefront see order'),
                disabled: !hasActiveStore,
                keywords: ['fulfillment', 'deliveries', 'checkout orders'],
            },
            {
                label: this.intl.t('storefront.sidebar.products'),
                description: this.intl.t('storefront.sidebar.descriptions.products'),
                icon: 'box',
                permission: 'storefront list product',
                visible: this.can('storefront see product'),
                disabled: !hasActiveStore,
                // One entry: the products page carries its own category sidebar.
                route: 'console.storefront.products',
                keywords: ['inventory', 'items', 'sku', 'categories', 'create product', 'add product'],
            },
            {
                label: this.intl.t('storefront.sidebar.catalogs'),
                description: this.intl.t('storefront.sidebar.descriptions.catalogs'),
                icon: 'book-open',
                route: 'console.storefront.catalogs',
                permission: 'storefront list catalog',
                visible: this.can('storefront see catalog'),
                disabled: !hasActiveStore,
                keywords: ['menus', 'catalogs', 'published catalog'],
            },
            {
                label: this.intl.t('storefront.sidebar.customers'),
                description: this.intl.t('storefront.sidebar.descriptions.customers'),
                icon: 'users',
                route: 'console.storefront.customers',
                permission: 'storefront list user',
                visible: this.can('storefront see user'),
                disabled: !hasActiveStore,
                keywords: ['contacts', 'buyers', 'users'],
            },
            {
                label: this.intl.t('storefront.sidebar.food-trucks'),
                description: this.intl.t('storefront.sidebar.descriptions.trucks'),
                icon: 'truck',
                route: 'console.storefront.food-trucks',
                permission: 'storefront list food-truck',
                visible: this.can('storefront see food-truck'),
                disabled: !hasActiveStore,
                keywords: ['vehicles', 'mobile store', 'truck'],
            },
            {
                label: this.intl.t('storefront.sidebar.promotions'),
                description: this.intl.t('storefront.sidebar.descriptions.promotions'),
                icon: 'bullhorn',
                route: 'console.storefront.promotions',
                permission: 'storefront view promotions',
                visible: this.can('storefront see promotions'),
                disabled: !hasActiveStore,
                keywords: ['discounts', 'coupons', 'promo codes', 'campaigns', 'segments', 'push notifications', 'redemptions'],
            },
            {
                label: this.intl.t('storefront.sidebar.settings'),
                description: this.intl.t('storefront.sidebar.descriptions.settings'),
                icon: 'cogs',
                route: 'console.storefront.settings',
                permission: 'storefront view settings',
                visible: this.can('storefront see settings'),
                disabled: !hasActiveStore,
                keywords: ['settings', 'general', 'locations', 'gateways', 'payments', 'notifications', 'api', 'hours', 'checkout'],
            },
            {
                label: this.intl.t('storefront.sidebar.launch-app'),
                description: this.intl.t('storefront.sidebar.descriptions.launch-app'),
                icon: 'rocket',
                url: 'https://github.com/fleetbase/storefront-app',
                target: '_github',
                keywords: ['launch app', 'storefront app', 'github'],
            },
        ];
    }

    /**
     * Store context: the networks the active store belongs to, as cross-links.
     * Clicking one enters that network's context.
     */
    get memberOfItems() {
        if (this.isNetworkContext) {
            return [];
        }

        const memberships = this.activeStore?.networks ?? [];

        return memberships.filter((network) => network?.public_id).map((network) => ({ id: network.public_id, name: network.name, icon: 'network-wired', network }));
    }

    @action selectMemberNetwork(item) {
        return this.switchActiveNetwork(item.network);
    }

    /** Invitations and memberships waiting on the operator, shown on the network's Stores item. */
    get networkPendingCount() {
        const invitations = this.activeNetwork?.invitations ?? [];

        return invitations.filter((invitation) => ['pending', 'awaiting_approval'].includes(invitation?.status)).length;
    }

    /** Orders waiting to be confirmed, shown as the Orders badge. */
    get ordersToConfirm() {
        return Number(this.storefront.attention?.orders_to_confirm ?? 0);
    }

    get activeStoreUuid() {
        return this.activeStore?.id;
    }

    can(permission) {
        try {
            return this.abilities.can(permission);
        } catch (_) {
            return true;
        }
    }

    @action createNewStorefront() {
        return this.storefront.createNewStorefront({
            onSuccess: () => {
                const loader = this.loader.show({ loadingMessage: 'Switching to newly created store...' });

                this.hostRouter.refresh().then(() => {
                    this.loader.removeLoader(loader);
                });
            },
        });
    }

    @action createNewNetwork() {
        return this.storefront.createNewNetwork({
            onSuccess: (network) => this.switchActiveNetwork(network),
        });
    }

    @action switchActiveStore(store) {
        const loader = this.loader.show({ loadingMessage: `Switching Storefront to ${store.name}...` });
        const leavingNetwork = this.isNetworkContext;
        this.storefront.setActiveStorefront(store);
        // A page inside a network has no meaning for a store, so the switch lands on the store dashboard;
        // leaving the network route is what clears the network context.
        const transition = leavingNetwork ? this.hostRouter.transitionTo('console.storefront.home') : this.hostRouter.refresh();

        return Promise.resolve(transition).finally(() => {
            this.loader.removeLoader(loader);
        });
    }

    @action switchActiveNetwork(network) {
        // Entering the network route is what sets the network context.
        return this.hostRouter.transitionTo('console.storefront.networks.index.network.stores', network.public_id);
    }

    @action
    async searchNavigation({ query, limit = 12 }) {
        const trimmedQuery = query?.trim();

        if (!trimmedQuery || !this.activeStore?.public_id) {
            return [];
        }

        try {
            const response = await this.fetch.get(
                'search',
                {
                    query: trimmedQuery,
                    limit,
                    storefront: this.activeStore.public_id,
                },
                { namespace: 'storefront/int/v1' }
            );

            return response.results ?? [];
        } catch (_) {
            return [];
        }
    }
}
