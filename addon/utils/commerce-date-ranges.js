import { startOfDay, endOfDay, startOfMonth, endOfMonth, startOfQuarter, endOfQuarter, startOfYear, endOfYear, addDays, subDays, subMonths, subQuarters, subYears, format } from 'date-fns';

const currentYear = new Date().getFullYear();

function getCurrentYearQuarterRange(quarter) {
    const start = new Date(currentYear, (quarter - 1) * 3, 1);

    return [startOfQuarter(start), endOfQuarter(start)];
}

function getBlackFriday(year = currentYear) {
    let fridayCount = 0;
    let date = new Date(year, 10, 1);

    while (date.getMonth() === 10) {
        if (date.getDay() === 5) {
            fridayCount++;

            if (fridayCount === 4) {
                return date;
            }
        }

        date = addDays(date, 1);
    }
}

/**
 * Predefined date range buttons for ecommerce analytics dashboard
 * Each button contains a label and a function that returns [startDate, endDate]
 */
export const predefinedDateRanges = [
    // Rolling periods used most often for commerce dashboards.
    {
        label: 'Last 7 Days',
        getValue: () => {
            const today = new Date();
            const sevenDaysAgo = subDays(today, 6); // 6 days ago + today = 7 days
            return [startOfDay(sevenDaysAgo), endOfDay(today)];
        },
    },
    {
        label: 'Last 30 Days',
        getValue: () => {
            const today = new Date();
            const thirtyDaysAgo = subDays(today, 29);
            return [startOfDay(thirtyDaysAgo), endOfDay(today)];
        },
    },
    {
        label: 'Last 90 Days',
        getValue: () => {
            const today = new Date();
            const ninetyDaysAgo = subDays(today, 89);
            return [startOfDay(ninetyDaysAgo), endOfDay(today)];
        },
    },

    // Month and quarter reporting.
    {
        label: 'This Month',
        getValue: () => {
            const today = new Date();
            return [startOfMonth(today), endOfMonth(today)];
        },
    },
    {
        label: 'Last Month',
        getValue: () => {
            const lastMonth = subMonths(new Date(), 1);
            return [startOfMonth(lastMonth), endOfMonth(lastMonth)];
        },
    },
    {
        label: 'This Quarter',
        getValue: () => {
            const today = new Date();
            return [startOfQuarter(today), endOfQuarter(today)];
        },
    },
    {
        label: 'Last Quarter',
        getValue: () => {
            const lastQuarter = subQuarters(new Date(), 1);
            return [startOfQuarter(lastQuarter), endOfQuarter(lastQuarter)];
        },
    },
    {
        label: `Q1 ${currentYear}`,
        getValue: () => getCurrentYearQuarterRange(1),
    },
    {
        label: `Q2 ${currentYear}`,
        getValue: () => getCurrentYearQuarterRange(2),
    },
    {
        label: `Q3 ${currentYear}`,
        getValue: () => getCurrentYearQuarterRange(3),
    },
    {
        label: `Q4 ${currentYear}`,
        getValue: () => getCurrentYearQuarterRange(4),
    },

    // Annual reporting.
    {
        label: 'This Year',
        getValue: () => {
            const today = new Date();
            return [startOfYear(today), endOfYear(today)];
        },
    },
    {
        label: 'Last Year',
        getValue: () => {
            const lastYear = subYears(new Date(), 1);
            return [startOfYear(lastYear), endOfYear(lastYear)];
        },
    },

    // Current-year ecommerce seasons.
    {
        label: 'Black Friday Week',
        getValue: () => {
            const blackFriday = getBlackFriday();
            const weekStart = subDays(blackFriday, 3);
            const weekEnd = addDays(blackFriday, 3);
            return [startOfDay(weekStart), endOfDay(weekEnd)];
        },
    },
    {
        label: 'Holiday Season',
        getValue: () => {
            const seasonStart = new Date(currentYear, 10, 1);
            const seasonEnd = new Date(currentYear, 11, 31);
            return [startOfDay(seasonStart), endOfDay(seasonEnd)];
        },
    },
];

/**
 * Convert predefined date ranges to AirDatepicker buttons format
 * @param {Function} onRangeSelect - Callback function when a range is selected
 * @returns {Array} Array of button objects for AirDatepicker
 */
export function createDateRangeButtons(onRangeSelect) {
    return predefinedDateRanges.map((range) => ({
        content: range.label,
        className: 'custom-date-range-btn',
        onClick: (datepicker) => {
            const [startDate, endDate] = range.getValue();
            datepicker.selectDate([startDate, endDate]);
            datepicker.hide();

            // Call the callback if provided
            if (typeof onRangeSelect === 'function') {
                onRangeSelect({
                    label: range.label,
                    startDate,
                    endDate,
                    formattedRange: `${format(startDate, 'MMM dd, yyyy')} - ${format(endDate, 'MMM dd, yyyy')}`,
                });
            }
        },
    }));
}

/**
 * Get a specific date range by label
 * @param {string} label - The label of the date range
 * @returns {Array|null} [startDate, endDate] or null if not found
 */
export function getDateRangeByLabel(label) {
    const range = predefinedDateRanges.find((r) => r.label === label);
    return range ? range.getValue() : null;
}

/**
 * Format date range for display
 * @param {Date} startDate
 * @param {Date} endDate
 * @returns {string} Formatted date range string
 */
export function formatDateRange(startDate, endDate) {
    return `${format(startDate, 'MMM dd, yyyy')} - ${format(endDate, 'MMM dd, yyyy')}`;
}
