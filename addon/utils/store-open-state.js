const DAYS = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

/**
 * Minutes since midnight for an `HH:mm` or `HH:mm:ss` string; null when unparseable.
 */
function toMinutes(value) {
    const match = String(value ?? '').match(/^(\d{1,2}):(\d{2})/);

    if (!match) {
        return null;
    }

    return parseInt(match[1], 10) * 60 + parseInt(match[2], 10);
}

/**
 * `HH:mm` for display from an `HH:mm[:ss]` string.
 */
export function formatClock(value) {
    const minutes = toMinutes(value);

    if (minutes === null) {
        return null;
    }

    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    return `${String(hours).padStart(2, '0')}:${String(rest).padStart(2, '0')}`;
}

/**
 * The weekday index and minutes since midnight of `now` in a timezone;
 * falls back to the browser's zone when the timezone is unknown.
 */
export function localClock(now = new Date(), timezone) {
    try {
        const parts = new Intl.DateTimeFormat('en-US', { timeZone: timezone || undefined, weekday: 'long', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(now);
        const read = (type) => parts.find((part) => part.type === type)?.value;
        const day = DAYS.indexOf(String(read('weekday')).toLowerCase());
        const hour = parseInt(read('hour'), 10) % 24;
        const minute = parseInt(read('minute'), 10);

        if (day >= 0 && !Number.isNaN(hour) && !Number.isNaN(minute)) {
            return { day, minutes: hour * 60 + minute };
        }
    } catch {
        // Unknown timezone: use the browser's.
    }

    return { day: now.getDay(), minutes: now.getHours() * 60 + now.getMinutes() };
}

function normalizeHours(hours) {
    const list = hours?.toArray?.() ?? Array.from(hours ?? []);

    return list
        .filter(Boolean)
        .map((hour) => {
            const dayName = String(hour.day_of_week ?? hour.day ?? '').toLowerCase();
            const day = DAYS.findIndex((name) => name.startsWith(dayName.slice(0, 3)));
            const start = toMinutes(hour.start);
            const end = toMinutes(hour.end);

            return day >= 0 && start !== null && end !== null ? { day, start, end, startLabel: formatClock(hour.start), endLabel: formatClock(hour.end) } : null;
        })
        .filter(Boolean);
}

/**
 * Whether a store is open right now from its hour rows (`day_of_week`, `start`, `end`),
 * evaluated in the store's timezone. Hours from several locations can be passed together:
 * the store counts as open when any of them is.
 *
 * @returns {{ hasHours: boolean, isOpen: boolean, closesAt: string|null, opensAt: string|null, opensDay: string|null }}
 */
export default function storeOpenState(hours, { timezone, now = new Date() } = {}) {
    const rows = normalizeHours(hours);

    if (!rows.length) {
        return { hasHours: false, isOpen: false, closesAt: null, opensAt: null, opensDay: null };
    }

    const { day, minutes } = localClock(now, timezone);
    const today = rows.filter((row) => row.day === day);
    const current = today.filter((row) => row.start <= minutes && minutes < row.end).sort((a, b) => b.end - a.end)[0];

    if (current) {
        return { hasHours: true, isOpen: true, closesAt: current.endLabel, opensAt: null, opensDay: null };
    }

    const laterToday = today.filter((row) => row.start > minutes).sort((a, b) => a.start - b.start)[0];

    if (laterToday) {
        return { hasHours: true, isOpen: false, closesAt: null, opensAt: laterToday.startLabel, opensDay: null };
    }

    for (let offset = 1; offset <= 7; offset++) {
        const nextDay = (day + offset) % 7;
        const next = rows.filter((row) => row.day === nextDay).sort((a, b) => a.start - b.start)[0];

        if (next) {
            return { hasHours: true, isOpen: false, closesAt: null, opensAt: next.startLabel, opensDay: DAYS[nextDay] };
        }
    }

    return { hasHours: true, isOpen: false, closesAt: null, opensAt: null, opensDay: null };
}
