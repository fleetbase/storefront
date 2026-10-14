import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { addDays, format, isBefore, parseISO, startOfDay } from 'date-fns';
import { formatInStore, storeNow, toStoreWall } from '../../../utils/store-time';

const DAYS = 7;

/**
 * A week of a bookable service's days (places left, full or closed) and the start times of the
 * chosen day, from the service's hours and its bookings (the existing slots endpoint, one day at
 * a time). Picking a time books that slot.
 *
 * @argument {Object} product the bookable product
 * @argument {String} value the chosen slot start (ISO)
 * @argument {String} current the start of the booking being moved (ISO): its own place counts as free
 * @argument {Function} onSelect called with the slot start (ISO) and end
 * @argument {Number} offset the store's UTC offset in minutes (else the active store's): days and
 *   times are the store's, whatever the browser's timezone
 */
export default class StorefrontOrderBookingSlotsComponent extends Component {
    @service fetch;
    @service intl;
    @service notifications;
    @service storefrontOrderActions;
    @tracked from;
    @tracked date;
    @tracked responses = {};

    constructor() {
        super(...arguments);
        // Open on the chosen day when there is one (and it has not passed), else today; both on
        // the store's calendar (the days are wall-clock dates, as the slots endpoint reads them).
        const today = this.today;
        const chosen = this.args.value ? startOfDay(toStoreWall(this.args.value, this.offset)) : null;
        this.from = chosen && !isBefore(chosen, today) ? chosen : today;
        this.date = format(chosen ?? today, 'yyyy-MM-dd');
        this.load.perform();
    }

    get offset() {
        return this.args.offset ?? this.storefrontOrderActions.storeOffsetFor(this.args.order);
    }

    /** Today on the store's calendar. */
    get today() {
        return startOfDay(storeNow(this.offset));
    }

    get service() {
        return Object.values(this.responses).find((response) => response?.service)?.service ?? {};
    }

    /** "3 h job" or "45 min job". */
    get lengthLabel() {
        const minutes = this.service.duration_minutes;
        if (!minutes) {
            return null;
        }

        return minutes % 60 === 0 ? this.intl.t('storefront.orders.booking.hours-job', { count: minutes / 60 }) : this.intl.t('storefront.orders.booking.minutes-job', { count: minutes });
    }

    get canGoBack() {
        return isBefore(this.today, this.from);
    }

    /** A day's slots, with the place of the booking being moved given back. */
    slotsFor(key) {
        const current = this.args.current ? parseISO(this.args.current).getTime() : null;

        return (this.responses[key]?.slots ?? []).map((slot) =>
            current !== null && parseISO(slot.start).getTime() === current ? { ...slot, available: true, remaining: (slot.remaining ?? 0) + 1 } : slot
        );
    }

    get days() {
        return Array.from({ length: DAYS }, (_, index) => {
            const day = addDays(this.from, index);
            const key = format(day, 'yyyy-MM-dd');
            const response = this.responses[key];
            const slots = this.slotsFor(key).filter((slot) => !slot.past);
            const free = slots.filter((slot) => slot.available);
            const left = free.reduce((sum, slot) => sum + (slot.remaining ?? 1), 0);
            let state = 'loading';
            if (response === null) {
                state = 'error';
            } else if (response) {
                state = !(response.slots ?? []).length ? 'closed' : free.length ? 'open' : 'full';
            }

            return {
                key,
                weekday: format(day, 'EEE'),
                number: format(day, 'd'),
                state,
                note:
                    {
                        open: this.intl.t('storefront.orders.booking.left', { count: left }),
                        full: this.intl.t('storefront.orders.booking.full'),
                        closed: this.intl.t('storefront.orders.booking.closed'),
                    }[state] ?? '',
                isSelected: key === this.date,
                isDisabled: state !== 'open',
            };
        });
    }

    get monthLabel() {
        return format(this.from, 'MMMM');
    }

    get slots() {
        const selected = this.args.value ? parseISO(this.args.value).getTime() : null;

        // Times are the store's local times, as the server labels them; past ones are left out.
        return this.slotsFor(this.date)
            .filter((slot) => !slot.past)
            .map((slot) => ({
                ...slot,
                label: slot.time ?? formatInStore(slot.start, 'HH:mm', this.offset),
                isSelected: selected === parseISO(slot.start).getTime(),
            }));
    }

    get selectedSlot() {
        return this.slots.find((slot) => slot.isSelected) ?? null;
    }

    /** "10:00 to 13:00" for the chosen time. */
    get rangeLabel() {
        const slot = this.selectedSlot;
        return slot ? this.intl.t('storefront.orders.booking.range', { start: slot.label, end: formatInStore(slot.end, 'HH:mm', this.offset) }) : null;
    }

    @task({ restartable: true }) *load() {
        const product = this.args.product;
        if (!product) {
            return;
        }

        const keys = Array.from({ length: DAYS }, (_, index) => format(addDays(this.from, index), 'yyyy-MM-dd')).filter((key) => !(key in this.responses));
        const results = yield Promise.all(
            keys.map((date) =>
                this.fetch
                    .get(`products/${product.id}/slots`, { date }, { namespace: 'storefront/int/v1' })
                    .then((response) => [date, response])
                    .catch(() => [date, null])
            )
        );
        this.responses = { ...this.responses, ...Object.fromEntries(results) };

        // Open on the chosen day, else the first day with a free time.
        if (!this.days.find((day) => day.isSelected && day.state === 'open') && !this.args.value) {
            const first = this.days.find((day) => day.state === 'open');
            if (first) {
                this.date = first.key;
            }
        }
    }

    @action pickDay(day) {
        if (day.isDisabled) {
            return;
        }
        this.date = day.key;
    }

    @action shiftWeek(direction) {
        const next = addDays(this.from, direction * DAYS);
        this.from = isBefore(next, this.today) ? this.today : next;
        this.load.perform();
    }

    @action pick(slot) {
        this.args.onSelect?.(slot.start, slot.end);
    }
}
