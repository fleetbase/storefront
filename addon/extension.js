import { Widget, ExtensionComponent } from '@fleetbase/ember-core/contracts';

function createStorefrontKeyMetricsWidget() {
    return new Widget({
        id: 'storefront-key-metrics-widget',
        name: 'Storefront Metrics',
        description: 'Key metrics from Storefront.',
        icon: 'store',
        component: new ExtensionComponent('@fleetbase/storefront-engine', 'widget/storefront-key-metrics'),
        grid_options: { w: 12, h: 7, minW: 8, minH: 7 },
        options: { title: 'Storefront Metrics' },
        default: true,
    });
}

export function registerWidgets(widgetService) {
    widgetService.registerDashboard('storefront');

    widgetService.registerWidgets('storefront', [
        new Widget({
            id: 'storefront-metrics-widget',
            name: 'Storefront Metrics',
            description: 'Storefront order, customer, store, and earnings metrics.',
            icon: 'chart-line',
            component: new ExtensionComponent('@fleetbase/storefront-engine', 'widget/storefront-metrics'),
            grid_options: { w: 12, h: 4, minW: 8, minH: 4 },
            default: true,
        }),
        new Widget({
            id: 'storefront-orders-widget',
            name: 'Storefront Orders',
            description: 'Recent Storefront orders.',
            icon: 'bag-shopping',
            component: new ExtensionComponent('@fleetbase/storefront-engine', 'widget/orders'),
            grid_options: { w: 12, h: 10, minW: 8, minH: 8 },
            options: { wrapperClass: 'bordered-classic' },
            default: true,
        }),
        new Widget({
            id: 'storefront-customers-widget',
            name: 'Storefront Customers',
            description: 'Recent Storefront customers.',
            icon: 'users',
            component: new ExtensionComponent('@fleetbase/storefront-engine', 'widget/customers'),
            grid_options: { w: 12, h: 10, minW: 8, minH: 8 },
            options: { wrapperClass: 'bordered-classic' },
            default: true,
        }),
    ]);

    widgetService.registerWidgets('dashboard', [createStorefrontKeyMetricsWidget()]);
}

export default {
    setupExtension(app, universe) {
        const menuService = universe.getService('menu');
        const registryService = universe.getService('registry');
        const widgetService = universe.getService('widget');

        // Register menu item in header
        menuService.registerHeaderMenuItem('Storefront', 'console.storefront', {
            icon: 'store',
            priority: 1,
            description: 'Online store management: products, orders, customers, and promotions.',
            shortcuts: [
                {
                    title: 'Products',
                    description: 'Manage your product catalogue, categories, and inventory.',
                    icon: 'box-open',
                    route: 'console.storefront.products',
                },
                {
                    title: 'Orders',
                    description: 'View and fulfil incoming storefront orders.',
                    icon: 'bag-shopping',
                    route: 'console.storefront.orders',
                },
                {
                    title: 'Customers',
                    description: 'Browse and manage your storefront customer accounts.',
                    icon: 'users',
                    route: 'console.storefront.customers',
                },
                {
                    title: 'Networks',
                    description: 'Connect and manage multi-store networks and marketplaces.',
                    icon: 'network-wired',
                    route: 'console.storefront.networks',
                },
                {
                    title: 'Catalogs',
                    description: 'Organise products into shareable catalogs.',
                    icon: 'book-open',
                    route: 'console.storefront.catalogs',
                },
                {
                    title: 'Promotions',
                    description: 'Create push notifications and promotional campaigns.',
                    icon: 'bullhorn',
                    route: 'console.storefront.promotions',
                },
            ],
        });

        registerWidgets(widgetService);

        // register component to views
        registryService.registerRenderableComponent('fleet-ops:component:order:details', new ExtensionComponent('@fleetbase/storefront-engine', 'storefront-order-summary'));
        registryService.registerRenderableComponent(
            'fleet-ops:template:operations:orders:new:entities-input',
            new ExtensionComponent('@fleetbase/storefront-engine', 'add-product-as-entity-button')
        );
    },
};
