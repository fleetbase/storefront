import { get } from '@ember/object';
import { format as formatDate, isValid, parseISO } from 'date-fns';

/**
 * Times as the store reads them.
 *
 * Store hours, booked times, due times and the like are the store's wall-clock times. The
 * console reads them with the store's current UTC offset from the API (`utc_offset`, minutes
 * east of UTC) rather than the browser's timezone data, which some browsers lack (they format
 * every zone as UTC). Without an offset it asks the browser for the store's timezone, and
 * without that it uses the browser's own clock.
 */

/** The store's offset from UTC in minutes now (east positive). */
export function storeOffset(storefront) {
    const offset = Number(storefront ? get(storefront, 'utc_offset') : NaN);
    if (Number.isFinite(offset)) {
        return offset;
    }

    const timezone = storefront ? get(storefront, 'timezone') : null;
    if (timezone) {
        try {
            const now = new Date();
            const parts = new Intl.DateTimeFormat('en-US', {
                timeZone: timezone,
                hourCycle: 'h23',
                year: 'numeric',
                month: 'numeric',
                day: 'numeric',
                hour: 'numeric',
                minute: 'numeric',
            }).formatToParts(now);
            const read = (type) => parseInt(parts.find((part) => part.type === type)?.value, 10);
            const wall = Date.UTC(read('year'), read('month') - 1, read('day'), read('hour') % 24, read('minute'));
            const real = Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate(), now.getUTCHours(), now.getUTCMinutes());

            return Math.round((wall - real) / 60000);
        } catch {
            // An unknown zone: the browser's clock below.
        }
    }

    return -new Date().getTimezoneOffset();
}

function toDate(value) {
    if (value instanceof Date) {
        return isValid(value) ? value : null;
    }
    if (typeof value === 'string') {
        const date = parseISO(value);
        return isValid(date) ? date : null;
    }
    if (typeof value === 'number') {
        return new Date(value);
    }

    return null;
}

/**
 * A Date whose local fields (hours, weekday, date) read as the store's wall clock at `value`,
 * for formatting and day arithmetic only; compare real instants, not these.
 */
export function toStoreWall(value, offset) {
    const date = toDate(value);
    if (!date) {
        return null;
    }

    const shifted = new Date(date.getTime() + offset * 60000);

    // Rebuilt from the UTC fields so a browser DST change between the two instants can't shift it.
    return new Date(
        shifted.getUTCFullYear(),
        shifted.getUTCMonth(),
        shifted.getUTCDate(),
        shifted.getUTCHours(),
        shifted.getUTCMinutes(),
        shifted.getUTCSeconds(),
        shifted.getUTCMilliseconds()
    );
}

/** The store's wall clock now. */
export function storeNow(offset) {
    return toStoreWall(new Date(), offset);
}

/** `value` formatted (date-fns pattern) in the store's time. */
export function formatInStore(value, pattern, offset) {
    const wall = toStoreWall(value, offset);

    return wall ? formatDate(wall, pattern) : '';
}

/** The weekday (0 = Sunday) and minutes since midnight of the store's clock now. */
export function storeClock(offset, now = new Date()) {
    const wall = toStoreWall(now, offset);

    return { day: wall.getDay(), minutes: wall.getHours() * 60 + wall.getMinutes() };
}

/** Whether two instants fall on the same day of the store's calendar. */
export function isSameStoreDay(a, b, offset) {
    const left = toStoreWall(a, offset);
    const right = toStoreWall(b, offset);

    return Boolean(left && right) && left.toDateString() === right.toDateString();
}
