import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { tracked } from '@glimmer/tracking';

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
    @tracked productCategories = [];
    categoryLoadStoreUuid;

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
            networkItem('stores', {
                label: this.intl.t('storefront.networks.index.network.stores.store'),
                description: 'Stores selling through this network, their categories and invitations.',
                icon: 'store',
                permission: 'storefront view network',
                keywords: ['stores', 'members', 'invite', 'categories'],
            }),
            networkItem('trucks', {
                label: this.intl.t('storefront.common.food-trucks'),
                description: 'Trucks run by the stores in this network.',
                icon: 'truck',
                permission: 'storefront list food-truck',
                visible: this.can('storefront see food-truck'),
                keywords: ['vehicles', 'mobile store', 'truck'],
            }),
            networkItem('orders', {
                label: this.intl.t('storefront.networks.index.network.orders'),
                description: 'Orders placed through this network.',
                icon: 'file-invoice-dollar',
                permission: 'storefront list order',
                visible: this.can('storefront see order'),
                keywords: ['fulfillment', 'deliveries', 'checkout orders'],
            }),
            networkItem('customers', {
                label: this.intl.t('storefront.networks.index.network.customers'),
                description: 'Customers who ordered through this network.',
                icon: 'users',
                permission: 'storefront list user',
                visible: this.can('storefront see user'),
                keywords: ['contacts', 'buyers', 'users'],
            }),
            networkItem('index', {
                label: this.intl.t('storefront.networks.index.network.settings'),
                description: 'Network details, gateways and notification channels.',
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
                description: 'Storefront dashboard and sales overview.',
                icon: 'home',
                route: 'console.storefront.home',
                keywords: ['dashboard', 'overview', 'metrics', 'sales'],
            },
            {
                label: this.intl.t('storefront.sidebar.orders'),
                description: 'Manage incoming storefront orders.',
                icon: 'file-invoice-dollar',
                route: 'console.storefront.orders',
                permission: 'storefront list order',
                visible: this.can('storefront see order'),
                disabled: !hasActiveStore,
                keywords: ['fulfillment', 'deliveries', 'checkout orders'],
            },
            {
                label: this.intl.t('storefront.sidebar.products'),
                description: 'Manage products, categories, variants, and addons.',
                icon: 'box',
                permission: 'storefront list product',
                visible: this.can('storefront see product'),
                disabled: !hasActiveStore,
                children: [
                    {
                        label: 'All Products',
                        description: 'Browse and manage product inventory.',
                        icon: 'box',
                        route: 'console.storefront.products.index.index',
                        keywords: ['inventory', 'items', 'sku', 'create product', 'add product'],
                    },
                    ...this.productCategoryItems,
                ],
            },
            {
                label: this.intl.t('storefront.sidebar.catalogs'),
                description: 'Create and manage storefront catalogs.',
                icon: 'book-open',
                route: 'console.storefront.catalogs',
                permission: 'storefront list catalog',
                visible: this.can('storefront see catalog'),
                disabled: !hasActiveStore,
                keywords: ['menus', 'catalogs', 'published catalog'],
            },
            {
                label: this.intl.t('storefront.sidebar.customers'),
                description: 'Review storefront customers and order history.',
                icon: 'users',
                route: 'console.storefront.customers',
                permission: 'storefront list user',
                visible: this.can('storefront see user'),
                disabled: !hasActiveStore,
                keywords: ['contacts', 'buyers', 'users'],
            },
            {
                label: this.intl.t('storefront.sidebar.food-trucks'),
                description: 'Manage trucks, where they serve and what they sell.',
                icon: 'truck',
                route: 'console.storefront.food-trucks',
                permission: 'storefront list food-truck',
                visible: this.can('storefront see food-truck'),
                disabled: !hasActiveStore,
                keywords: ['vehicles', 'mobile store', 'truck'],
            },
            {
                label: this.intl.t('storefront.sidebar.promotions'),
                description: 'Discounts, promo codes, customer segments and campaigns.',
                icon: 'bullhorn',
                permission: 'storefront view promotions',
                visible: this.can('storefront see promotions'),
                disabled: !hasActiveStore,
                children: [
                    {
                        label: this.intl.t('storefront.promotions.list.tab-title'),
                        description: 'Create discounts and promo codes applied at checkout.',
                        icon: 'tags',
                        route: 'console.storefront.promotions.index',
                        keywords: ['discounts', 'coupons', 'promo codes', 'sales', 'deals'],
                    },
                    {
                        label: this.intl.t('storefront.promotions.campaigns.tab-title'),
                        description: 'Send scheduled notifications to customer segments.',
                        icon: 'bullhorn',
                        route: 'console.storefront.promotions.campaigns',
                        keywords: ['marketing', 'broadcast', 'notifications'],
                    },
                    {
                        label: this.intl.t('storefront.promotions.segments.tab-title'),
                        description: 'Group customers by their order history for targeting.',
                        icon: 'users',
                        route: 'console.storefront.promotions.segments',
                        keywords: ['audience', 'customers', 'targeting'],
                    },
                    {
                        label: 'Push Notifications',
                        description: 'Send push notifications to storefront customers.',
                        icon: 'bell',
                        route: 'console.storefront.promotions.push-notifications',
                        keywords: ['marketing', 'broadcast', 'campaigns'],
                    },
                ],
            },
            {
                label: this.intl.t('storefront.sidebar.settings'),
                description: 'Configure storefront settings, locations, gateways, and notifications.',
                icon: 'cogs',
                permission: 'storefront view settings',
                visible: this.can('storefront see settings'),
                disabled: !hasActiveStore,
                children: [
                    {
                        label: this.intl.t('storefront.common.general'),
                        description: 'General storefront settings.',
                        icon: 'cog',
                        route: 'console.storefront.settings.index',
                        keywords: ['settings', 'general'],
                    },
                    {
                        label: this.intl.t('storefront.common.location'),
                        description: 'Storefront pickup and service locations.',
                        icon: 'map-marker-alt',
                        route: 'console.storefront.settings.locations',
                        keywords: ['locations', 'places', 'pickup'],
                    },
                    {
                        label: this.intl.t('storefront.common.gateways'),
                        description: 'Payment gateway settings.',
                        icon: 'cash-register',
                        route: 'console.storefront.settings.gateways',
                        keywords: ['payments', 'stripe', 'qpay', 'checkout'],
                    },
                    {
                        label: this.intl.t('storefront.common.api'),
                        description: 'Storefront API settings.',
                        icon: 'code',
                        route: 'console.storefront.settings.api',
                        keywords: ['api', 'developer'],
                    },
                    {
                        label: this.intl.t('storefront.common.notification'),
                        description: 'Notification channel settings.',
                        icon: 'bell-concierge',
                        route: 'console.storefront.settings.notifications',
                        keywords: ['push notifications', 'apn', 'fcm'],
                    },
                ],
            },
            {
                label: this.intl.t('storefront.sidebar.launch-app'),
                description: 'Open the storefront application repository.',
                icon: 'rocket',
                url: 'https://github.com/fleetbase/storefront-app',
                target: '_github',
                keywords: ['launch app', 'storefront app', 'github'],
            },
        ];
    }

    get activeStoreUuid() {
        return this.activeStore?.id;
    }

    get productCategoryItems() {
        return this.productCategories
            .filter((category) => category?.slug)
            .map((category) => {
                const description = category.description || category.slug;
                const route = 'console.storefront.products.index.category';

                return {
                    id: `product-category:${category.id ?? category.slug}`,
                    label: category.name,
                    description,
                    icon: 'folder',
                    // Route-only matching cannot distinguish categories sharing a dynamic route.
                    activeWhen: () => this.hostRouter.isActive(route, category.slug),
                    onClick: () => this.hostRouter.transitionTo(route, category.slug),
                    keywords: ['category', 'collection', category.slug, category.name, description].filter(Boolean),
                };
            });
    }

    can(permission) {
        try {
            return this.abilities.can(permission);
        } catch (_) {
            return true;
        }
    }

    async loadProductCategories(storeUuid = this.activeStoreUuid) {
        const ownerUuid = storeUuid;
        this.categoryLoadStoreUuid = ownerUuid;

        if (!ownerUuid) {
            this.productCategories = [];
            return [];
        }

        try {
            const categories = await this.store.query('category', {
                for: 'storefront_product',
                owner_uuid: ownerUuid,
                limit: -1,
            });

            if (this.categoryLoadStoreUuid !== ownerUuid) {
                return this.productCategories;
            }

            this.productCategories = categories?.toArray?.() ?? Array.from(categories ?? []);
            return this.productCategories;
        } catch (_) {
            if (this.categoryLoadStoreUuid !== ownerUuid) {
                return this.productCategories;
            }

            this.productCategories = [];
            return [];
        }
    }

    @action createNewStorefront() {
        return this.storefront.createNewStorefront({
            onSuccess: () => {
                const loader = this.loader.show({ loadingMessage: 'Switching to newly created store...' });

                this.hostRouter.refresh().then(() => {
                    this.loadProductCategories(this.activeStoreUuid);
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
        this.productCategories = [];
        // A page inside a network has no meaning for a store, so the switch lands on the store dashboard;
        // leaving the network route is what clears the network context.
        const transition = leavingNetwork ? this.hostRouter.transitionTo('console.storefront.home') : this.hostRouter.refresh();

        return Promise.resolve(transition)
            .then(() => {
                return this.loadProductCategories(store.id);
            })
            .finally(() => {
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
