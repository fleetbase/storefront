import { Widget, ExtensionComponent } from '@fleetbase/ember-core/contracts';

export default {
    setupExtension(app, universe) {
        const menuService = universe.getService('menu');
        const registryService = universe.getService('registry');
        const widgetService = universe.getService('widget');

        // Register menu item in header
        menuService.registerHeaderMenuItem('Storefront', 'console.storefront', { icon: 'store', priority: 1 });

        // widgets for registry
        const widgets = [
            new Widget({
                id: 'storefront-key-metrics-widget',
                name: 'Storefront Metrics',
                description: 'Key metrics from Storefront.',
                icon: 'store',
                component: new ExtensionComponent('@fleetbase/storefront-engine', 'widget/storefront-key-metrics'),
                grid_options: { w: 12, h: 7, minW: 8, minH: 7 },
                options: { title: 'Storefront Metrics' },
                default: true,
            }),
        ];

        widgetService.registerWidgets('dashboard', widgets);

        // register component to views
        registryService.registerRenderableComponent('fleet-ops:component:order:details', new ExtensionComponent('@fleetbase/storefront-engine', 'storefront-order-summary'));
        registryService.registerRenderableComponent(
            'fleet-ops:template:operations:orders:new:entities-input',
            new ExtensionComponent('@fleetbase/storefront-engine', 'add-product-as-entity-button')
        );
    },
};
