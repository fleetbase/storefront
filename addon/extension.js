import { Widget, ExtensionComponent } from '@fleetbase/ember-core/contracts';

function createStorefrontKeyMetricsWidget() {
    return new Widget({
        id: 'storefront-key-metrics-widget',
        name: 'Storefront Metrics (Legacy)',
        description: 'Legacy grouped Storefront metrics.',
        icon: 'store',
        component: new ExtensionComponent('@fleetbase/storefront-engine', 'widget/storefront-key-metrics'),
        grid_options: { w: 12, h: 7, minW: 8, minH: 7 },
        options: { title: 'Storefront Metrics' },
        category: 'Legacy',
        default: false,
    });
}

export function registerWidgets(widgetService) {
    widgetService.registerDashboard('storefront');

    widgetService.registerWidgets('storefront', [
        new Widget({
            id: 'storefront-kpi-revenue-widget',
            name: 'Revenue',
            description: 'Storefront revenue for the current period with trend.',
            icon: 'sack-dollar',
            component: new ExtensionComponent('@fleetbase/storefront-engine', 'widget/kpi-revenue'),
            grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
            category: 'KPI Tiles',
            default: true,
        }),
        new Widget({
            id: 'storefront-kpi-orders-widget',
            name: 'Orders',
            description: 'Order volume for the current period with trend.',
            icon: 'bag-shopping',
            component: new ExtensionComponent('@fleetbase/storefront-engine', 'widget/kpi-orders'),
            grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
            category: 'KPI Tiles',
            default: true,
        }),
        new Widget({
            id: 'storefront-kpi-aov-widget',
            name: 'Average Order Value',
            description: 'Average order value for non-canceled Storefront orders.',
            icon: 'receipt',
            component: new ExtensionComponent('@fleetbase/storefront-engine', 'widget/kpi-aov'),
            grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
            category: 'KPI Tiles',
            default: true,
        }),
        new Widget({
            id: 'storefront-kpi-active-orders-widget',
            name: 'Active Orders',
            description: 'Orders currently moving through fulfillment.',
            icon: 'bolt',
            component: new ExtensionComponent('@fleetbase/storefront-engine', 'widget/kpi-active-orders'),
            grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
            category: 'KPI Tiles',
            default: true,
        }),
        new Widget({
            id: 'storefront-kpi-completed-orders-widget',
            name: 'Completed Orders',
            description: 'Completed orders for the current period.',
            icon: 'circle-check',
            component: new ExtensionComponent('@fleetbase/storefront-engine', 'widget/kpi-completed-orders'),
            grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
            category: 'KPI Tiles',
            default: true,
        }),
        new Widget({
            id: 'storefront-kpi-customers-widget',
            name: 'Customers',
            description: 'Unique customers ordering during the current period.',
            icon: 'users',
            component: new ExtensionComponent('@fleetbase/storefront-engine', 'widget/kpi-customers'),
            grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
            category: 'KPI Tiles',
            default: true,
        }),
        new Widget({
            id: 'storefront-kpi-cart-conversion-widget',
            name: 'Cart Conversion',
            description: 'Orders as a percentage of carts created in the current period.',
            icon: 'cart-shopping',
            component: new ExtensionComponent('@fleetbase/storefront-engine', 'widget/kpi-cart-conversion'),
            grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
            category: 'KPI Tiles',
            default: true,
        }),
        new Widget({
            id: 'storefront-kpi-cancellation-rate-widget',
            name: 'Cancellation Rate',
            description: 'Canceled orders as a percentage of current period order volume.',
            icon: 'ban',
            component: new ExtensionComponent('@fleetbase/storefront-engine', 'widget/kpi-cancellation-rate'),
            grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
            category: 'KPI Tiles',
            default: true,
        }),
        new Widget({
            id: 'storefront-revenue-trend-widget',
            name: 'Revenue Trend',
            description: 'Revenue and order volume over time.',
            icon: 'chart-line',
            component: new ExtensionComponent('@fleetbase/storefront-engine', 'widget/revenue-trend'),
            grid_options: { w: 6, h: 10, minW: 5, minH: 9 },
            category: 'Analytics',
            default: true,
        }),
        new Widget({
            id: 'storefront-orders-by-status-widget',
            name: 'Order Status Mix',
            description: 'Distribution of Storefront orders by status.',
            icon: 'chart-column',
            component: new ExtensionComponent('@fleetbase/storefront-engine', 'widget/orders-by-status'),
            grid_options: { w: 6, h: 9, minW: 5, minH: 8 },
            category: 'Analytics',
            default: true,
        }),
        new Widget({
            id: 'storefront-top-products-widget',
            name: 'Top Products',
            description: 'Best-selling products by revenue.',
            icon: 'ranking-star',
            component: new ExtensionComponent('@fleetbase/storefront-engine', 'widget/top-products'),
            grid_options: { w: 6, h: 9, minW: 5, minH: 8 },
            category: 'Analytics',
            default: true,
        }),
        new Widget({
            id: 'storefront-customer-insights-widget',
            name: 'Customer Insights',
            description: 'New and returning customer mix.',
            icon: 'chart-pie',
            component: new ExtensionComponent('@fleetbase/storefront-engine', 'widget/customer-insights'),
            grid_options: { w: 6, h: 9, minW: 5, minH: 8 },
            category: 'Analytics',
            default: true,
        }),
        new Widget({
            id: 'storefront-metrics-widget',
            name: 'Storefront Metrics (Legacy)',
            description: 'Legacy Storefront order, customer, store, and earnings metrics.',
            icon: 'chart-line',
            component: new ExtensionComponent('@fleetbase/storefront-engine', 'widget/storefront-metrics'),
            grid_options: { w: 12, h: 4, minW: 8, minH: 4 },
            category: 'Legacy',
            default: false,
        }),
        new Widget({
            id: 'storefront-orders-widget',
            name: 'Storefront Orders',
            description: 'Recent Storefront orders.',
            icon: 'bag-shopping',
            component: new ExtensionComponent('@fleetbase/storefront-engine', 'widget/orders'),
            grid_options: { w: 12, h: 10, minW: 8, minH: 8 },
            options: { wrapperClass: 'bordered-classic' },
            category: 'Operations',
            default: true,
        }),
        new Widget({
            id: 'storefront-customers-widget',
            name: 'Storefront Customers',
            description: 'Recent Storefront customers.',
            icon: 'users',
            component: new ExtensionComponent('@fleetbase/storefront-engine', 'widget/customers'),
            grid_options: { w: 6, h: 6, minW: 5, minH: 5 },
            options: { wrapperClass: 'bordered-classic' },
            category: 'Operations',
            default: false,
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
