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
            ['storefront-metrics-widget', 'storefront-orders-widget', 'storefront-customers-widget']
        );
        assert.true(storefrontRegistration.widgets.every((widget) => widget.default));
        assert.deepEqual(
            dashboardRegistration.widgets.map((widget) => widget.id),
            ['storefront-key-metrics-widget']
        );
    });
});
