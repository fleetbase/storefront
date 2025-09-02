import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class WidgetStorefrontKeyMetricsComponent extends Component {
    /**
     * The widget ID to use for registering.
     *
     * @memberof WidgetFleetOpsKeyMetricsComponent
     */
    static widgetId = 'storefront-key-metrics-widget';

    /**
     * Inject the fetch service.
     *
     * @memberof WidgetKeyMetricsComponent
     */
    @service fetch;

    /**
     * Property for loading metrics to.
     *
     * @memberof WidgetKeyMetricsComponent
     */
    @tracked metrics = {};

    /**
     * Creates an instance of WidgetKeyMetricsComponent.
     * @memberof WidgetKeyMetricsComponent
     */
    constructor() {
        super(...arguments);
        this.getDashboardMetrics.perform();
    }

    /**
     * Task which fetches key metrics.
     *
     * @memberof WidgetKeyMetricsComponent
     */
    @task *getDashboardMetrics(params = {}) {
        this.metrics = yield this.fetch.get('metrics', params, { namespace: 'storefront/int/v1' }).then((response) => {
            return this.createMetricsMapFromResponse(response);
        });
    }

    /**
     * Creates a map of metrics from the response data. This method organizes the metrics data into a more usable format.
     *
     * @param {Object} metrics - The metrics object fetched from the server.
     * @returns {Object} A map of metrics where each key is a metric name and its value is an object of metric options.
     * @memberof WidgetKeyMetricsComponent
     */
    createMetricsMapFromResponse(metrics = {}) {
        const keys = Object.keys(metrics);
        const map = {};

        for (let i = 0; i < keys.length; i++) {
            const key = keys[i];
            map[key] = this.createMetricOptionsHash(key, metrics[key]);
        }

        return map;
    }

    /**
     * Creates a hash of options for a given metric. Depending on the metric key, it assigns a specific format.
     *
     * @param {string} key - The key representing the specific metric.
     * @param {number} value - The value of the metric.
     * @returns {Object} An object containing the metric value and its format.
     * @memberof WidgetKeyMetricsComponent
     */
    createMetricOptionsHash(key, value) {
        const options = { value };

        return options;
    }
}
