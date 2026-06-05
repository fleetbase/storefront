import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';
import formatCurrency from '@fleetbase/ember-ui/utils/format-currency';
import getCurrency from '@fleetbase/ember-ui/utils/get-currency';
import formatMoney from '@fleetbase/ember-accounting/utils/format-money';

export default class WidgetRevenueTrendComponent extends Component {
    static widgetId = 'storefront-revenue-trend-widget';

    @service fetch;
    @service storefront;
    @service storefrontDashboard;

    @tracked data = null;
    @tracked error = null;

    constructor() {
        super(...arguments);
        this.load.perform();
        this.storefront.on('order.broadcasted', () => {
            this.load.perform();
        });
        this.storefront.on('storefront.changed', () => {
            this.load.perform();
        });
        this.storefrontDashboard.on('periodChanged', () => {
            this.load.perform();
        });
    }

    get storeId() {
        return this.storefront.activeStore?.public_id ?? this.storefront.activeStore?.id;
    }

    get queryParams() {
        return this.storefrontDashboard.withStore(this.storeId);
    }

    get formattedRevenue() {
        return formatCurrency(this.data?.summary?.revenue ?? 0, this.data?.summary?.currency ?? 'USD');
    }

    get currencyCode() {
        return this.data?.summary?.currency ?? this.storefront.activeStore?.currency ?? 'USD';
    }

    get currency() {
        return getCurrency(this.currencyCode) ?? getCurrency('USD');
    }

    get currencyDivisor() {
        const precision = Number(this.currency?.precision ?? 2);

        return precision > 0 ? 10 ** precision : 1;
    }

    get chartDatasets() {
        return (
            this.data?.datasets?.map((dataset) => {
                if (!this.isRevenueDataset(dataset)) {
                    return dataset;
                }

                return {
                    ...dataset,
                    data: dataset.data?.map((value) => this.normalizeRevenueValue(value)) ?? [],
                };
            }) ?? []
        );
    }

    isRevenueDataset(dataset) {
        return dataset?.label === 'Revenue' || dataset?.yAxisID === 'y';
    }

    normalizeRevenueValue(value) {
        const numericValue = Number(value);

        if (!Number.isFinite(numericValue)) {
            return value;
        }

        return numericValue / this.currencyDivisor;
    }

    formatChartCurrency(value) {
        const numericValue = Number(value);

        if (!Number.isFinite(numericValue)) {
            return value;
        }

        return formatMoney(numericValue, this.currency.symbol, this.currency.precision, this.currency.thousandSeparator, this.currency.decimalSeparator);
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
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    callbacks: {
                        label: (context) => {
                            if (this.isRevenueDataset(context.dataset)) {
                                return `Revenue: ${this.formatChartCurrency(context.parsed?.y ?? context.raw)}`;
                            }

                            return `${context.dataset?.label ?? 'Value'}: ${context.parsed?.y ?? context.raw}`;
                        },
                    },
                },
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
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: this.currency.precision,
                        font: { size: 10 },
                        callback: (value) => this.formatChartCurrency(value),
                    },
                },
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
}
