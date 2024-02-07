import Engine from '@ember/engine';
import loadInitializers from 'ember-load-initializers';
import Resolver from 'ember-resolver';
import config from './config/environment';
import services from '@fleetbase/ember-core/exports/services';
import StorefrontKeyMetricsWidget from './components/widget/storefront-key-metrics';

const { modulePrefix } = config;
const externalRoutes = ['console', 'extensions'];

export default class StorefrontEngine extends Engine {
    modulePrefix = modulePrefix;
    Resolver = Resolver;
    dependencies = {
        services,
        externalRoutes,
    };
    setupExtension = function (app, engine, universe) {
        // register menu item in header
        universe.registerHeaderMenuItem('Storefront', 'console.storefront', { icon: 'store', priority: 1 });

        // widgets for registry
        const KeyMetricsWidgetDefinition = {
            widgetId: 'storefront-key-metrics-widget',
            name: 'Storefront Metrics',
            description: 'Key metrics from Storefront.',
            icon: 'store',
            component: StorefrontKeyMetricsWidget,
            grid_options: { w: 12, h: 7, minW: 8, minH: 7 },
            options: {
                title: 'Storefront Metrics',
            },
        };

        // register widgets
        universe.registerDefaultDashboardWidgets([KeyMetricsWidgetDefinition]);
        universe.registerDashboardWidgets([KeyMetricsWidgetDefinition]);
    };
}

loadInitializers(StorefrontEngine, modulePrefix);
