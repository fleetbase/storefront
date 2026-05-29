import { module, test } from 'qunit';
import { registerWidgets } from '@fleetbase/storefront-engine/extension';

module('Unit | Extension', function () {
    test('registers storefront dashboard widgets', function (assert) {
        const dashboards = [];
        const widgetRegistrations = [];
        const widgetService = {
            registerDashboard(id) {
                dashboards.push(id);
            },
            registerWidgets(id, widgets) {
                widgetRegistrations.push({ id, widgets });
            },
        };

        registerWidgets(widgetService);

        const storefrontRegistration = widgetRegistrations.find((registration) => registration.id === 'storefront');
        const dashboardRegistration = widgetRegistrations.find((registration) => registration.id === 'dashboard');

        assert.deepEqual(dashboards, ['storefront']);
        assert.deepEqual(
            storefrontRegistration.widgets.map((widget) => widget.id),
            [
                'storefront-kpi-revenue-widget',
                'storefront-kpi-orders-widget',
                'storefront-kpi-aov-widget',
                'storefront-kpi-active-orders-widget',
                'storefront-kpi-completed-orders-widget',
                'storefront-kpi-customers-widget',
                'storefront-kpi-cart-conversion-widget',
                'storefront-kpi-cancellation-rate-widget',
                'storefront-revenue-trend-widget',
                'storefront-orders-by-status-widget',
                'storefront-top-products-widget',
                'storefront-customer-insights-widget',
                'storefront-metrics-widget',
                'storefront-orders-widget',
                'storefront-customers-widget',
            ]
        );
        assert.deepEqual(
            storefrontRegistration.widgets.filter((widget) => widget.default).map((widget) => widget.id),
            [
                'storefront-kpi-revenue-widget',
                'storefront-kpi-orders-widget',
                'storefront-kpi-aov-widget',
                'storefront-kpi-active-orders-widget',
                'storefront-kpi-completed-orders-widget',
                'storefront-kpi-customers-widget',
                'storefront-kpi-cart-conversion-widget',
                'storefront-kpi-cancellation-rate-widget',
                'storefront-revenue-trend-widget',
                'storefront-orders-by-status-widget',
                'storefront-top-products-widget',
                'storefront-customer-insights-widget',
                'storefront-orders-widget',
            ]
        );
        assert.deepEqual(storefrontRegistration.widgets.find((widget) => widget.id === 'storefront-revenue-trend-widget').grid_options, { w: 6, h: 10, minW: 5, minH: 9 });
        assert.deepEqual(storefrontRegistration.widgets.find((widget) => widget.id === 'storefront-orders-by-status-widget').grid_options, { w: 6, h: 9, minW: 5, minH: 8 });
        assert.deepEqual(storefrontRegistration.widgets.find((widget) => widget.id === 'storefront-top-products-widget').grid_options, { w: 6, h: 9, minW: 5, minH: 8 });
        assert.deepEqual(storefrontRegistration.widgets.find((widget) => widget.id === 'storefront-customer-insights-widget').grid_options, { w: 6, h: 9, minW: 5, minH: 8 });
        assert.deepEqual(
            dashboardRegistration.widgets.map((widget) => widget.id),
            ['storefront-key-metrics-widget']
        );
    });
});
