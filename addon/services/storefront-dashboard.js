import Service from '@ember/service';
import Evented from '@ember/object/evented';
import { action } from '@ember/object';
import { tracked } from '@glimmer/tracking';
import { format, parseISO } from 'date-fns';
import { createDateRangeButtons, formatDateRange, getDateRangeByLabel } from '../utils/commerce-date-ranges';

const DEFAULT_RANGE_LABEL = 'Last 30 Days';
const QUERY_DATE_FORMAT = 'yyyy-MM-dd';

export default class StorefrontDashboardService extends Service.extend(Evented) {
    @tracked start;
    @tracked end;
    @tracked label = DEFAULT_RANGE_LABEL;
    @tracked formattedRange;
    @tracked version = 0;
    @tracked datePickerValue = '';
    @tracked datePickerButtons = [];

    isSelectingPreset = false;

    constructor() {
        super(...arguments);
        const [startDate, endDate] = getDateRangeByLabel(DEFAULT_RANGE_LABEL);
        this.setRange(startDate, endDate, DEFAULT_RANGE_LABEL, { silent: true });
        this.datePickerButtons = this.createDatePickerButtons();
    }

    get queryParams() {
        return {
            start: this.start,
            end: this.end,
        };
    }

    createDatePickerButtons() {
        return createDateRangeButtons((range) => {
            this.setRange(range.startDate, range.endDate, range.label);
        }).map((button) => ({
            ...button,
            onClick: (datepicker) => {
                this.isSelectingPreset = true;
                try {
                    button.onClick(datepicker);
                } finally {
                    this.isSelectingPreset = false;
                }
            },
        }));
    }

    withStore(store) {
        return {
            store,
            ...this.queryParams,
        };
    }

    @action selectDates({ date, formattedDate }) {
        if (this.isSelectingPreset) {
            return;
        }

        if (!formattedDate) {
            return;
        }

        const [start, end] = Array.isArray(formattedDate) ? formattedDate : [formattedDate, formattedDate];

        if (start && end) {
            this.setRange(start, end, 'Custom');
        } else if (Array.isArray(date) && date.length === 2) {
            this.setRange(date[0], date[1], 'Custom');
        }
    }

    @action selectRange(range) {
        if (!range) {
            return;
        }

        if (typeof range.getValue === 'function') {
            const [startDate, endDate] = range.getValue();
            this.setRange(startDate, endDate, range.label);
            return;
        }

        this.setRange(range.startDate ?? range.start, range.endDate ?? range.end, range.label);
    }

    setRange(start, end, label = 'Custom', options = {}) {
        const startDate = this.normalizeDate(start);
        const endDate = this.normalizeDate(end);

        if (!startDate || !endDate) {
            return;
        }

        const nextStart = format(startDate, QUERY_DATE_FORMAT);
        const nextEnd = format(endDate, QUERY_DATE_FORMAT);

        if (this.start === nextStart && this.end === nextEnd && this.label === label) {
            return;
        }

        this.start = nextStart;
        this.end = nextEnd;
        this.datePickerValue = `${nextStart},${nextEnd}`;
        this.label = label;
        this.formattedRange = formatDateRange(startDate, endDate);

        if (!options.silent) {
            this.version++;
            this.trigger('periodChanged', this.queryParams);
        }
    }

    normalizeDate(value) {
        if (!value) {
            return null;
        }

        if (value instanceof Date) {
            return value;
        }

        if (typeof value === 'string') {
            return parseISO(value);
        }

        return null;
    }
}
