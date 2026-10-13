import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { format, isValid, parseISO } from 'date-fns';

/**
 * Move a booking: the time it is booked for, the week of its service's free times (its own time
 * counts as free) and who is told. The accept button names the new time once one is picked.
 */
export default class ModalsRescheduleBookingComponent extends Component {
    @service intl;
    @service modalsManager;

    get options() {
        return this.args.options ?? {};
    }

    get draft() {
        return this.options.draft;
    }

    label(start, end) {
        const from = start ? parseISO(start) : null;
        if (!from || !isValid(from)) {
            return null;
        }
        const to = end ? parseISO(end) : null;

        return to && isValid(to)
            ? this.intl.t('storefront.order.panel.booking-when', { day: format(from, 'EEE d MMM'), start: format(from, 'HH:mm'), end: format(to, 'HH:mm') })
            : format(from, 'EEE d MMM, HH:mm');
    }

    get currentLabel() {
        return this.label(this.options.current, this.options.currentEnd);
    }

    get isMoved() {
        const { start } = this.draft;
        return Boolean(start) && (!this.options.current || parseISO(start).getTime() !== parseISO(this.options.current).getTime());
    }

    get newLabel() {
        return this.isMoved ? this.label(this.draft.start, this.draft.end) : null;
    }

    @action pick(start, end) {
        this.draft.start = start;
        this.draft.end = end;

        const moved = this.isMoved;
        this.modalsManager.setOption('acceptButtonDisabled', !moved);
        this.modalsManager.setOption(
            'acceptButtonText',
            moved ? this.intl.t('storefront.order.reschedule.move-to', { time: format(parseISO(start), 'EEE d MMM, HH:mm') }) : this.intl.t('storefront.order.reschedule.move')
        );
    }

    @action toggleCustomer(checked) {
        this.draft.notifyCustomer = checked;
    }

    @action toggleProvider(checked) {
        this.draft.notifyProvider = checked;
    }
}
