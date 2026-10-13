import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { guidFor } from '@ember/object/internals';
import { task } from 'ember-concurrency';
import { format, parseISO } from 'date-fns';

/**
 * The free slots of a bookable service on a day, from the service's hours and its bookings.
 *
 * @argument {Object} product the bookable product
 * @argument {String} value the chosen slot start (ISO)
 * @argument {Function} onSelect called with the slot start (ISO)
 */
export default class StorefrontOrderBookingSlotsComponent extends Component {
    @service fetch;
    @service notifications;
    @tracked date = format(new Date(), 'yyyy-MM-dd');
    @tracked response = null;
    uid = guidFor(this);

    constructor() {
        super(...arguments);
        if (this.args.value) {
            this.date = format(parseISO(this.args.value), 'yyyy-MM-dd');
        }
        this.load.perform();
    }

    get today() {
        return format(new Date(), 'yyyy-MM-dd');
    }

    get service() {
        return this.response?.service ?? {};
    }

    get slots() {
        const selected = this.args.value ? parseISO(this.args.value).getTime() : null;

        // Times are the store's local times, as the server labels them; past ones are left out.
        return (this.response?.slots ?? [])
            .filter((slot) => !slot.past)
            .map((slot) => ({
                ...slot,
                label: slot.time ?? format(parseISO(slot.start), 'HH:mm'),
                isSelected: selected === parseISO(slot.start).getTime(),
            }));
    }

    @task({ restartable: true }) *load() {
        const product = this.args.product;
        if (!product) {
            return;
        }

        try {
            this.response = yield this.fetch.get(`products/${product.id}/slots`, { date: this.date }, { namespace: 'storefront/int/v1' });
        } catch (error) {
            this.response = null;
            this.notifications.serverError(error);
        }
    }

    @action setDate(event) {
        this.date = event.target.value;
        this.load.perform();
    }

    @action pick(slot) {
        this.args.onSelect?.(slot.start);
    }
}
