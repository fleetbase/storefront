import Component from '@glimmer/component';

const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

/**
 * A seven-cell strip that shows which days a schedule covers, from a list of
 * hour records (`day_of_week`, `start`, `end`) the way catalog, store and
 * product hours are stored. Each cell carries the day's hours as its title.
 */
export default class StorefrontHoursStripComponent extends Component {
    get days() {
        const source = this.args.hours;
        const hours = (source?.toArray?.() ?? Array.from(source ?? [])).filter(Boolean);

        return DAYS.map((day) => {
            const matches = hours.filter((hour) =>
                String(hour.day_of_week ?? '')
                    .toLowerCase()
                    .startsWith(day.slice(0, 3))
            );
            const ranges = matches.map((hour) => [hour.start, hour.end].filter(Boolean).join(' to ')).filter(Boolean);

            return {
                key: day,
                label: day.charAt(0).toUpperCase() + day.slice(1, 3),
                open: matches.length > 0,
                title: ranges.length ? `${day.charAt(0).toUpperCase() + day.slice(1)}: ${ranges.join(', ')}` : `${day.charAt(0).toUpperCase() + day.slice(1)}: closed`,
            };
        });
    }

    get openDays() {
        return this.days.filter((day) => day.open).length;
    }
}
