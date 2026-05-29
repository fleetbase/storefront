import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import formatCurrency from '@fleetbase/ember-ui/utils/format-currency';

const PERIODS = [
    { label: '7d', days: 7 },
    { label: '30d', days: 30 },
    { label: '90d', days: 90 },
];

export default class WidgetRevenueTrendComponent extends Component {
    static widgetId = 'storefront-revenue-trend-widget';

    @service fetch;
    @service storefront;

    @tracked data = null;
    @tracked error = null;
    @tracked period = PERIODS[1];

    periods = PERIODS;

    constructor() {
        super(...arguments);
        this.load.perform();
        this.storefront.on('order.broadcasted', () => {
            this.load.perform();
        });
        this.storefront.on('storefront.changed', () => {
            this.load.perform();
        });
    }

    get storeId() {
        return this.storefront.activeStore?.public_id ?? this.storefront.activeStore?.id;
    }

    get queryParams() {
        const end = new Date();
        const start = new Date();
        start.setDate(end.getDate() - (this.period.days - 1));

        return {
            store: this.storeId,
            start: start.toISOString().slice(0, 10),
            end: end.toISOString().slice(0, 10),
        };
    }

    get formattedRevenue() {
        return formatCurrency(this.data?.summary?.revenue ?? 0, this.data?.summary?.currency ?? 'USD');
    }

    get chartOptions() {
        return {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        usePointStyle: true,
                        pointStyle: 'circle',
                        boxWidth: 6,
                        boxHeight: 6,
                        padding: 10,
                        font: { size: 10, weight: '600' },
                    },
                },
                tooltip: { mode: 'index', intersect: false },
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: {
                        autoSkip: true,
                        maxTicksLimit: 6,
                        maxRotation: 0,
                        minRotation: 0,
                        font: { size: 10 },
                    },
                },
                y: { beginAtZero: true, ticks: { precision: 0, font: { size: 10 } } },
                y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, ticks: { precision: 0, font: { size: 10 } } },
            },
            elements: { point: { radius: 0, hoverRadius: 4 } },
        };
    }

    @task *load() {
        try {
            this.data = yield this.fetch.get('analytics/revenue-trend', this.queryParams, { namespace: 'storefront/int/v1' });
            this.error = null;
        } catch (error) {
            this.error = error?.message ?? 'Unable to load revenue trend';
        }
    }

    @action setPeriod(period) {
        this.period = period;
        this.load.perform();
    }
}
