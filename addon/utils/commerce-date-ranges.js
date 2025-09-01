import {
    startOfDay,
    endOfDay,
    startOfWeek,
    endOfWeek,
    startOfMonth,
    endOfMonth,
    startOfQuarter,
    endOfQuarter,
    startOfYear,
    endOfYear,
    subDays,
    subWeeks,
    subMonths,
    subQuarters,
    subYears,
    format,
} from 'date-fns';

/**
 * Predefined date range buttons for ecommerce analytics dashboard
 * Each button contains a label and a function that returns [startDate, endDate]
 */
export const predefinedDateRanges = [
    // Recent periods - most commonly used for daily monitoring
    {
        label: 'Today',
        getValue: () => {
            const today = new Date();
            return [startOfDay(today), endOfDay(today)];
        },
    },
    {
        label: 'Yesterday',
        getValue: () => {
            const yesterday = subDays(new Date(), 1);
            return [startOfDay(yesterday), endOfDay(yesterday)];
        },
    },
    {
        label: 'Last 7 Days',
        getValue: () => {
            const today = new Date();
            const sevenDaysAgo = subDays(today, 6); // 6 days ago + today = 7 days
            return [startOfDay(sevenDaysAgo), endOfDay(today)];
        },
    },
    {
        label: 'Last 14 Days',
        getValue: () => {
            const today = new Date();
            const fourteenDaysAgo = subDays(today, 13);
            return [startOfDay(fourteenDaysAgo), endOfDay(today)];
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

    // Weekly periods
    {
        label: 'This Week',
        getValue: () => {
            const today = new Date();
            return [startOfWeek(today, { weekStartsOn: 1 }), endOfWeek(today, { weekStartsOn: 1 })]; // Monday start
        },
    },
    {
        label: 'Last Week',
        getValue: () => {
            const lastWeek = subWeeks(new Date(), 1);
            return [startOfWeek(lastWeek, { weekStartsOn: 1 }), endOfWeek(lastWeek, { weekStartsOn: 1 })];
        },
    },

    // Monthly periods - crucial for monthly reporting
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
        label: 'Last 3 Months',
        getValue: () => {
            const today = new Date();
            const threeMonthsAgo = subMonths(today, 3);
            return [startOfMonth(threeMonthsAgo), endOfMonth(today)];
        },
    },
    {
        label: 'Last 6 Months',
        getValue: () => {
            const today = new Date();
            const sixMonthsAgo = subMonths(today, 6);
            return [startOfMonth(sixMonthsAgo), endOfMonth(today)];
        },
    },

    // Quarterly periods - important for business reporting
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
        label: 'Q1 2024',
        getValue: () => {
            const q1Start = new Date(2024, 0, 1); // January 1, 2024
            return [startOfQuarter(q1Start), endOfQuarter(q1Start)];
        },
    },
    {
        label: 'Q2 2024',
        getValue: () => {
            const q2Start = new Date(2024, 3, 1); // April 1, 2024
            return [startOfQuarter(q2Start), endOfQuarter(q2Start)];
        },
    },
    {
        label: 'Q3 2024',
        getValue: () => {
            const q3Start = new Date(2024, 6, 1); // July 1, 2024
            return [startOfQuarter(q3Start), endOfQuarter(q3Start)];
        },
    },
    {
        label: 'Q4 2024',
        getValue: () => {
            const q4Start = new Date(2024, 9, 1); // October 1, 2024
            return [startOfQuarter(q4Start), endOfQuarter(q4Start)];
        },
    },

    // Yearly periods - essential for annual analysis
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
    {
        label: '2024',
        getValue: () => {
            const year2024 = new Date(2024, 0, 1);
            return [startOfYear(year2024), endOfYear(year2024)];
        },
    },
    {
        label: '2023',
        getValue: () => {
            const year2023 = new Date(2023, 0, 1);
            return [startOfYear(year2023), endOfYear(year2023)];
        },
    },

    // Special ecommerce periods
    {
        label: 'Black Friday Week',
        getValue: () => {
            // Assuming Black Friday 2024 is November 29th
            const blackFriday = new Date(2024, 10, 29); // November 29, 2024
            const weekStart = subDays(blackFriday, 3); // Tuesday before
            const weekEnd = subDays(blackFriday, -3); // Monday after
            return [startOfDay(weekStart), endOfDay(weekEnd)];
        },
    },
    {
        label: 'Holiday Season 2024',
        getValue: () => {
            // November 1st to December 31st
            const seasonStart = new Date(2024, 10, 1); // November 1, 2024
            const seasonEnd = new Date(2024, 11, 31); // December 31, 2024
            return [startOfDay(seasonStart), endOfDay(seasonEnd)];
        },
    },
    {
        label: 'Back to School 2024',
        getValue: () => {
            // August 1st to September 15th
            const seasonStart = new Date(2024, 7, 1); // August 1, 2024
            const seasonEnd = new Date(2024, 8, 15); // September 15, 2024
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
