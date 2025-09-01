import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { get, action } from '@ember/object';
import { debug } from '@ember/debug';
import { startOfMonth, endOfMonth, format } from 'date-fns';
import { task } from 'ember-concurrency';
import { getDateRangeByLabel } from '../../utils/commerce-date-ranges';

export default class WidgetStorefrontMetricsComponent extends Component {
    @service fetch;
    @service storefront;
    @tracked title = 'This Month';
    @tracked start = format(startOfMonth(new Date()), 'yyyy-MM-dd');
    @tracked end = format(endOfMonth(new Date()), 'yyyy-MM-dd');
    @tracked metrics = {
        orders_count: 0,
        customers_count: 0,
        stores_count: 0,
        earnings_sum: 0,
    };
    @tracked datePickerButtons = [
        {
            content: 'Yesterday',
            className: 'quick-select-btn',
            onClick: (datepicker) => {
                const thisMonthRange = getDateRangeByLabel('Yesterday');
                if (thisMonthRange) {
                    datepicker.selectDate(thisMonthRange);
                }
            },
        },
        {
            content: 'Last 7 Days',
            className: 'quick-select-btn',
            onClick: (datepicker) => {
                const thisMonthRange = getDateRangeByLabel('Last 7 Days');
                if (thisMonthRange) {
                    datepicker.selectDate(thisMonthRange);
                }
            },
        },
        {
            content: 'Last Week',
            className: 'quick-select-btn',
            onClick: (datepicker) => {
                const thisMonthRange = getDateRangeByLabel('Last Week');
                if (thisMonthRange) {
                    datepicker.selectDate(thisMonthRange);
                }
            },
        },
        {
            content: 'This Month',
            className: 'quick-select-btn',
            onClick: (datepicker) => {
                const thisMonthRange = getDateRangeByLabel('This Month');
                if (thisMonthRange) {
                    datepicker.selectDate(thisMonthRange);
                }
            },
        },
        {
            content: 'Last Month',
            className: 'quick-select-btn',
            onClick: (datepicker) => {
                const thisMonthRange = getDateRangeByLabel('Last Month');
                if (thisMonthRange) {
                    datepicker.selectDate(thisMonthRange);
                }
            },
        },
        {
            content: 'This Quarter',
            className: 'quick-select-btn',
            onClick: (datepicker) => {
                const thisMonthRange = getDateRangeByLabel('This Quarter');
                if (thisMonthRange) {
                    datepicker.selectDate(thisMonthRange);
                }
            },
        },
        {
            content: 'Last Quarter',
            className: 'quick-select-btn',
            onClick: (datepicker) => {
                const thisMonthRange = getDateRangeByLabel('Last Quarter');
                if (thisMonthRange) {
                    datepicker.selectDate(thisMonthRange);
                }
            },
        },
        {
            content: 'This Year',
            className: 'quick-select-btn',
            onClick: (datepicker) => {
                const thisMonthRange = getDateRangeByLabel('This Year');
                if (thisMonthRange) {
                    datepicker.selectDate(thisMonthRange);
                }
            },
        },
        {
            content: 'Last Year',
            className: 'quick-select-btn',
            onClick: (datepicker) => {
                const thisMonthRange = getDateRangeByLabel('Last Year');
                if (thisMonthRange) {
                    datepicker.selectDate(thisMonthRange);
                }
            },
        },
    ];

    constructor(owner, { title = 'This Month' }) {
        super(...arguments);
        this.title = title;
        this.loadMetrics.perform(this.start, this.end);
        this.storefront.on('order.broadcasted', () => {
            this.loadMetrics.perform();
        });
        this.storefront.on('storefront.changed', () => {
            this.loadMetrics.perform();
        });
    }

    @task *loadMetrics(start, end) {
        const store = get(this.storefront, 'activeStore.id');

        try {
            const metrics = yield this.fetch.get('actions/metrics', { start, end, store }, { namespace: 'storefront/int/v1' });
            this.metrics = metrics;
            return metrics;
        } catch (err) {
            debug('Error loading storefront metrics:', err);
        }
    }

    @action selectDates({ formattedDate }) {
        const [start, end] = formattedDate;
        this.start = start;
        this.end = end;
        if (start && end) {
            this.loadMetrics.perform(start, end);
        }
    }
}
